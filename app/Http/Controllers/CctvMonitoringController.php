<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LocatesInventoryUnits;
use App\Models\Category;
use App\Models\CctvInspection;
use App\Models\CctvSystem;
use App\Models\Item;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketAsset;
use App\Models\User;
use App\Services\CctvEquipmentMatcher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CctvMonitoringController extends Controller implements HasMiddleware
{
    use LocatesInventoryUnits;

    private const MONTHS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

    public static function middleware(): array
    {
        return [
            new Middleware('can:cctv_monitoring.view', only: ['index', 'importTemplate', 'unitsSearch', 'showInspection']),
            new Middleware('can:cctv_monitoring.create', only: ['store', 'storeInspection', 'import']),
            new Middleware('can:cctv_monitoring.edit', only: ['update', 'updateInspection']),
            new Middleware('can:cctv_monitoring.delete', only: ['destroy', 'destroyInspection']),
        ];
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2000|max:2100',
            'sector' => 'nullable|integer',
            'brand' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::in(CctvInspection::STATUSES)],
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:5|max:200',
        ]);

        $year = (int) ($validated['year'] ?? now()->year);
        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = CctvSystem::query()
            ->with(['store:id,code,name,brand,area,sector', 'latestInspection']);

        $query->whereHas('store', function ($q) use ($validated) {
            if (isset($validated['sector'])) {
                $q->where('sector', $validated['sector']);
            }
            if (!empty($validated['brand'])) {
                $q->where('brand', $validated['brand']);
            }
            if (!empty($validated['search'])) {
                $q->where(function ($sq) use ($validated) {
                    $sq->where('code', 'like', "%{$validated['search']}%")
                        ->orWhere('name', 'like', "%{$validated['search']}%")
                        ->orWhere('brand', 'like', "%{$validated['search']}%");
                });
            }
        });

        $systems = $query->get()->sortBy(fn ($s) => $s->store?->code)->values();

        $systems->load(['inspections' => fn ($q) => $q->whereYear('inspection_date', $year)]);

        $inventoryByStore = $this->batchInventoryContext($systems);

        $rows = $systems->map(fn (CctvSystem $system) => $this->serializeSystemRow($system, $year, $inventoryByStore))->values();

        $filtered = $rows
            ->when(!empty($validated['status']), fn (Collection $items) => $items->filter(
                fn (array $row) => ($row['latest_status'] ?? null) === $validated['status']
            ))
            ->values();

        $page = (int) $request->get('page', 1);
        $pageItems = $filtered->forPage($page, $perPage)->values();

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $pageItems,
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $availableStores = Store::whereNotIn('id', CctvSystem::pluck('store_id'))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'brand'])
            ->map(fn ($s) => ['label' => "{$s->code} — {$s->name} ({$s->brand})", 'value' => $s->id]);

        return Inertia::render('CctvMonitoring/Index', [
            'rows' => $paginator,
            'filters' => [
                'year' => $year,
                'sector' => $validated['sector'] ?? null,
                'brand' => $validated['brand'] ?? null,
                'status' => $validated['status'] ?? null,
                'search' => $validated['search'] ?? '',
                'per_page' => $perPage,
            ],
            'statuses' => CctvInspection::STATUSES,
            'lguStatuses' => CctvInspection::LGU_STATUSES,
            'brands' => Store::whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand'),
            'sectors' => Store::whereNotNull('sector')->distinct()->orderBy('sector')->pluck('sector'),
            'summary' => $this->buildSummary($rows),
            'availableStores' => $availableStores,
            'assignableStaff' => User::whereHas('roles', fn ($q) => $q->where('is_assignable', true))
                ->select('id', 'name')
                ->orderBy('name')
                ->pluck('name')
                ->map(fn ($name) => ['label' => $name, 'value' => $name]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'cctv_type' => 'nullable|in:DVR,NVR,Hybrid',
            'has_qr_code' => 'boolean',
            'setup_completed' => 'boolean',
            'dpo_seal_checking' => 'nullable|in:Pending,Done,N/A',
            'dvr_nvr_count' => 'nullable|integer|min:0',
            'expected_cameras' => 'nullable|integer|min:0',
        ]);

        if (CctvSystem::where('store_id', $validated['store_id'])->exists()) {
            throw ValidationException::withMessages([
                'store_id' => 'A CCTV system already exists for this store. Edit it instead.',
            ]);
        }

        $validated['created_by'] = $request->user()->id;

        CctvSystem::create($validated);

        return redirect()->back()->with('success', 'CCTV system created successfully');
    }

    public function update(Request $request, CctvSystem $cctvSystem)
    {
        $validated = $request->validate([
            'cctv_type' => 'nullable|in:DVR,NVR,Hybrid',
            'has_qr_code' => 'boolean',
            'setup_completed' => 'boolean',
            'dpo_seal_checking' => 'nullable|in:Pending,Done,N/A',
            'dvr_nvr_count' => 'nullable|integer|min:0',
            'expected_cameras' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $cctvSystem->update($validated);

        return redirect()->back()->with('success', 'CCTV system updated successfully');
    }

    public function destroy(CctvSystem $cctvSystem)
    {
        $cctvSystem->delete();

        return redirect()->back()->with('success', 'CCTV system deleted successfully');
    }

    public function storeInspection(Request $request, CctvSystem $cctvSystem)
    {
        $validated = $this->validateInspection($request);

        $inspection = DB::transaction(function () use ($validated, $request, $cctvSystem) {
            $ticket = $this->ensureTicket($cctvSystem, $validated, $request->user());

            $inspection = $cctvSystem->inspections()->create([
                'inspection_date' => $validated['inspection_date'],
                'overall_status' => $validated['overall_status'],
                'working_cameras' => $validated['working_cameras'],
                'not_working_cameras' => $validated['not_working_cameras'],
                'total_cameras' => $validated['total_cameras'],
                'technician' => $validated['technician'],
                'data_retention' => $validated['data_retention'],
                'storage' => $validated['storage'],
                'ups_status' => $validated['ups_status'],
                'lgu_memo' => $validated['lgu_memo'],
                'lgu_status' => $validated['lgu_status'],
                'next_step' => $validated['next_step'],
                'remarks' => $validated['remarks'],
                'ticket_id' => $ticket->id,
                'created_by' => $request->user()->id,
            ]);

            $this->syncLinkedUnits($inspection, $validated['linked_units'] ?? []);
            $this->tagDefectiveUnitsOnTicket($ticket, $inspection, $request->user()->id);
            CctvInspection::maintainLatestFlag($cctvSystem->id);

            return $inspection;
        });

        return redirect()->back()->with('success', 'CCTV inspection saved successfully');
    }

    public function updateInspection(Request $request, CctvInspection $cctvInspection)
    {
        $validated = $this->validateInspection($request);

        DB::transaction(function () use ($validated, $request, $cctvInspection) {
            $ticket = $this->ensureTicket($cctvInspection->cctvSystem, $validated, $request->user(), $cctvInspection);

            $cctvInspection->update([
                'inspection_date' => $validated['inspection_date'],
                'overall_status' => $validated['overall_status'],
                'working_cameras' => $validated['working_cameras'],
                'not_working_cameras' => $validated['not_working_cameras'],
                'total_cameras' => $validated['total_cameras'],
                'technician' => $validated['technician'],
                'data_retention' => $validated['data_retention'],
                'storage' => $validated['storage'],
                'ups_status' => $validated['ups_status'],
                'lgu_memo' => $validated['lgu_memo'],
                'lgu_status' => $validated['lgu_status'],
                'next_step' => $validated['next_step'],
                'remarks' => $validated['remarks'],
                'ticket_id' => $ticket->id,
            ]);

            $this->syncLinkedUnits($cctvInspection, $validated['linked_units'] ?? []);
            $this->tagDefectiveUnitsOnTicket($ticket, $cctvInspection, $request->user()->id);
            CctvInspection::maintainLatestFlag($cctvInspection->cctv_system_id);
        });

        return redirect()->back()->with('success', 'CCTV inspection updated successfully');
    }

    public function destroyInspection(CctvInspection $cctvInspection)
    {
        $systemId = $cctvInspection->cctv_system_id;
        $cctvInspection->delete();
        CctvInspection::maintainLatestFlag($systemId);

        return redirect()->back()->with('success', 'CCTV inspection deleted successfully');
    }

    public function showInspection(Request $request, CctvInspection $cctvInspection)
    {
        $cctvInspection->load([
            'ticket:id,ticket_key,title,status',
            'linkedUnits:id,asset_id,serial_no,barcode',
            'linkedUnits.asset:id,item_code,brand,model',
        ]);

        return response()->json([
            'id' => $cctvInspection->id,
            'cctv_system_id' => $cctvInspection->cctv_system_id,
            'inspection_date' => $cctvInspection->inspection_date?->format('Y-m-d'),
            'overall_status' => $cctvInspection->overall_status,
            'working_cameras' => $cctvInspection->working_cameras,
            'not_working_cameras' => $cctvInspection->not_working_cameras,
            'total_cameras' => $cctvInspection->total_cameras,
            'technician' => $cctvInspection->technician,
            'data_retention' => $cctvInspection->data_retention,
            'storage' => $cctvInspection->storage,
            'ups_status' => $cctvInspection->ups_status,
            'lgu_memo' => $cctvInspection->lgu_memo,
            'lgu_status' => $cctvInspection->lgu_status,
            'next_step' => $cctvInspection->next_step,
            'remarks' => $cctvInspection->remarks,
            'ticket_id' => $cctvInspection->ticket_id,
            'ticket' => $cctvInspection->ticket ? [
                'id' => $cctvInspection->ticket->id,
                'ticket_key' => $cctvInspection->ticket->ticket_key,
                'title' => $cctvInspection->ticket->title,
                'status' => $cctvInspection->ticket->status,
            ] : null,
            'linked_units' => $cctvInspection->linkedUnits->map(fn ($u) => [
                'stock_in_id' => $u->id,
                'condition' => $u->pivot->condition,
                'notes' => $u->pivot->notes,
                'serial_no' => $u->serial_no,
                'barcode' => $u->barcode,
                'item_code' => $u->asset?->item_code,
                'brand' => $u->asset?->brand,
                'model' => $u->asset?->model,
            ]),
        ]);
    }

    public function unitsSearch(Request $request, Store $store)
    {
        $units = $this->fixedUnitsCurrentlyAt($this->locationVariants($store->code));

        $cctvUnits = $units
            ->map(function (\App\Models\StockIn $unit) {
                $role = CctvEquipmentMatcher::classify($unit);
                if (!$role) {
                    return null;
                }

                return [
                    'stock_in_id' => $unit->id,
                    'asset_id' => $unit->asset_id,
                    'item_code' => $unit->asset?->item_code,
                    'brand' => $unit->asset?->brand,
                    'model' => $unit->asset?->model,
                    'serial_no' => $unit->serial_no,
                    'barcode' => $unit->barcode,
                    'role' => $role,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'store_id' => $store->id,
            'units' => $cctvUnits,
        ]);
    }

    public function importTemplate()
    {
        $headers = [
            'Store Code', 'CCTV Type', 'QR Code', 'Setup Completed', 'DPO Seal Checking', 'Total DVR/NVR No',
            'Month', 'Date', 'Status', 'Working Camera', 'Not Working Camera', 'Total Camera',
            'Data Retention', 'Storage', 'UPS Status', 'LGU Memo', 'LGU Status', 'Tech Eng', 'Next Step', 'Remarks',
        ];

        return response()->streamDownload(function () use ($headers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            fputcsv($out, ['CBTL CAU', 'NVR', 'TRUE', 'TRUE', 'DONE', '1', 'JANUARY', '2026-01-05', 'Working', '5', '0', '5', '40', '5TB', 'Working', '', 'Pending', 'GERR', 'None', '']);
            fclose($out);
        }, 'cctv-monitoring-template.csv');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt']);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');
        $headerRow = fgetcsv($handle);
        $headerMap = array_change_key_case(array_map('trim', $headerRow ?: []));

        $col = fn ($name) => array_search(strtolower($name), $headerMap, true);

        $imported = 0;
        $errors = [];
        $rowNumber = 1;
        $userId = $request->user()->id;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $get = function ($name) use ($row, $col) {
                $idx = $col($name);
                return $idx === false ? null : trim($row[$idx] ?? '');
            };

            $storeCode = $get('Store Code');
            if ($storeCode === '') {
                continue;
            }

            $store = Store::where('code', $storeCode)->orWhere('name', $storeCode)->first();
            if (!$store) {
                $errors[] = "Row {$rowNumber}: Store \"{$storeCode}\" not found.";
                continue;
            }

            $system = CctvSystem::firstOrCreate(
                ['store_id' => $store->id],
                [
                    'cctv_type' => $this->mapCctvType($get('CCTV Type')),
                    'has_qr_code' => $this->mapBool($get('QR Code')),
                    'setup_completed' => $this->mapBool($get('Setup Completed')),
                    'dpo_seal_checking' => $this->mapDpo($get('DPO Seal Checking')),
                    'dvr_nvr_count' => $this->mapInt($get('Total DVR/NVR No')),
                    'created_by' => $userId,
                ]
            );

            $monthLabel = strtoupper($get('Month') ?: '');
            $status = $this->normalizeStatus($get('Status'));
            if ($monthLabel === '' || $status === null) {
                continue;
            }

            $monthNum = $this->monthNumber($monthLabel);
            if (!$monthNum) {
                continue;
            }

            $dateStr = $get('Date');
            try {
                $date = $dateStr ? Carbon::parse($dateStr) : now();
            } catch (\Throwable $e) {
                $date = now();
            }

            DB::transaction(function () use ($get, $store, $system, $monthNum, $date, $status, $userId, &$imported) {
                $ticket = $this->createCctvTicket($system, $store, $monthNum, $date->year, $userId, $status);

                $system->inspections()->create([
                    'inspection_date' => $date->toDateString(),
                    'overall_status' => $status,
                    'working_cameras' => $this->mapInt($get('Working Camera')),
                    'not_working_cameras' => $this->mapInt($get('Not Working Camera')),
                    'total_cameras' => $this->mapInt($get('Total Camera')),
                    'technician' => $get('Tech Eng'),
                    'data_retention' => $get('Data Retention'),
                    'storage' => $get('Storage'),
                    'ups_status' => $get('UPS Status'),
                    'lgu_memo' => $get('LGU Memo'),
                    'lgu_status' => $this->normalizeLgu($get('LGU Status')),
                    'next_step' => $get('Next Step'),
                    'remarks' => $get('Remarks'),
                    'ticket_id' => $ticket->id,
                    'created_by' => $userId,
                ]);

                CctvInspection::maintainLatestFlag($system->id);
                $imported++;
            });
        }

        fclose($handle);

        if ($request->expectsJson()) {
            return response()->json(['imported' => $imported, 'errors' => $errors]);
        }

        return redirect()->back()->with('success', "Imported {$imported} CCTV inspection(s).")->withErrors($errors ? ['import' => $errors] : []);
    }

    private function validateInspection(Request $request): array
    {
        return $request->validate([
            'inspection_date' => 'required|date',
            'overall_status' => ['required', Rule::in(CctvInspection::STATUSES)],
            'working_cameras' => 'nullable|integer|min:0',
            'not_working_cameras' => 'nullable|integer|min:0',
            'total_cameras' => 'nullable|integer|min:0',
            'technician' => 'nullable|string|max:255',
            'data_retention' => 'nullable|string|max:255',
            'storage' => 'nullable|string|max:255',
            'ups_status' => 'nullable|string|max:255',
            'lgu_memo' => 'nullable|string|max:255',
            'lgu_status' => ['nullable', Rule::in(CctvInspection::LGU_STATUSES)],
            'next_step' => 'nullable|string|max:2000',
            'remarks' => 'nullable|string|max:2000',
            'ticket_id' => 'nullable|exists:tickets,id',
            'ticket_title' => 'nullable|string|max:255',
            'ticket_description' => 'nullable|string',
            'linked_units' => 'nullable|array',
            'linked_units.*.stock_in_id' => 'required|exists:stock_ins,id',
            'linked_units.*.condition' => ['required', Rule::in(CctvInspection::UNIT_CONDITIONS)],
            'linked_units.*.notes' => 'nullable|string|max:1000',
        ]);
    }

    private function ensureTicket(CctvSystem $system, array $validated, $user, ?CctvInspection $inspection = null): Ticket
    {
        // Reuse the inspection's existing ticket on edit, or an explicitly provided one.
        if ($inspection && $inspection->ticket_id) {
            $existing = Ticket::find($inspection->ticket_id);
            if ($existing) {
                return $existing;
            }
        }

        if (!empty($validated['ticket_id'])) {
            $existing = Ticket::find($validated['ticket_id']);
            if ($existing) {
                return $existing;
            }
        }

        $store = $system->store;
        $monthLabel = Carbon::parse($validated['inspection_date'])->format('M Y');
        [$categoryId, $itemId] = $this->resolveCctvTicketRefs();

        $title = $validated['ticket_title']
            ?: "CCTV Inspection – {$store->code} ({$store->name}) – {$monthLabel}";

        return Ticket::create([
            'title' => $title,
            'description' => $validated['ticket_description'] ?? $validated['remarks'] ?? null,
            'type' => 'task',
            'status' => 'open',
            'priority' => $validated['overall_status'] === 'Not Working' ? 'high' : 'medium',
            'severity' => 'minor',
            'reporter_id' => $user->id,
            'company_id' => $user->company_id,
            'store_id' => $store->id,
            'category_id' => $categoryId,
            'item_id' => $itemId,
            'created_at' => now('Asia/Manila'),
        ]);
    }

    private function createCctvTicket(CctvSystem $system, Store $store, int $month, int $year, int $userId, string $status): Ticket
    {
        [$categoryId, $itemId] = $this->resolveCctvTicketRefs();

        return Ticket::create([
            'title' => "CCTV Inspection – {$store->code} – " . date('M', mktime(0, 0, 0, $month)) . " {$year}",
            'type' => 'task',
            'status' => 'open',
            'priority' => $status === 'Not Working' ? 'high' : 'medium',
            'severity' => 'minor',
            'reporter_id' => $userId,
            'company_id' => $userId ? (\App\Models\User::find($userId)?->company_id) : null,
            'store_id' => $store->id,
            'category_id' => $categoryId,
            'item_id' => $itemId,
            'created_at' => now('Asia/Manila'),
        ]);
    }

    private function resolveCctvTicketRefs(): array
    {
        $category = Category::firstOrCreate(['name' => 'CCTV'], ['is_active' => true]);
        $item = Item::firstOrCreate(
            ['name' => 'CCTV – General', 'category_id' => $category->id],
            ['priority' => 'medium', 'is_active' => true]
        );

        return [$category->id, $item->id];
    }

    private function syncLinkedUnits(CctvInspection $inspection, array $units): void
    {
        $inspection->linkedUnits()->detach();

        foreach ($units as $unit) {
            $inspection->linkedUnits()->attach($unit['stock_in_id'], [
                'condition' => $unit['condition'],
                'notes' => $unit['notes'] ?? null,
            ]);
        }
    }

    private function tagDefectiveUnitsOnTicket(Ticket $ticket, CctvInspection $inspection, int $userId): void
    {
        TicketAsset::where('ticket_id', $ticket->id)
            ->where('transaction_type', 'Defective (CCTV)')
            ->delete();

        $defective = $inspection->linkedUnits()->wherePivot('condition', 'Defective')->get();

        foreach ($defective as $unit) {
            TicketAsset::create([
                'ticket_id' => $ticket->id,
                'asset_id' => $unit->asset_id,
                'stock_in_id' => $unit->id,
                'serial_no' => $unit->serial_no,
                'barcode' => $unit->barcode,
                'transaction_type' => 'Defective (CCTV)',
                'quantity' => 1,
                'notes' => $inspection->remarks,
                'created_by' => $userId,
            ]);
        }
    }

    private function serializeSystemRow(CctvSystem $system, int $year, array $inventoryByStore = []): array
    {
        $inspections = $system->inspections;

        $months = [];
        foreach (self::MONTHS as $m) {
            $latest = $inspections->where(fn ($i) => $i->inspection_date?->month === $m)->sortByDesc('inspection_date')->first();
            $months[$m] = $latest ? [
                'status' => $latest->overall_status,
                'date' => $latest->inspection_date?->format('Y-m-d'),
                'ticket_key' => $latest->ticket?->ticket_key,
                'inspection_id' => $latest->id,
            ] : null;
        }

        $latestOverall = $system->latestInspection && $system->latestInspection->inspection_date?->year === $year
            ? $system->latestInspection->overall_status
            : ($inspections->sortByDesc('inspection_date')->first()?->overall_status);

        $storeId = $system->store?->id;

        return [
            'id' => $system->id,
            'store' => $system->store ? [
                'id' => $system->store->id,
                'code' => $system->store->code,
                'name' => $system->store->name,
                'brand' => $system->store->brand,
                'area' => $system->store->area,
                'sector' => $system->store->sector,
            ] : null,
            'cctv_type' => $system->cctv_type,
            'has_qr_code' => $system->has_qr_code,
            'setup_completed' => $system->setup_completed,
            'dpo_seal_checking' => $system->dpo_seal_checking,
            'dvr_nvr_count' => $system->dvr_nvr_count,
            'expected_cameras' => $system->expected_cameras,
            'months' => $months,
            'latest_status' => $latestOverall,
            'inventory_context' => $storeId ? ($inventoryByStore[$storeId] ?? ['camera_count' => 0, 'dvr_nvr_count' => 0, 'units' => []]) : ['camera_count' => 0, 'dvr_nvr_count' => 0, 'units' => []],
        ];
    }

    /**
     * Build CCTV inventory context for all stores in a single batched query,
     * instead of calling fixedUnitsCurrentlyAt() per store on every request.
     *
     * @param  \Illuminate\Support\Collection<int, CctvSystem>  $systems
     * @return array<int, array{camera_count: int, dvr_nvr_count: int, units: array}>
     */
    private function batchInventoryContext(Collection $systems): array
    {
        $locationToStore = []; // normalized location string => store_id
        $storeIds = [];

        foreach ($systems as $system) {
            $store = $system->store;
            if (!$store || !$store->code) {
                continue;
            }
            $storeIds[] = $store->id;
            foreach ($this->locationVariants($store->code) as $variant) {
                $locationToStore[$variant] = $store->id;
            }
        }

        if (empty($locationToStore)) {
            return [];
        }

        $units = \App\Models\StockIn::query()
            ->where('stock_ins.status', 'Posted')
            ->with([
                'asset:id,item_code,brand,model,description',
                'sourceStockTransfers' => fn ($q) => $q->whereIn('status', ['For Posting', 'Posted', 'Received'])
                    ->select('id', 'source_stock_in_id', 'status', 'destination_location')
                    ->orderByDesc('id'),
            ])
            ->orderBy('serial_no')
            ->get();

        $grouped = [];
        foreach ($units as $unit) {
            $role = CctvEquipmentMatcher::classify($unit);
            if (!$role) {
                continue;
            }

            $lastReceived = $unit->sourceStockTransfers->firstWhere('status', 'Received');
            $location = $lastReceived?->destination_location ?: $unit->destination_location;
            if (!$location) {
                continue;
            }

            $storeId = $locationToStore[$location] ?? null;
            if ($storeId === null) {
                continue;
            }

            $grouped[$storeId][] = [
                'stock_in_id' => $unit->id,
                'item_code' => $unit->asset?->item_code,
                'brand' => $unit->asset?->brand,
                'model' => $unit->asset?->model,
                'serial_no' => $unit->serial_no,
                'barcode' => $unit->barcode,
                'role' => $role,
            ];
        }

        $result = [];
        foreach ($storeIds as $storeId) {
            $storeUnits = $grouped[$storeId] ?? [];
            $result[$storeId] = [
                'camera_count' => count(array_filter($storeUnits, fn ($u) => $u['role'] === CctvEquipmentMatcher::ROLE_CAMERA)),
                'dvr_nvr_count' => count(array_filter($storeUnits, fn ($u) => $u['role'] === CctvEquipmentMatcher::ROLE_DVR_NVR)),
                'units' => array_values($storeUnits),
            ];
        }

        return $result;
    }

    private function buildSummary(Collection $rows): array
    {
        $counts = collect(CctvInspection::STATUSES)->mapWithKeys(fn ($s) => [$s => 0])->all();
        $compliant = 0;
        $withStatus = 0;

        foreach ($rows as $row) {
            $status = $row['latest_status'] ?? null;
            if ($status && isset($counts[$status])) {
                $counts[$status]++;
                $withStatus++;
            }
        }

        return [
            'status_counts' => $counts,
            'total_stores' => $rows->count(),
            'with_status' => $withStatus,
        ];
    }

    private function mapCctvType(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));
        if (in_array($value, ['DVR', 'NVR', 'HYBRID'], true)) {
            return $value === 'HYBRID' ? 'Hybrid' : $value;
        }
        return null;
    }

    private function mapBool(?string $value): bool
    {
        $value = strtoupper(trim((string) $value));
        return in_array($value, ['TRUE', 'YES', '1', 'DONE'], true);
    }

    private function mapDpo(?string $value): string
    {
        $value = strtoupper(trim((string) $value));
        return match ($value) {
            'DONE' => 'Done',
            'N/A', 'NA' => 'N/A',
            default => 'Pending',
        };
    }

    private function mapInt(?string $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }

    private function normalizeStatus(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (CctvInspection::STATUSES as $status) {
            if (strcasecmp($status, $value) === 0) {
                return $status;
            }
        }
        return null;
    }

    private function normalizeLgu(?string $value): string
    {
        $value = trim((string) $value);
        foreach (CctvInspection::LGU_STATUSES as $status) {
            if (strcasecmp($status, $value) === 0) {
                return $status;
            }
        }
        return 'Pending';
    }

    private function monthNumber(string $label): ?int
    {
        $map = [
            'JANUARY' => 1, 'FEBRUARY' => 2, 'MARCH' => 3, 'APRIL' => 4, 'MAY' => 5, 'JUNE' => 6,
            'JULY' => 7, 'AUGUST' => 8, 'SEPTEMBER' => 9, 'OCTOBER' => 10, 'NOVEMBER' => 11, 'DECEMBER' => 12,
        ];
        return $map[$label] ?? null;
    }
}
