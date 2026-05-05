<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\StockIn;
use App\Models\Store;
use App\Models\Vendor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
    public function index()
    {
        return Inertia::render('StockIn/Index', [
            'stockIns' => StockIn::with(['asset', 'creator:id,name,email', 'updater:id,name,email'])->latest()->paginate(10),
            'assets' => Asset::all(),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'vendors' => Vendor::active()->orderBy('name')->get(['id', 'code', 'name']),
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
            'posted_by' => 'nullable|string|max:255',
            'status' => 'required|in:For Posting,Posted',
            'asset_id' => 'required|exists:assets,id',
            'quantity' => 'required|integer|min:1',
            'entries' => 'required|array|min:1',
            'entries.*.serial_no' => 'nullable|string',
            'entries.*.barcode' => 'required|string',
            'entries.*.qrcode' => 'required|string',
            'entries.*.warranty_months' => 'required|integer|min:0',
            'entries.*.eol_months' => 'required|integer|min:0',
            'entries.*.cost' => 'required|numeric|min:0',
            'entries.*.price' => 'required|numeric|min:0',
            'entries.*.destination_location' => 'nullable|string|max:255',
        ], $this->stockInCodeValidationMessages());

        foreach ($validated['entries'] as $entry) {
            StockIn::create([
                'receive_date' => $validated['receive_date'],
                'dr_no' => $validated['dr_no'] ?? null,
                'dr_date' => $validated['dr_date'] ?? null,
                'vendor' => $validated['vendor'] ?? null,
                'origin_location' => $this->normalizeStoreCode($validated['origin_location'] ?? null),
                'received_by' => $validated['received_by'] ?? null,
                'posted_by' => $validated['posted_by'] ?? null,
                'status' => $validated['status'],
                'asset_id' => $validated['asset_id'],
                'quantity' => 1,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
                ...$this->normalizeStockEntry(Arr::only($entry, [
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
            'posted_by' => 'nullable|string|max:255',
            'status' => 'required|in:For Posting,Posted',
            'asset_id' => 'required|exists:assets,id',
            'quantity' => 'required|integer|min:1',
            'header_mode' => 'nullable|boolean',
            'entries' => 'nullable|array|min:1',
            'entries.*.serial_no' => 'nullable|string',
            'entries.*.barcode' => 'required|string',
            'entries.*.qrcode' => 'required|string',
            'entries.*.warranty_months' => 'required_with:entries|integer|min:0',
            'entries.*.eol_months' => 'required_with:entries|integer|min:0',
            'entries.*.cost' => 'required_with:entries|numeric|min:0',
            'entries.*.price' => 'required_with:entries|numeric|min:0',
            'entries.*.destination_location' => 'nullable|string|max:255',
            'serial_no' => 'nullable|string',
            'barcode' => 'required_without:entries|string',
            'qrcode' => 'required_without:entries|string',
            'warranty_months' => 'required_without:entries|integer|min:0',
            'eol_months' => 'required_without:entries|integer|min:0',
            'cost' => 'required_without:entries|numeric|min:0',
            'price' => 'required_without:entries|numeric|min:0',
            'destination_location' => 'nullable|string|max:255',
        ], $this->stockInCodeValidationMessages());

        if (!empty($validated['header_mode'])) {
            $this->syncGroupedEntries($stockIn, $validated);

            return redirect()->back()->with('success', 'Stock In updated successfully');
        }

        $stockIn->update([
            ...$this->normalizeStockEntry(Arr::except($validated, ['header_mode'])),
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->back()->with('success', 'Stock In updated successfully');
    }

    public function destroy(StockIn $stockIn)
    {
        $stockIn->delete();

        return redirect()->back()->with('success', 'Stock In deleted successfully');
    }

    public function post(Request $request, StockIn $stockIn)
    {
        abort_unless($request->user()->can('stock_ins.post'), 403);

        DB::table('stock_ins')
            ->where('asset_id', $stockIn->asset_id)
            ->whereDate('receive_date', $stockIn->receive_date)
            ->update([
                'status' => 'Posted',
                'posted_by' => $request->user()->name,
                'posted_date' => now(),
            ]);

        return redirect()->back()->with('success', 'Stock In status updated to Posted');
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

        if (!empty($missingHeaders)) {
            return response()->json([
                'imported' => 0,
                'errors' => ['Template is missing required columns: ' . implode(', ', $missingHeaders)],
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

            if (!$asset) {
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
                $errors[] = "Row {$rowNum}: " . implode(', ', $validator->errors()->all());
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

        $spreadsheet = new Spreadsheet();

        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $listsSheet->setCellValue('A1', 'Assets');
        foreach ($assets as $index => $asset) {
            $listsSheet->setCellValue('A' . ($index + 2), $asset->item_code);
        }

        $listsSheet->setCellValue('B1', 'Stores');
        foreach ($stores as $index => $store) {
            $listsSheet->setCellValue('B' . ($index + 2), $store->code);
        }

        $listsSheet->setCellValue('C1', 'Vendors');
        foreach ($vendors as $index => $vendor) {
            $listsSheet->setCellValue('C' . ($index + 2), $vendor->name);
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
        $barcode = new DNS1D();
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
            return "No barcodes generated for this stock group.";
        }

        $pdf = Pdf::loadView('pdf.stock-in-barcodes', compact('items'))
            ->setPaper('a4', 'portrait');

        return $pdf->stream('barcodes-' . $stockIn->receive_date->format('Y-m-d') . '.pdf');
    }

    public function printQrcodes(StockIn $stockIn)
    {
        $qrcode = new DNS2D();
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
            return "No QR codes generated for this stock group.";
        }

        $pdf = Pdf::loadView('pdf.stock-in-qrcodes', compact('items'))
            ->setPaper('a4', 'portrait');

        return $pdf->stream('qrcodes-' . $stockIn->receive_date->format('Y-m-d') . '.pdf');
    }

    protected function groupedStockInRows(StockIn $stockIn)
    {
        return StockIn::with('asset')
            ->where('asset_id', $stockIn->asset_id)
            ->whereDate('receive_date', $stockIn->receive_date->toDateString())
            ->orderBy('id');
    }

    protected function syncGroupedEntries(StockIn $stockIn, array $validated): void
    {
        $entries = $validated['entries'] ?? [[
            'serial_no' => $validated['serial_no'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'qrcode' => $validated['qrcode'] ?? null,
            'warranty_months' => $validated['warranty_months'],
            'eol_months' => $validated['eol_months'],
            'cost' => $validated['cost'],
            'price' => $validated['price'],
            'destination_location' => $validated['destination_location'] ?? null,
        ]];

        $relatedRows = StockIn::where('asset_id', $stockIn->asset_id)
            ->whereDate('receive_date', $stockIn->receive_date)
            ->orderBy('id')
            ->get();

        foreach (array_values($entries) as $index => $entry) {
            $payload = [
                'receive_date' => $validated['receive_date'],
                'dr_no' => $validated['dr_no'] ?? null,
                'dr_date' => $validated['dr_date'] ?? null,
                'vendor' => $validated['vendor'] ?? null,
                'origin_location' => $this->normalizeStoreCode($validated['origin_location'] ?? null),
                'received_by' => $validated['received_by'] ?? null,
                'posted_by' => $validated['posted_by'] ?? null,
                'status' => $validated['status'],
                'asset_id' => $validated['asset_id'],
                'quantity' => 1,
                'updated_by' => auth()->id(),
                ...$this->normalizeStockEntry(Arr::only($entry, [
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
        if (!$value) {
            return $value;
        }

        $store = Store::query()
            ->where('code', $value)
            ->orWhere('name', $value)
            ->first(['code']);

        return $store?->code ?? $value;
    }
}
