<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StockReceivingController extends Controller
{
    public function index(Request $request)
    {
        $search       = trim((string) $request->input('search', ''));
        $perPage      = max(1, min(200, (int) $request->input('per_page', 10)));
        $statuses     = array_values(array_filter((array) $request->input('statuses', [])));
        $categoryId   = $request->input('category_id');
        $destination  = $request->input('destination_location');

        $applyFilters = function ($query) use ($search, $statuses, $categoryId, $destination) {
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('receiving_no', 'like', "%{$search}%")
                      ->orWhere('origin_location', 'like', "%{$search}%")
                      ->orWhere('destination_location', 'like', "%{$search}%");
                });
            }
            if (! empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
            if ($categoryId) {
                $query->whereHas('asset', fn ($q) => $q->where('category_id', $categoryId));
            }
            if ($destination) {
                $query->where('destination_location', $destination);
            }
        };

        $query = StockReceiving::with(['creator:id,name,email', 'updater:id,name,email'])
            ->select(
                'receiving_date',
                'receiving_no',
                'origin_location',
                'destination_location',
                'status',
                'received_by',
                'received_at',
                DB::raw('SUM(received_quantity) as quantity'),
                DB::raw('SUM(transferred_quantity) as transferred_quantity'),
                DB::raw('COUNT(*) as record_count'),
                DB::raw('COUNT(DISTINCT asset_id) as asset_count'),
                DB::raw('MAX(id) as id'),
                DB::raw('MAX(created_at) as created_at'),
                DB::raw('MAX(updated_at) as updated_at')
            )
            ->groupBy(
                'receiving_date',
                'receiving_no',
                'origin_location',
                'destination_location',
                'status',
                'received_by',
                'received_at'
            );

        $applyFilters($query);

        $flatBase = StockReceiving::query();
        $applyFilters($flatBase);

        $summary = [
            'total_qty'         => (clone $flatBase)->sum('received_quantity'),
            'received_qty'      => (clone $flatBase)->where('status', 'Received')->sum('received_quantity'),
            'for_receiving_qty' => (clone $flatBase)->where('status', 'For Receiving')->sum('received_quantity'),
            'total_records'     => (clone $flatBase)->count(),
        ];

        $stockReceivings = $query->latest('receiving_date')->paginate($perPage);

        return Inertia::render('StockReceiving/Index', [
            'stockReceivings' => $stockReceivings,
            'stores'          => Store::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'categories'      => Category::orderBy('name')->get(['id', 'name']),
            'destinations'    => StockReceiving::whereNotNull('destination_location')
                ->distinct()
                ->orderBy('destination_location')
                ->pluck('destination_location'),
            'summary'         => $summary,
            'filters'         => $request->only(['category_id', 'destination_location', 'statuses', 'search']),
        ]);
    }

    public function show(StockReceiving $stockReceiving)
    {
        return response()->json(
            $this->groupedReceivingRows($stockReceiving)->get()
        );
    }

    public function update(Request $request, StockReceiving $stockReceiving)
    {
        $validated = $request->validate([
            'remarks'                                     => 'nullable|string|max:2000',
            'asset_transfers'                             => 'required|array|min:1',
            'asset_transfers.*.asset_id'                  => 'required|exists:assets,id',
            'asset_transfers.*.entries'                   => 'required|array|min:1',
            'asset_transfers.*.entries.*.id'              => 'required|integer|exists:stock_receivings,id',
            'asset_transfers.*.entries.*.received_quantity' => 'required|integer|min:0',
            'asset_transfers.*.entries.*.condition'       => 'required|string|in:Good,Damaged,Missing',
            'asset_transfers.*.entries.*.damage_notes'    => 'nullable|string|max:1000',
        ]);

        $relatedRows = $this->groupedReceivingRows($stockReceiving)->get()->keyBy('id');

        foreach ($validated['asset_transfers'] as $transfer) {
            foreach ($transfer['entries'] as $entry) {
                $row = $relatedRows->get($entry['id']);
                if (! $row) {
                    continue;
                }
                $row->update([
                    'received_quantity' => min($entry['received_quantity'], $row->transferred_quantity),
                    'condition'         => $entry['condition'],
                    'damage_notes'      => $entry['damage_notes'] ?? null,
                    'remarks'           => $validated['remarks'] ?? null,
                    'updated_by'        => $request->user()?->id,
                ]);
            }
        }

        return redirect()->back()->with('success', 'Receiving Stock updated successfully');
    }

    public function destroy(Request $request, StockReceiving $stockReceiving)
    {
        abort_unless($stockReceiving->status === 'For Receiving', 422, 'Cannot delete a posted receiving.');

        if ($request->boolean('delete_group')) {
            $this->groupedReceivingRows($stockReceiving)->delete();
        } else {
            $stockReceiving->delete();
        }

        return redirect()->back()->with('success', 'Receiving Stock deleted successfully');
    }

    public function post(Request $request, StockReceiving $stockReceiving)
    {
        abort_unless($request->user()->can('stock_receivings.post'), 403);
        abort_unless($stockReceiving->status === 'For Receiving', 422, 'Receiving already posted.');

        $affectedRows = $this->groupedReceivingRows($stockReceiving)->get();
        $now          = now();
        $userName     = $request->user()->name;
        $userId       = $request->user()->id;

        DB::transaction(function () use ($affectedRows, $now, $userName, $userId) {
            $transferIds = [];

            foreach ($affectedRows as $row) {
                $row->update([
                    'status'      => 'Received',
                    'received_by' => $userName,
                    'received_at' => $now,
                    'updated_by'  => $userId,
                ]);

                if ($row->received_quantity > 0) {
                    InventoryTransaction::create([
                        'asset_id'         => $row->asset_id,
                        'reference_type'   => StockReceiving::class,
                        'reference_id'     => $row->id,
                        'location'         => $row->destination_location,
                        'transaction_type' => 'Transfer In',
                        'quantity'         => $row->received_quantity,
                        'created_by'       => $userId,
                        'updated_by'       => $userId,
                    ]);
                }

                if ($row->stock_transfer_id) {
                    $transferIds[$row->stock_transfer_id] = true;
                }
            }

            // Mark corresponding StockTransfer rows as Received
            if (! empty($transferIds)) {
                StockTransfer::whereIn('id', array_keys($transferIds))->update([
                    'status'      => 'Received',
                    'received_by' => $userName,
                    'received_at' => $now,
                    'updated_by'  => $userId,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Receiving Stock posted. Destination inventory credited.');
    }

    public function decline(Request $request, StockReceiving $stockReceiving)
    {
        abort_unless($request->user()->can('stock_receivings.post'), 403);
        abort_unless($stockReceiving->status === 'For Receiving', 422, 'Only pending receiving records can be declined.');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $affectedRows = $this->groupedReceivingRows($stockReceiving)->get();
        $now          = now();
        $userId       = $request->user()->id;
        $reason       = trim($validated['reason']);

        if ($reason === '') {
            return redirect()->back()->withErrors(['reason' => 'The decline reason field is required.']);
        }

        DB::transaction(function () use ($affectedRows, $now, $userId, $reason) {
            $transferIds = [];

            foreach ($affectedRows as $row) {
                InventoryTransaction::create([
                    'asset_id'         => $row->asset_id,
                    'reference_type'   => StockReceiving::class,
                    'reference_id'     => $row->id,
                    'location'         => $row->origin_location,
                    'transaction_type' => 'Receiving Declined',
                    'quantity'         => $row->transferred_quantity,
                    'created_by'       => $userId,
                    'updated_by'       => $userId,
                ]);

                $row->update([
                    'status'            => 'Declined',
                    'received_quantity' => 0,
                    'remarks'           => $reason,
                    'received_by'       => null,
                    'received_at'       => null,
                    'updated_by'        => $userId,
                    'updated_at'        => $now,
                ]);

                if ($row->stock_transfer_id) {
                    $transferIds[$row->stock_transfer_id] = true;
                }
            }

            if (! empty($transferIds)) {
                StockTransfer::whereIn('id', array_keys($transferIds))->update([
                    'status'      => 'Declined',
                    'received_by' => null,
                    'received_at' => null,
                    'updated_by'  => $userId,
                    'updated_at'  => $now,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Receiving declined. Inventory has been returned to the origin location.');
    }

    protected function groupedReceivingRows(StockReceiving $stockReceiving)
    {
        $query = StockReceiving::with(['asset', 'creator:id,name,email', 'updater:id,name,email', 'sourceStockIn', 'stockTransfer']);

        if ($stockReceiving->receiving_no !== null) {
            $query->where('receiving_no', $stockReceiving->receiving_no)
                  ->whereDate('receiving_date', $stockReceiving->receiving_date)
                  ->where('origin_location', $stockReceiving->origin_location)
                  ->where('destination_location', $stockReceiving->destination_location)
                  ->where('status', $stockReceiving->status);
        } else {
            $query->whereNull('receiving_no')
                  ->whereDate('receiving_date', $stockReceiving->receiving_date)
                  ->where('origin_location', $stockReceiving->origin_location)
                  ->where('destination_location', $stockReceiving->destination_location)
                  ->where('status', $stockReceiving->status);
        }

        return $query->orderBy('id');
    }
}
