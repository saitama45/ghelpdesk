<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\InventoryTransaction;
use App\Models\StockIn;
use App\Models\Store;
use App\Models\Vendor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Milon\Barcode\DNS1D;
use Milon\Barcode\DNS2D;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class StockInController extends Controller
{
    private const SUPPLIER_LOCATION = 'SUPPLIER';

    private const RESERVED_TRANSFER_STATUSES = ['For Posting', 'Posted'];

    public function index(Request $request)
    {
        $search   = trim((string) $request->input('search', ''));
        $perPage  = max(1, min(200, (int) $request->input('per_page', 10)));
        $statuses = array_values(array_filter((array) $request->input('statuses', [])));

        $query = StockIn::with(['asset', 'creator:id,name,email', 'updater:id,name,email'])
            ->select(
                'asset_id',
                'receive_date',
                'dr_no',
                'dr_date',
                'vendor',
                'origin_location',
                'received_by',
                'memo_remarks',
                'status',
                'posted_by',
                'posted_date',
                DB::raw("CASE WHEN COUNT(DISTINCT COALESCE(destination_location, '')) > 1 THEN 'Multiple' ELSE MAX(destination_location) END as destination_location"),
                DB::raw('SUM(quantity) as quantity'),
                DB::raw('COUNT(*) as record_count'),
                DB::raw('MAX(id) as id'),
                DB::raw('MAX(created_at) as created_at'),
                DB::raw('MAX(updated_at) as updated_at')
            )
            ->groupBy(
                'asset_id',
                'receive_date',
                'dr_no',
                'dr_date',
                'vendor',
                'origin_location',
                'received_by',
                'memo_remarks',
                'status',
                'posted_by',
                'posted_date'
            );

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('dr_no', 'like', "%{$search}%")
                  ->orWhere('vendor', 'like', "%{$search}%")
                  ->orWhere('received_by', 'like', "%{$search}%");
            });
        }

        if (!empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        $stockIns = $query->latest('receive_date')->paginate($perPage);

        return Inertia::render('StockIn/Index', [
            'stockIns' => $stockIns,
            'assets' => Asset::all(),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'vendors' => Vendor::active()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function show(StockIn $stockIn)
    {
        return response()->json(
            $this->groupedStockInRows($stockIn)->get()
        );
    }

    public function availableStock(Request $request)
    {
        $validated = $request->validate([
            'asset_id' => 'required|exists:assets,id',
            'origin_location' => 'required|string|max:255',
        ]);

        $asset = Asset::select('id', 'item_code', 'brand', 'model', 'description', 'type', 'cost')
            ->findOrFail($validated['asset_id']);
        $originLocation = $this->normalizeStoreCode($validated['origin_location']);

        $availableUnits = collect();
        if ($originLocation && ! $this->isSupplierLocation($originLocation) && $this->isFixedAsset($asset)) {
            $availableUnits = $this->availableFixedSourceRows($asset, $originLocation)
                ->get([
                    'id',
                    'receive_date',
                    'dr_no',
                    'dr_date',
                    'vendor',
                    'origin_location',
                    'destination_location',
                    'received_by',
                    'serial_no',
                    'barcode',
                    'qrcode',
                    'warranty_months',
                    'eol_months',
                    'warranty_date',
                    'eol_date',
                    'cost',
                    'price',
                    'created_at',
                ])
                ->map(fn (StockIn $row) => [
                    'id' => $row->id,
                    'source_stock_in_id' => $row->id,
                    'receive_date' => $row->receive_date,
                    'dr_no' => $row->dr_no,
                    'dr_date' => $row->dr_date,
                    'vendor' => $row->vendor,
                    'origin_location' => $row->origin_location,
                    'destination_location' => $row->destination_location,
                    'received_by' => $row->received_by,
                    'serial_no' => $row->serial_no,
                    'barcode' => $row->barcode,
                    'qrcode' => $row->qrcode,
                    'warranty_months' => $row->warranty_months,
                    'eol_months' => $row->eol_months,
                    'warranty_date' => $row->warranty_date,
                    'eol_date' => $row->eol_date,
                    'cost' => $row->cost,
                    'price' => $row->price,
                    'created_at' => $row->created_at,
                ])
                ->values();
        }

        return response()->json([
            'asset' => $asset,
            'origin_location' => $originLocation,
            'soh' => $originLocation && ! $this->isSupplierLocation($originLocation)
                ? $this->inventorySoh($asset->id, $originLocation)
                : 0,
            'available_units' => $availableUnits,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'receive_date' => 'required|date',
            'dr_no' => 'nullable|string|max:255',
            'dr_date' => 'nullable|date',
            'vendor' => 'nullable|string|max:255',
            'origin_location' => 'nullable|string|max:255',
            'received_by' => 'nullable|string|max:255',
            'memo_remarks' => 'nullable|string|max:2000',
            'posted_by' => 'nullable|string|max:255',
            'status' => 'required|in:For Posting,Posted',
            'asset_id' => 'required|exists:assets,id',
            'quantity' => 'required|integer|min:1',
            'entries' => 'required|array|min:1',
            'entries.*.source_stock_in_id' => 'nullable|integer|exists:stock_ins,id',
            'entries.*.serial_no' => 'nullable|string',
            'entries.*.barcode' => 'required|string',
            'entries.*.qrcode' => 'required|string',
            'entries.*.warranty_months' => 'required|integer|min:0',
            'entries.*.eol_months' => 'required|integer|min:0',
            'entries.*.cost' => 'required|numeric|min:0',
            'entries.*.price' => 'required|numeric|min:0',
            'entries.*.destination_location' => 'nullable|string|max:255',
        ], $this->stockInCodeValidationMessages());

        $asset = Asset::findOrFail($validated['asset_id']);
        $originLocation = $this->normalizeStoreCode($validated['origin_location'] ?? null);
        $validated['entries'] = $this->prepareEntriesForSave($asset, $originLocation, $validated['entries']);

        if ((int) $validated['quantity'] !== count($validated['entries'])) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must match the number of stock detail rows.',
            ]);
        }

        foreach ($validated['entries'] as $entry) {
            StockIn::create([
                'receive_date' => $validated['receive_date'],
                'dr_no' => $validated['dr_no'] ?? null,
                'dr_date' => $validated['dr_date'] ?? null,
                'vendor' => $validated['vendor'] ?? null,
                'origin_location' => $originLocation,
                'received_by' => $validated['received_by'] ?? null,
                'memo_remarks' => $validated['memo_remarks'] ?? null,
                'posted_by' => $validated['posted_by'] ?? null,
                'status' => $validated['status'],
                'asset_id' => $validated['asset_id'],
                'quantity' => 1,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
                ...$this->normalizeStockEntry(Arr::only($entry, [
                    'source_stock_in_id',
                    'serial_no',
                    'barcode',
                    'qrcode',
                    'warranty_months',
                    'eol_months',
                    'cost',
                    'price',
                    'destination_location',
                ])),
            ]);
        }

        return redirect()->back()->with('success', 'Stock In recorded successfully');
    }

    public function update(Request $request, StockIn $stockIn)
    {
        $validated = $request->validate([
            'receive_date' => 'required|date',
            'dr_no' => 'nullable|string|max:255',
            'dr_date' => 'nullable|date',
            'vendor' => 'nullable|string|max:255',
            'origin_location' => 'nullable|string|max:255',
            'received_by' => 'nullable|string|max:255',
            'memo_remarks' => 'nullable|string|max:2000',
            'posted_by' => 'nullable|string|max:255',
            'status' => 'required|in:For Posting,Posted',
            'asset_id' => 'required|exists:assets,id',
            'quantity' => 'required|integer|min:1',
            'header_mode' => 'nullable|boolean',
            'entries' => 'nullable|array|min:1',
            'entries.*.source_stock_in_id' => 'nullable|integer|exists:stock_ins,id',
            'entries.*.serial_no' => 'nullable|string',
            'entries.*.barcode' => 'required|string',
            'entries.*.qrcode' => 'required|string',
            'entries.*.warranty_months' => 'required_with:entries|integer|min:0',
            'entries.*.eol_months' => 'required_with:entries|integer|min:0',
            'entries.*.cost' => 'required_with:entries|numeric|min:0',
            'entries.*.price' => 'required_with:entries|numeric|min:0',
            'entries.*.destination_location' => 'nullable|string|max:255',
            'source_stock_in_id' => 'nullable|integer|exists:stock_ins,id',
            'serial_no' => 'nullable|string',
            'barcode' => 'required_without:entries|string',
            'qrcode' => 'required_without:entries|string',
            'warranty_months' => 'required_without:entries|integer|min:0',
            'eol_months' => 'required_without:entries|integer|min:0',
            'cost' => 'required_without:entries|numeric|min:0',
            'price' => 'required_without:entries|numeric|min:0',
            'destination_location' => 'nullable|string|max:255',
        ], $this->stockInCodeValidationMessages());

        $asset = Asset::findOrFail($validated['asset_id']);
        $originLocation = $this->normalizeStoreCode($validated['origin_location'] ?? null);

        if (! empty($validated['header_mode'])) {
            $relatedRows = $this->groupedStockInRows($stockIn)->get();
            $entryDetails = $validated['entries'] ?? [[
                'source_stock_in_id' => $validated['source_stock_in_id'] ?? null,
                'serial_no' => $validated['serial_no'] ?? null,
                'barcode' => $validated['barcode'] ?? null,
                'qrcode' => $validated['qrcode'] ?? null,
                'warranty_months' => $validated['warranty_months'],
                'eol_months' => $validated['eol_months'],
                'cost' => $validated['cost'],
                'price' => $validated['price'],
                'destination_location' => $validated['destination_location'] ?? null,
            ]];
            $validated['entries'] = $this->prepareEntriesForSave(
                $asset,
                $originLocation,
                $entryDetails,
                $relatedRows->pluck('id')->all()
            );

            if ((int) $validated['quantity'] !== count($validated['entries'])) {
                throw ValidationException::withMessages([
                    'quantity' => 'Quantity must match the number of stock detail rows.',
                ]);
            }

            $this->syncGroupedEntries($stockIn, $validated, $relatedRows);

            return redirect()->back()->with('success', 'Stock In updated successfully');
        }

        $entryPayload = $this->prepareEntriesForSave(
            $asset,
            $originLocation,
            [Arr::only($validated, [
                'source_stock_in_id',
                'serial_no',
                'barcode',
                'qrcode',
                'warranty_months',
                'eol_months',
                'cost',
                'price',
                'destination_location',
            ])],
            [$stockIn->id]
        )[0];

        $stockIn->update([
            ...$this->normalizeStockEntry([
                ...Arr::except($validated, [
                    'header_mode',
                    'entries',
                    'serial_no',
                    'barcode',
                    'qrcode',
                    'warranty_months',
                    'eol_months',
                    'cost',
                    'price',
                    'destination_location',
                    'source_stock_in_id',
                ]),
                'origin_location' => $originLocation,
                ...$entryPayload,
            ]),
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->back()->with('success', 'Stock In updated successfully');
    }

    public function destroy(Request $request, StockIn $stockIn)
    {
        if ($request->boolean('delete_group')) {
            $this->groupedStockInRows($stockIn)->delete();
        } else {
            $stockIn->delete();
        }

        return redirect()->back()->with('success', 'Stock In deleted successfully');
    }

    public function post(Request $request, StockIn $stockIn)
    {
        abort_unless($request->user()->can('stock_ins.post'), 403);

        $affectedRows = StockIn::where('asset_id', $stockIn->asset_id)
            ->whereDate('receive_date', $stockIn->receive_date);

        // Replicate grouping logic to identify all rows in this header
        $fields = ['dr_no', 'dr_date', 'vendor', 'origin_location', 'received_by', 'status', 'posted_by', 'posted_date'];
        foreach ($fields as $field) {
            if ($stockIn->$field !== null) {
                $affectedRows->where($field, $stockIn->$field);
            } else {
                $affectedRows->whereNull($field);
            }
        }

        $itemsToPost = $affectedRows->get();
        $this->validatePostableTransfers($itemsToPost);
        $ledgerRowsByItemId = $itemsToPost->mapWithKeys(fn (StockIn $item) => [
            $item->id => $this->stockInLedgerRows($item, $request->user()->id),
        ]);
        $now = now();

        DB::transaction(function () use ($itemsToPost, $request, $now, $ledgerRowsByItemId) {
            foreach ($itemsToPost as $item) {
                $item->update([
                    'status' => 'Posted',
                    'posted_by' => $request->user()->name,
                    'posted_date' => $now,
                ]);

                foreach ($ledgerRowsByItemId[$item->id] as $ledgerRow) {
                    InventoryTransaction::create($ledgerRow);
                }
            }
        });

        return redirect()->back()->with('success', 'Stock In status updated to Posted and ledger entries recorded');
    }

    public function import(Request $request)
    {
        abort_unless($request->user()?->can('stock_ins.create'), 403);

        $request->validate(['file' => 'required|file|mimes:xlsx,csv,txt|max:5120']);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $header = array_map(fn ($value) => trim((string) $value), array_shift($rows) ?? []);
        $expectedHeaders = $this->stockInImportHeaders();
        $missingHeaders = array_values(array_diff($expectedHeaders, $header));

        if (! empty($missingHeaders)) {
            return response()->json([
                'imported' => 0,
                'errors' => ['Template is missing required columns: '.implode(', ', $missingHeaders)],
            ]);
        }

        $headerIndexes = array_flip($header);
        $assetsByItemCode = Asset::query()
            ->get(['id', 'item_code', 'cost'])
            ->keyBy(fn (Asset $asset) => mb_strtolower(trim((string) $asset->item_code)));

        $imported = 0;
        $errors = [];
        $rowNum = 1;

        foreach ($rows as $line) {
            $rowNum++;

            if (empty(array_filter($line, fn ($value) => $value !== null && trim((string) $value) !== ''))) {
                continue;
            }

            $data = [];
            foreach ($expectedHeaders as $expectedHeader) {
                $data[$expectedHeader] = $this->normalizeImportValue($line[$headerIndexes[$expectedHeader]] ?? null);
            }

            $itemCode = $data['item_code'] ?? '';
            $asset = $itemCode !== ''
                ? $assetsByItemCode->get(mb_strtolower($itemCode))
                : null;

            if (! $asset) {
                $errors[] = "Row {$rowNum}: item_code '{$itemCode}' was not found.";

                continue;
            }

            $payload = [
                'receive_date' => $this->normalizeImportDate($data['receive_date']),
                'dr_no' => $data['dr_no'] ?: null,
                'dr_date' => $this->normalizeImportDate($data['dr_date']),
                'vendor' => $data['vendor'] ?: null,
                'origin_location' => $data['origin_location'] ?: null,
                'received_by' => $data['received_by'] ?: null,
                'asset_id' => $asset->id,
                'serial_no' => $data['serial_no'] ?: null,
                'barcode' => $data['barcode'] ?: null,
                'qrcode' => $data['qrcode'] ?: null,
                'warranty_months' => $this->normalizeImportNumber($data['warranty_months'], 12),
                'eol_months' => $this->normalizeImportNumber($data['eol_months'], 60),
                'cost' => $this->normalizeImportNumber($data['cost'], $asset->cost ?? 0),
                'price' => $this->normalizeImportNumber($data['price'], 0),
                'destination_location' => $data['destination_location'] ?: null,
            ];

            $validator = Validator::make($payload, [
                'receive_date' => 'required|date',
                'dr_no' => 'nullable|string|max:255',
                'dr_date' => 'nullable|date',
                'vendor' => 'nullable|string|max:255',
                'origin_location' => 'nullable|string|max:255',
                'received_by' => 'nullable|string|max:255',
                'asset_id' => 'required|exists:assets,id',
                'serial_no' => 'nullable|string',
                'barcode' => 'nullable|string',
                'qrcode' => 'nullable|string',
                'warranty_months' => 'required|integer|min:0',
                'eol_months' => 'required|integer|min:0',
                'cost' => 'required|numeric|min:0',
                'price' => 'required|numeric|min:0',
                'destination_location' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNum}: ".implode(', ', $validator->errors()->all());

                continue;
            }

            $validated = $validator->validated();

            StockIn::create([
                'receive_date' => $validated['receive_date'],
                'dr_no' => $validated['dr_no'] ?? null,
                'dr_date' => $validated['dr_date'] ?? null,
                'vendor' => $validated['vendor'] ?? null,
                'origin_location' => $this->normalizeStoreCode($validated['origin_location'] ?? null),
                'received_by' => $validated['received_by'] ?? null,
                'posted_by' => null,
                'status' => 'For Posting',
                'asset_id' => $validated['asset_id'],
                'quantity' => 1,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
                ...$this->normalizeStockEntry(Arr::only($validated, [
                    'serial_no',
                    'barcode',
                    'qrcode',
                    'warranty_months',
                    'eol_months',
                    'cost',
                    'price',
                    'destination_location',
                ])),
            ]);

            $imported++;
        }

        return response()->json([
            'imported' => $imported,
            'errors' => $errors,
        ]);
    }

    public function template(Request $request)
    {
        abort_unless($request->user()?->can('stock_ins.create'), 403);

        $assets = Asset::orderBy('item_code')->get(['item_code', 'cost']);
        $stores = Store::where('is_active', true)->orderBy('name')->get(['code', 'name']);
        $vendors = Vendor::active()->orderBy('name')->get(['name']);

        $spreadsheet = new Spreadsheet;

        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $listsSheet->setCellValue('A1', 'Assets');
        foreach ($assets as $index => $asset) {
            $listsSheet->setCellValue('A'.($index + 2), $asset->item_code);
        }

        $listsSheet->setCellValue('B1', 'Stores');
        foreach ($stores as $index => $store) {
            $listsSheet->setCellValue('B'.($index + 2), $store->code);
        }

        $listsSheet->setCellValue('C1', 'Vendors');
        foreach ($vendors as $index => $vendor) {
            $listsSheet->setCellValue('C'.($index + 2), $vendor->name);
        }

        $listsSheet->setCellValue('D1', 'Status');
        $listsSheet->setCellValue('D2', 'For Posting');
        $listsSheet->setCellValue('D3', 'Posted');

        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('Import Template');

        $headers = $this->stockInImportHeaders();
        foreach ($headers as $index => $header) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue("{$col}1", $header);
        }

        $today = now()->toDateString();
        $sampleAsset = $assets->first();
        $sampleStore = $stores->first();
        $sampleVendor = $vendors->first();

        $sheet->fromArray([
            [
                $today,
                'DR-001',
                $today,
                $sampleVendor?->name ?? '',
                $sampleStore?->code ?? '',
                $request->user()?->name ?? '',
                $sampleAsset?->item_code ?? '',
                'SN-001',
                'BC-001',
                'Sample QR content',
                '12',
                '60',
                $sampleAsset?->cost ?? '0',
                '0',
                $sampleStore?->code ?? '',
            ],
            [
                $today,
                'DR-001',
                $today,
                $sampleVendor?->name ?? '',
                $sampleStore?->code ?? '',
                $request->user()?->name ?? '',
                $sampleAsset?->item_code ?? '',
                'SN-002',
                'BC-002',
                'Sample QR content',
                '12',
                '60',
                $sampleAsset?->cost ?? '0',
                '0',
                $sampleStore?->code ?? '',
            ],
        ], null, 'A2');

        $sheet->getStyle('A1:O1')->getFont()->setBold(true);
        $sheet->getStyle('A1:O1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');
        $sheet->getStyle('A:A')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->getStyle('C:C')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
        $sheet->getStyle('K:L')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
        $sheet->getStyle('M:N')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->freezePane('A2');

        foreach (range(1, count($headers)) as $colIndex) {
            $col = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        if ($assets->isNotEmpty()) {
            $assetFormula = sprintf('Lists!$A$2:$A$%d', $assets->count() + 1);
            $this->applyListValidation($sheet, 'G', $assetFormula, false);
        }

        if ($stores->isNotEmpty()) {
            $storeFormula = sprintf('Lists!$B$2:$B$%d', $stores->count() + 1);
            $this->applyListValidation($sheet, 'E', $storeFormula, true);
            $this->applyListValidation($sheet, 'O', $storeFormula, true);
        }

        if ($vendors->isNotEmpty()) {
            $vendorFormula = sprintf('Lists!$C$2:$C$%d', $vendors->count() + 1);
            $this->applyListValidation($sheet, 'D', $vendorFormula, true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $filename = 'stock-ins-import-template.xlsx';
        $httpHeaders = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'max-age=0',
        ];

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, $httpHeaders);
    }

    public function printBarcodes(StockIn $stockIn)
    {
        $barcode = new DNS1D;
        $items = $this->groupedStockInRows($stockIn)
            ->whereNotNull('barcode')
            ->get()
            ->map(function (StockIn $item) use ($barcode) {
                return [
                    'item' => $item,
                    'image' => $barcode->getBarcodePNG($item->barcode, 'C128', 2, 58),
                ];
            });

        if ($items->isEmpty()) {
            return 'No barcodes generated for this stock group.';
        }

        $pdf = Pdf::loadView('pdf.stock-in-barcodes', compact('items'))
            ->setPaper('a4', 'portrait');

        return $pdf->stream('barcodes-'.$stockIn->receive_date->format('Y-m-d').'.pdf');
    }

    public function printQrcodes(StockIn $stockIn)
    {
        $qrcode = new DNS2D;
        $items = $this->groupedStockInRows($stockIn)
            ->whereNotNull('qrcode')
            ->get()
            ->map(function (StockIn $item) use ($qrcode) {
                return [
                    'item' => $item,
                    'image' => $qrcode->getBarcodePNG($item->qrcode, 'QRCODE', 4, 4, [0, 0, 0], [255, 255, 255]),
                ];
            });

        if ($items->isEmpty()) {
            return 'No QR codes generated for this stock group.';
        }

        $pdf = Pdf::loadView('pdf.stock-in-qrcodes', compact('items'))
            ->setPaper('a4', 'portrait');

        return $pdf->stream('qrcodes-'.$stockIn->receive_date->format('Y-m-d').'.pdf');
    }

    protected function prepareEntriesForSave(Asset $asset, ?string $originLocation, array $entries, array $excludeChildIds = []): array
    {
        $originLocation = $this->normalizeStoreCode($originLocation);

        if (! $this->isInternalTransferLocation($originLocation)) {
            return array_map(function (array $entry) {
                $entry['source_stock_in_id'] = null;

                return $entry;
            }, $entries);
        }

        $entries = array_map(function (array $entry) {
            $entry['destination_location'] = $this->normalizeStoreCode($entry['destination_location'] ?? null);

            return $entry;
        }, $entries);

        foreach ($entries as $index => $entry) {
            if (empty($entry['destination_location'])) {
                throw ValidationException::withMessages([
                    "entries.{$index}.destination_location" => 'Destination location is required for an internal transfer.',
                ]);
            }

            if ($this->sameLocation($originLocation, $entry['destination_location'])) {
                throw ValidationException::withMessages([
                    "entries.{$index}.destination_location" => 'Destination location must be different from the origin location.',
                ]);
            }
        }

        $soh = $this->inventorySoh($asset->id, $originLocation);
        if (count($entries) > $soh) {
            throw ValidationException::withMessages([
                'quantity' => "Only {$soh} item(s) are available at {$originLocation}.",
            ]);
        }

        if (! $this->isFixedAsset($asset)) {
            return array_map(function (array $entry) {
                $entry['source_stock_in_id'] = null;

                return $entry;
            }, $entries);
        }

        $sourceIds = array_map(
            fn (array $entry) => isset($entry['source_stock_in_id']) ? (int) $entry['source_stock_in_id'] : null,
            $entries
        );

        if (in_array(null, $sourceIds, true)) {
            throw ValidationException::withMessages([
                'entries' => 'Select the source stock unit for every fixed asset transfer row.',
            ]);
        }

        if (count($sourceIds) !== count(array_unique($sourceIds))) {
            throw ValidationException::withMessages([
                'entries' => 'A source stock unit can only be selected once in the same transfer.',
            ]);
        }

        $sourceRows = $this->availableFixedSourceRows($asset, $originLocation, $excludeChildIds)
            ->whereIn('id', $sourceIds)
            ->get()
            ->keyBy('id');

        if ($sourceRows->count() !== count($sourceIds)) {
            throw ValidationException::withMessages([
                'entries' => 'One or more selected source units are no longer available at the origin location.',
            ]);
        }

        return array_map(function (array $entry) use ($sourceRows) {
            $source = $sourceRows->get((int) $entry['source_stock_in_id']);

            return [
                ...$entry,
                'source_stock_in_id' => $source->id,
                'serial_no' => $source->serial_no,
                'barcode' => $source->barcode,
                'qrcode' => $source->qrcode,
                'warranty_months' => $source->warranty_months,
                'eol_months' => $source->eol_months,
                'cost' => $source->cost,
                'price' => $source->price,
            ];
        }, $entries);
    }

    protected function validatePostableTransfers($itemsToPost): void
    {
        $transferItems = $itemsToPost->filter(
            fn (StockIn $item) => $this->isInternalTransferLocation($item->origin_location)
        );

        if ($transferItems->isEmpty()) {
            return;
        }

        $transferItems
            ->groupBy(fn (StockIn $item) => $item->asset_id.'|'.$this->normalizeStoreCode($item->origin_location))
            ->each(function ($items) {
                $firstItem = $items->first();
                $asset = Asset::findOrFail($firstItem->asset_id);
                $originLocation = $this->normalizeStoreCode($firstItem->origin_location);

                $entries = $items->map(fn (StockIn $item) => [
                    'source_stock_in_id' => $item->source_stock_in_id,
                    'destination_location' => $item->destination_location,
                ])->all();

                $this->prepareEntriesForSave(
                    $asset,
                    $originLocation,
                    $entries,
                    $items->pluck('id')->all()
                );
            });
    }

    protected function stockInLedgerRows(StockIn $item, ?int $userId): array
    {
        $base = [
            'asset_id' => $item->asset_id,
            'reference_type' => StockIn::class,
            'reference_id' => $item->id,
            'created_by' => $userId,
            'updated_by' => $userId,
        ];

        if ($this->isInternalTransferLocation($item->origin_location)) {
            return [
                [
                    ...$base,
                    'location' => $this->requiredLedgerLocation(
                        $item->origin_location,
                        'origin_location',
                        'Origin location is required before posting an internal transfer.'
                    ),
                    'transaction_type' => 'Transfer Out',
                    'quantity' => -$item->quantity,
                ],
                [
                    ...$base,
                    'location' => $this->requiredLedgerLocation(
                        $item->destination_location,
                        'destination_location',
                        'Destination location is required before posting stock in.'
                    ),
                    'transaction_type' => 'Transfer In',
                    'quantity' => $item->quantity,
                ],
            ];
        }

        return [
            [
                ...$base,
                'location' => $this->requiredLedgerLocation(
                    $item->destination_location,
                    'destination_location',
                    'Destination location is required before posting stock in.'
                ),
                'transaction_type' => 'Stock In',
                'quantity' => $item->quantity,
            ],
        ];
    }

    protected function requiredLedgerLocation(?string $location, string $field, string $message): string
    {
        $location = $this->normalizeStoreCode($location);

        if (! $location) {
            throw ValidationException::withMessages([
                $field => $message,
            ]);
        }

        return $location;
    }

    protected function availableFixedSourceRows(Asset $asset, string $originLocation, array $excludeChildIds = [])
    {
        $originLocation = $this->normalizeStoreCode($originLocation);

        return StockIn::query()
            ->where('asset_id', $asset->id)
            ->where('status', 'Posted')
            ->where('destination_location', $originLocation)
            ->whereDoesntHave('transferChildren', function ($query) use ($excludeChildIds) {
                $query->whereIn('status', self::RESERVED_TRANSFER_STATUSES);

                if (! empty($excludeChildIds)) {
                    $query->whereNotIn('id', $excludeChildIds);
                }
            })
            ->orderBy('serial_no')
            ->orderBy('id');
    }

    protected function inventorySoh(int $assetId, string $location): int
    {
        return (int) InventoryTransaction::where('asset_id', $assetId)
            ->where('location', $this->normalizeStoreCode($location))
            ->sum('quantity');
    }

    protected function isInternalTransferLocation(?string $location): bool
    {
        return ! empty($location) && ! $this->isSupplierLocation($location);
    }

    protected function isSupplierLocation(?string $location): bool
    {
        return strtoupper(trim((string) $location)) === self::SUPPLIER_LOCATION;
    }

    protected function sameLocation(?string $left, ?string $right): bool
    {
        return strtoupper(trim((string) $left)) === strtoupper(trim((string) $right));
    }

    protected function isFixedAsset(Asset $asset): bool
    {
        return $asset->type === 'Fixed';
    }

    protected function groupedStockInRows(StockIn $stockIn)
    {
        $query = StockIn::with(['asset', 'creator:id,name,email', 'updater:id,name,email', 'sourceStockIn'])
            ->where('asset_id', $stockIn->asset_id)
            ->whereDate('receive_date', $stockIn->receive_date);

        $fields = ['dr_no', 'dr_date', 'vendor', 'origin_location', 'received_by', 'status', 'posted_by', 'posted_date'];
        foreach ($fields as $field) {
            if ($stockIn->$field !== null) {
                $query->where($field, $stockIn->$field);
            } else {
                $query->whereNull($field);
            }
        }

        return $query->orderBy('id');
    }

    protected function syncGroupedEntries(StockIn $stockIn, array $validated, $relatedRows = null): void
    {
        $entries = $validated['entries'] ?? [[
            'source_stock_in_id' => $validated['source_stock_in_id'] ?? null,
            'serial_no' => $validated['serial_no'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'qrcode' => $validated['qrcode'] ?? null,
            'warranty_months' => $validated['warranty_months'],
            'eol_months' => $validated['eol_months'],
            'cost' => $validated['cost'],
            'price' => $validated['price'],
            'destination_location' => $validated['destination_location'] ?? null,
        ]];

        $relatedRows ??= $this->groupedStockInRows($stockIn)->get();

        foreach (array_values($entries) as $index => $entry) {
            $payload = [
                'receive_date' => $validated['receive_date'],
                'dr_no' => $validated['dr_no'] ?? null,
                'dr_date' => $validated['dr_date'] ?? null,
                'vendor' => $validated['vendor'] ?? null,
                'origin_location' => $this->normalizeStoreCode($validated['origin_location'] ?? null),
                'received_by' => $validated['received_by'] ?? null,
                'memo_remarks' => $validated['memo_remarks'] ?? null,
                'posted_by' => $validated['posted_by'] ?? null,
                'status' => $validated['status'],
                'asset_id' => $validated['asset_id'],
                'quantity' => 1,
                'updated_by' => auth()->id(),
                ...$this->normalizeStockEntry(Arr::only($entry, [
                    'source_stock_in_id',
                    'serial_no',
                    'barcode',
                    'qrcode',
                    'warranty_months',
                    'eol_months',
                    'cost',
                    'price',
                    'destination_location',
                ])),
            ];

            if (isset($relatedRows[$index])) {
                $relatedRows[$index]->update($payload);
            } else {
                StockIn::create([
                    ...$payload,
                    'created_by' => auth()->id(),
                ]);
            }
        }

        foreach ($relatedRows->slice(count($entries)) as $extraRow) {
            $extraRow->delete();
        }
    }

    protected function normalizeStockEntry(array $entry): array
    {
        if (array_key_exists('origin_location', $entry)) {
            $entry['origin_location'] = $this->normalizeStoreCode($entry['origin_location']);
        }

        if (array_key_exists('destination_location', $entry)) {
            $entry['destination_location'] = $this->normalizeStoreCode($entry['destination_location']);
        }

        return $entry;
    }

    protected function stockInCodeValidationMessages(): array
    {
        return [
            'entries.*.barcode.required' => 'Generate a barcode for every stock-in row before saving or updating.',
            'entries.*.qrcode.required' => 'Generate a QR code for every stock-in row before saving or updating.',
            'barcode.required_without' => 'Generate a barcode before updating this stock-in record.',
            'qrcode.required_without' => 'Generate a QR code before updating this stock-in record.',
        ];
    }

    protected function stockInImportHeaders(): array
    {
        return [
            'receive_date',
            'dr_no',
            'dr_date',
            'vendor',
            'origin_location',
            'received_by',
            'item_code',
            'serial_no',
            'barcode',
            'qrcode',
            'warranty_months',
            'eol_months',
            'cost',
            'price',
            'destination_location',
        ];
    }

    protected function applyListValidation($sheet, string $column, string $formula, bool $allowBlank): void
    {
        foreach (range(2, 1001) as $row) {
            $validation = $sheet->getCell("{$column}{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                ->setAllowBlank($allowBlank)
                ->setShowDropDown(true)
                ->setShowInputMessage(true)
                ->setFormula1($formula);
        }
    }

    protected function normalizeImportValue($value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    protected function normalizeImportDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 1000) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return trim((string) $value);
    }

    protected function normalizeImportNumber($value, $default = null)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return str_replace(',', '', trim((string) $value));
    }

    protected function normalizeStoreCode(?string $value): ?string
    {
        if (! $value) {
            return $value;
        }

        $store = Store::query()
            ->where('code', $value)
            ->orWhere('name', $value)
            ->first(['code']);

        return $store?->code ?? $value;
    }
}
