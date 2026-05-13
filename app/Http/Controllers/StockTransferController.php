<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\StockIn;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StockTransferController extends Controller
{
    public function index(Request $request)
    {
        $search     = trim((string) $request->input('search', ''));
        $perPage    = max(1, min(200, (int) $request->input('per_page', 10)));
        $statuses   = array_values(array_filter((array) $request->input('statuses', [])));
        $categoryId = $request->input('category_id');
        $location   = $request->input('location');

        $applyFilters = function ($query) use ($search, $statuses, $categoryId, $location) {
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('transfer_no', 'like', "%{$search}%")
                      ->orWhere('requested_by', 'like', "%{$search}%");
                });
            }
            if (! empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
            if ($categoryId) {
                $query->whereHas('asset', fn ($q) => $q->where('category_id', $categoryId));
            }
            if ($location) {
                $query->where('origin_location', $location);
            }
        };

        $query = StockTransfer::with(['creator:id,name,email', 'updater:id,name,email'])
            ->select(
                'transfer_date',
                'transfer_no',
                'origin_location',
                'destination_location',
                'requested_by',
                'memo_remarks',
                'status',
                'posted_by',
                'posted_date',
                DB::raw('SUM(quantity) as quantity'),
                DB::raw('COUNT(*) as record_count'),
                DB::raw('COUNT(DISTINCT asset_id) as asset_count'),
                DB::raw('MAX(id) as id'),
                DB::raw('MAX(created_at) as created_at'),
                DB::raw('MAX(updated_at) as updated_at')
            )
            ->groupBy(
                'transfer_date',
                'transfer_no',
                'origin_location',
                'destination_location',
                'requested_by',
                'memo_remarks',
                'status',
                'posted_by',
                'posted_date'
            );

        $applyFilters($query);

        $flatBase = StockTransfer::query();
        $applyFilters($flatBase);

        $summary = [
            'total_qty'       => (clone $flatBase)->sum('quantity'),
            'posted_qty'      => (clone $flatBase)->where('status', 'Posted')->sum('quantity'),
            'for_posting_qty' => (clone $flatBase)->where('status', 'For Posting')->sum('quantity'),
            'total_records'   => (clone $flatBase)->count(),
        ];

        $stockTransfers = $query->latest('transfer_date')->paginate($perPage);

        return Inertia::render('StockTransfer/Index', [
            'stockTransfers' => $stockTransfers,
            'assets'         => Asset::all(),
            'stores'         => Store::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'categories'     => Category::orderBy('name')->get(['id', 'name']),
            'locations'      => StockTransfer::whereNotNull('origin_location')
                ->distinct()
                ->orderBy('origin_location')
                ->pluck('origin_location'),
            'summary'        => $summary,
            'filters'        => $request->only(['category_id', 'location', 'statuses', 'search']),
        ]);
    }

    public function show(StockTransfer $stockTransfer)
    {
        return response()->json(
            $this->groupedTransferRows($stockTransfer)->get()
        );
    }

    public function assetsWithStock(Request $request)
    {
        $location = $this->normalizeStoreCode($request->input('location'));

        if (! $location) {
            return response()->json([]);
        }

        $locationVariants = $this->locationVariants($location);

        $sohData = InventoryTransaction::whereIn('location', $locationVariants)
            ->groupBy('asset_id')
            ->havingRaw('SUM(quantity) > 0')
            ->selectRaw('asset_id, SUM(quantity) as soh')
            ->pluck('soh', 'asset_id');

        $assets = Asset::whereIn('id', $sohData->keys())
            ->orderBy('item_code')
            ->get(['id', 'item_code', 'brand', 'model', 'description', 'type', 'cost'])
            ->map(fn ($a) => array_merge($a->toArray(), ['soh' => (int) $sohData->get($a->id, 0)]));

        return response()->json($assets);
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
        $locationVariants = $this->locationVariants($originLocation);

        $soh = (int) InventoryTransaction::where('inventory_transactions.asset_id', $asset->id)
            ->whereIn('inventory_transactions.location', $locationVariants)
            ->leftJoin('stock_ins as si_soh', function ($join) {
                $join->on('inventory_transactions.reference_id', '=', 'si_soh.id')
                     ->where('inventory_transactions.reference_type', '=', StockIn::class);
            })
            ->where(function ($q) {
                $q->where('inventory_transactions.reference_type', '!=', StockIn::class)
                  ->orWhere('si_soh.status', 'Posted');
            })
            ->sum('inventory_transactions.quantity');

        $excludeTransferIds = array_filter(
            (array) $request->input('exclude_transfer_ids', []),
            fn($v) => is_numeric($v)
        );

        $availableUnits = collect();
        if ($soh > 0 && $asset->type === 'Fixed') {
            $stockInRecords = StockIn::query()
                ->where('asset_id', $asset->id)
                ->where('status', 'Posted')
                ->with(['sourceStockTransfers' => function ($q) {
                    $q->whereIn('status', ['For Posting', 'Posted', 'Received'])
                      ->select('id', 'source_stock_in_id', 'transfer_no', 'status', 'destination_location')
                      ->orderByDesc('id');
                }])
                ->orderBy('serial_no')
                ->get();

            // Pull the "Stock In" inventory transaction location for each unit
            // (this is the SOH source of truth, and survives legacy data where
            // stock_ins.destination_location was stored differently).
            $stockInIds = $stockInRecords->pluck('id')->all();
            $initialLocations = InventoryTransaction::query()
                ->where('reference_type', StockIn::class)
                ->whereIn('reference_id', $stockInIds)
                ->where('transaction_type', 'Stock In')
                ->pluck('location', 'reference_id');

            $availableUnits = $stockInRecords
                ->filter(function ($unit) use ($locationVariants, $excludeTransferIds, $initialLocations) {
                    // Current location precedence:
                    //   1. Latest "Received" transfer destination (excluding the
                    //      transfer currently being edited)
                    //   2. The "Stock In" InventoryTransaction location
                    //   3. Original stock_ins.destination_location (last-resort fallback)
                    $lastReceived = $unit->sourceStockTransfers
                        ->filter(fn ($t) => $t->status === 'Received'
                            && ! in_array($t->id, $excludeTransferIds))
                        ->first();
                    $currentLocation = $lastReceived
                        ? $lastReceived->destination_location
                        : ($initialLocations->get($unit->id) ?? $unit->destination_location);
                    return in_array($currentLocation, $locationVariants, true);
                })
                ->map(function ($unit) use ($excludeTransferIds) {
                    // Reservation = any active "For Posting"/"Posted" transfer,
                    // excluding the transfer currently being edited.
                    $reserving = $unit->sourceStockTransfers
                        ->whereIn('status', ['For Posting', 'Posted'])
                        ->filter(fn ($t) => ! in_array($t->id, $excludeTransferIds))
                        ->first();
                    return array_merge($unit->makeHidden('sourceStockTransfers')->toArray(), [
                        'is_reserved' => $reserving !== null,
                        'reserved_in' => $reserving?->transfer_no,
                    ]);
                })
                ->values();
        }

        return response()->json([
            'asset' => $asset,
            'origin_location' => $originLocation,
            'soh' => $soh,
            'available_units' => $availableUnits,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'transfer_date'                              => 'required|date',
            'transfer_no'                               => 'nullable|string|max:255',
            'origin_location'                           => 'required|string|max:255',
            'destination_location'                      => 'required|string|max:255',
            'requested_by'                              => 'nullable|string|max:255',
            'memo_remarks'                              => 'nullable|string|max:2000',
            'status'                                    => 'required|in:For Posting,Posted',
            'asset_transfers'                           => 'required|array|min:1',
            'asset_transfers.*.asset_id'                => 'required|exists:assets,id',
            'asset_transfers.*.quantity'                => 'required|integer|min:1',
            'asset_transfers.*.entries'                 => 'required|array|min:1',
            'asset_transfers.*.entries.*.source_stock_in_id' => 'nullable|integer|exists:stock_ins,id',
            'asset_transfers.*.entries.*.serial_no'     => 'nullable|string',
            'asset_transfers.*.entries.*.barcode'       => 'required|string',
            'asset_transfers.*.entries.*.qrcode'        => 'required|string',
            'asset_transfers.*.entries.*.asset_type'    => 'required|string|in:New,Used',
            'asset_transfers.*.entries.*.is_allocation' => 'required|boolean',
            'asset_transfers.*.entries.*.warranty_months' => 'required|integer|min:0',
            'asset_transfers.*.entries.*.eol_months'    => 'required|integer|min:0',
            'asset_transfers.*.entries.*.cost'          => 'required|numeric|min:0',
            'asset_transfers.*.entries.*.price'         => 'required|numeric|min:0',
        ]);

        if ($validated['origin_location'] === $validated['destination_location']) {
            throw ValidationException::withMessages([
                'destination_location' => 'Destination must be different from origin.',
            ]);
        }

        if (empty($validated['transfer_no'])) {
            $validated['transfer_no'] = 'TRF-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        }

        $originLocation      = $this->normalizeStoreCode($validated['origin_location']);
        $destinationLocation = $this->normalizeStoreCode($validated['destination_location']);

        foreach ($validated['asset_transfers'] as $transfer) {
            foreach ($transfer['entries'] as $entry) {
                StockTransfer::create([
                    'transfer_date'        => $validated['transfer_date'],
                    'transfer_no'          => $validated['transfer_no'] ?? null,
                    'origin_location'      => $originLocation,
                    'destination_location' => $destinationLocation,
                    'requested_by'         => $validated['requested_by'] ?? null,
                    'memo_remarks'         => $validated['memo_remarks'] ?? null,
                    'status'               => $validated['status'],
                    'asset_id'             => $transfer['asset_id'],
                    'quantity'             => 1,
                    'created_by'           => $request->user()?->id,
                    'updated_by'           => $request->user()?->id,
                    ...Arr::only($entry, [
                        'source_stock_in_id',
                        'serial_no',
                        'barcode',
                        'qrcode',
                        'asset_type',
                        'is_allocation',
                        'warranty_months',
                        'eol_months',
                        'cost',
                        'price',
                    ]),
                ]);
            }
        }

        return redirect()->back()->with('success', 'Stock Transfer recorded successfully');
    }

    public function update(Request $request, StockTransfer $stockTransfer)
    {
        $validated = $request->validate([
            'transfer_date'                              => 'required|date',
            'transfer_no'                               => 'nullable|string|max:255',
            'origin_location'                           => 'required|string|max:255',
            'destination_location'                      => 'required|string|max:255',
            'requested_by'                              => 'nullable|string|max:255',
            'memo_remarks'                              => 'nullable|string|max:2000',
            'status'                                    => 'required|in:For Posting,Posted',
            'asset_transfers'                           => 'required|array|min:1',
            'asset_transfers.*.asset_id'                => 'required|exists:assets,id',
            'asset_transfers.*.quantity'                => 'required|integer|min:1',
            'asset_transfers.*.entries'                 => 'required|array|min:1',
            'asset_transfers.*.entries.*.source_stock_in_id' => 'nullable|integer|exists:stock_ins,id',
            'asset_transfers.*.entries.*.serial_no'     => 'nullable|string',
            'asset_transfers.*.entries.*.barcode'       => 'required|string',
            'asset_transfers.*.entries.*.qrcode'        => 'required|string',
            'asset_transfers.*.entries.*.asset_type'    => 'required|string|in:New,Used',
            'asset_transfers.*.entries.*.is_allocation' => 'required|boolean',
            'asset_transfers.*.entries.*.warranty_months' => 'required|integer|min:0',
            'asset_transfers.*.entries.*.eol_months'    => 'required|integer|min:0',
            'asset_transfers.*.entries.*.cost'          => 'required|numeric|min:0',
            'asset_transfers.*.entries.*.price'         => 'required|numeric|min:0',
        ]);

        $originLocation      = $this->normalizeStoreCode($validated['origin_location']);
        $destinationLocation = $this->normalizeStoreCode($validated['destination_location']);
        $transferNo          = $validated['transfer_no'] ?? $stockTransfer->transfer_no;

        // Replace all existing rows for this transfer with the new payload
        $originalCreatedBy = $stockTransfer->created_by;
        $this->groupedTransferRows($stockTransfer)->delete();

        foreach ($validated['asset_transfers'] as $transfer) {
            foreach ($transfer['entries'] as $entry) {
                StockTransfer::create([
                    'transfer_date'        => $validated['transfer_date'],
                    'transfer_no'          => $transferNo,
                    'origin_location'      => $originLocation,
                    'destination_location' => $destinationLocation,
                    'requested_by'         => $validated['requested_by'] ?? null,
                    'memo_remarks'         => $validated['memo_remarks'] ?? null,
                    'status'               => $validated['status'],
                    'asset_id'             => $transfer['asset_id'],
                    'quantity'             => 1,
                    'created_by'           => $originalCreatedBy,
                    'updated_by'           => auth()->id(),
                    ...Arr::only($entry, [
                        'source_stock_in_id',
                        'serial_no',
                        'barcode',
                        'qrcode',
                        'asset_type',
                        'is_allocation',
                        'warranty_months',
                        'eol_months',
                        'cost',
                        'price',
                    ]),
                ]);
            }
        }

        return redirect()->back()->with('success', 'Stock Transfer updated successfully');
    }

    public function destroy(Request $request, StockTransfer $stockTransfer)
    {
        if ($request->boolean('delete_group')) {
            $this->groupedTransferRows($stockTransfer)->delete();
        } else {
            $stockTransfer->delete();
        }

        return redirect()->back()->with('success', 'Stock Transfer deleted successfully');
    }

    public function post(Request $request, StockTransfer $stockTransfer)
    {
        abort_unless($request->user()->can('stock_transfers.post'), 403);

        $affectedRows = $this->groupedTransferRows($stockTransfer)->get();
        $now = now();
        $receivingNo = 'RCV-' . $now->format('Ymd') . '-' . strtoupper(Str::random(6));

        DB::transaction(function () use ($affectedRows, $request, $now, $receivingNo) {
            foreach ($affectedRows as $item) {
                $item->update([
                    'status' => 'Posted',
                    'posted_by' => $request->user()->name,
                    'posted_date' => $now,
                ]);

                InventoryTransaction::create([
                    'asset_id' => $item->asset_id,
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $item->id,
                    'location' => $item->origin_location,
                    'transaction_type' => 'Transfer Out',
                    'quantity' => -$item->quantity,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);

                // Auto-create pending Receiving row at the destination
                StockReceiving::create([
                    'stock_transfer_id'    => $item->id,
                    'receiving_no'         => $receivingNo,
                    'receiving_date'       => $now->toDateString(),
                    'origin_location'      => $item->origin_location,
                    'destination_location' => $item->destination_location,
                    'asset_id'             => $item->asset_id,
                    'source_stock_in_id'   => $item->source_stock_in_id,
                    'serial_no'            => $item->serial_no,
                    'barcode'              => $item->barcode,
                    'qrcode'               => $item->qrcode,
                    'asset_type'           => $item->asset_type,
                    'is_allocation'        => $item->is_allocation,
                    'warranty_months'      => $item->warranty_months,
                    'eol_months'           => $item->eol_months,
                    'cost'                 => $item->cost,
                    'price'                => $item->price,
                    'transferred_quantity' => $item->quantity,
                    'received_quantity'    => $item->quantity,
                    'condition'            => 'Good',
                    'status'               => 'For Receiving',
                    'created_by'           => $request->user()->id,
                    'updated_by'           => $request->user()->id,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Transfer posted. Origin inventory deducted and pending receiving created at destination.');
    }

    protected function groupedTransferRows(StockTransfer $stockTransfer)
    {
        $query = StockTransfer::with(['asset', 'creator:id,name,email', 'updater:id,name,email', 'sourceStockIn']);

        if ($stockTransfer->transfer_no !== null) {
            // Group by transfer_no — captures all assets in the same transaction
            $query->where('transfer_no', $stockTransfer->transfer_no)
                  ->whereDate('transfer_date', $stockTransfer->transfer_date)
                  ->where('origin_location', $stockTransfer->origin_location)
                  ->where('destination_location', $stockTransfer->destination_location)
                  ->where('status', $stockTransfer->status);
        } else {
            // Fallback for legacy null transfer_no: match by all header fields (no asset_id filter)
            // so multi-asset transfers created before auto-generation still load completely
            $query->whereNull('transfer_no')
                  ->whereDate('transfer_date', $stockTransfer->transfer_date)
                  ->where('origin_location', $stockTransfer->origin_location)
                  ->where('destination_location', $stockTransfer->destination_location)
                  ->where('status', $stockTransfer->status);

            if ($stockTransfer->requested_by !== null) {
                $query->where('requested_by', $stockTransfer->requested_by);
            } else {
                $query->whereNull('requested_by');
            }
        }

        return $query->orderBy('id');
    }

    protected function locationVariants(?string $code): array
    {
        if (! $code) {
            return [];
        }

        $variants = [$code];

        $store = Store::query()
            ->where('code', $code)
            ->orWhere('name', $code)
            ->first(['code', 'name']);

        if ($store) {
            if ($store->code && ! in_array($store->code, $variants)) {
                $variants[] = $store->code;
            }
            if ($store->name && ! in_array($store->name, $variants)) {
                $variants[] = $store->name;
            }
        }

        return $variants;
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
