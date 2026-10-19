<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StockReceivingController extends Controller
{
    /** Keep in step with DR_IMAGE_MAX_MB in StockReceiving/Index.vue. */
    private const DR_IMAGE_MAX_KB = 5120;

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

        // Lines in transit (reported unserved, see StockReceiving::isInTransit). Raw SQL, so
        // every column goes through the grammar: `condition` is reserved on some drivers.
        $grammar      = DB::getQueryGrammar();
        $inTransitSql = sprintf(
            "SUM(CASE WHEN %s = 'For Receiving' AND %s = 'Missing' AND %s = 0 THEN 1 ELSE 0 END) as in_transit_count",
            $grammar->wrap('status'),
            $grammar->wrap('condition'),
            $grammar->wrap('received_quantity')
        );

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
                DB::raw($inTransitSql),
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
            // The browser shrinks phone photos before upload; this cap is only a backstop.
            'dr_image'                                    => 'nullable|image|mimes:jpg,jpeg,png,webp|max:'.self::DR_IMAGE_MAX_KB,
            'remove_dr_image'                             => 'nullable|boolean',
        ], [
            'dr_image.max' => 'The DR image may not be larger than '.(self::DR_IMAGE_MAX_KB / 1024).' MB.',
        ]);

        $relatedRows = $this->groupedReceivingRows($stockReceiving)->get()->keyBy('id');

        // One DR photo per receiving: the same path is stamped on every row in the group.
        $currentPath = $relatedRows->pluck('dr_image_path')->filter()->first();
        $drImagePath = $currentPath;
        if ($request->hasFile('dr_image')) {
            $drImagePath = str_replace('\\', '/', $request->file('dr_image')->store('stock-receiving-dr', 'local'));
        } elseif ($request->boolean('remove_dr_image')) {
            $drImagePath = null;
        }
        if ($currentPath && $currentPath !== $drImagePath) {
            Storage::disk('local')->delete($currentPath);
        }

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
                    'dr_image_path'     => $drImagePath,
                    'updated_by'        => $request->user()?->id,
                ]);
            }
        }

        return redirect()->back()->with('success', 'Receiving Stock updated successfully');
    }

    /**
     * The DR photo sits on the private disk (it is an internal document), so it is
     * served through this authenticated route rather than the public /serve-storage.
     */
    public function drImage(StockReceiving $stockReceiving)
    {
        $path = $stockReceiving->dr_image_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function destroy(Request $request, StockReceiving $stockReceiving)
    {
        abort_unless($stockReceiving->status === 'For Receiving', 422, 'Cannot delete a posted receiving.');

        // An in-transit line carries unserved history and a deducted origin; deleting it
        // would strand both. The source resolves it with Return to Source or Write Off.
        $targets = $request->boolean('delete_group')
            ? $this->receivingGroupQuery($stockReceiving)->get()
            : collect([$stockReceiving]);
        abort_if(
            $targets->contains(fn (StockReceiving $row) => $row->isInTransit()),
            422,
            'This receiving has unserved lines in transit. Return them to source or write them off instead of deleting.'
        );

        if ($request->boolean('delete_group')) {
            $this->groupedReceivingRows($stockReceiving)->delete();
        } else {
            $stockReceiving->delete();
        }

        return redirect()->back()->with('success', 'Receiving Stock deleted successfully');
    }

    /**
     * Lines with a received quantity are posted: the destination is credited and their
     * transfers close as Received. A line received as 0 is NOT closed: nothing arrived, so
     * it stays open in transit (StockReceiving::isInTransit) with one 'Receiving Unserved'
     * history row and its transfer still Posted, until the source returns it to stock or
     * writes it off. A late arrival is simply received on a later post.
     */
    public function post(Request $request, StockReceiving $stockReceiving)
    {
        abort_unless($request->user()->can('stock_receivings.post'), 403);
        abort_unless($stockReceiving->status === 'For Receiving', 422, 'Receiving already posted.');

        $now      = now();
        $userName = $request->user()->name;
        $userId   = $request->user()->id;

        [$received, $inTransit] = DB::transaction(function () use ($stockReceiving, $now, $userName, $userId) {
            $transferIds = [];
            $received    = 0;
            $inTransit   = 0;

            // Locked re-read: linkportal works the same lines concurrently.
            foreach ($this->receivingGroupQuery($stockReceiving)->lockForUpdate()->get() as $row) {
                if ($row->received_quantity <= 0) {
                    $this->markUnserved($row, $userName, $userId);
                    $inTransit++;

                    continue;
                }

                $row->update([
                    'status'      => 'Received',
                    // A late arrival of a line reported unserved is no longer missing.
                    'condition'   => $row->condition === 'Missing' ? 'Good' : $row->condition,
                    'received_by' => $userName,
                    'received_at' => $now,
                    'updated_by'  => $userId,
                ]);

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

                if ($row->stock_transfer_id) {
                    $transferIds[$row->stock_transfer_id] = true;
                }
                $received++;
            }

            // Only transfers whose piece actually arrived are Received; the rest stay Posted.
            if (! empty($transferIds)) {
                StockTransfer::whereIn('id', array_keys($transferIds))->update([
                    'status'      => 'Received',
                    'received_by' => $userName,
                    'received_at' => $now,
                    'updated_by'  => $userId,
                ]);
            }

            return [$received, $inTransit];
        });

        $message = $inTransit === 0
            ? 'Receiving Stock posted. Destination inventory credited.'
            : "Receiving posted: {$received} unit(s) received, {$inTransit} unserved unit(s) left in transit.";

        return redirect()->back()->with('success', $message);
    }

    /**
     * Source side: an unserved line was never shipped (or turned up at the source), so it
     * goes back to origin stock and the line closes as Declined.
     */
    public function returnToSource(Request $request, StockReceiving $stockReceiving)
    {
        [$reason, $ids] = $this->validateInTransitResolution($request);
        $now      = now();
        $userName = $request->user()->name;
        $userId   = $request->user()->id;

        $count = DB::transaction(function () use ($stockReceiving, $ids, $reason, $now, $userName, $userId) {
            $lines = $this->lockedInTransitLines($stockReceiving, $ids);
            abort_if($lines->isEmpty(), 422, 'Only lines reported unserved (in transit) can be returned to source.');

            foreach ($lines as $row) {
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
                    'received_by'       => $userName,
                    'received_at'       => $now,
                    'updated_by'        => $userId,
                ]);
            }

            $transferIds = $lines->pluck('stock_transfer_id')->filter()->unique()->values();
            if ($transferIds->isNotEmpty()) {
                StockTransfer::whereIn('id', $transferIds)->update([
                    'status'      => 'Declined',
                    'received_by' => null,
                    'received_at' => null,
                    'updated_by'  => $userId,
                ]);
            }

            return $lines->count();
        });

        return redirect()->back()->with(
            'success',
            "{$count} unserved unit(s) returned to {$stockReceiving->origin_location}. Origin inventory credited back."
        );
    }

    /**
     * Source side: an unserved line is lost in transit. The origin stays deducted, the line
     * closes as Received with nothing received, and the piece is on no shelf from now on.
     */
    public function writeOff(Request $request, StockReceiving $stockReceiving)
    {
        [$reason, $ids] = $this->validateInTransitResolution($request);
        $now      = now();
        $userName = $request->user()->name;
        $userId   = $request->user()->id;

        $count = DB::transaction(function () use ($stockReceiving, $ids, $reason, $now, $userName, $userId) {
            $lines = $this->lockedInTransitLines($stockReceiving, $ids);
            abort_if($lines->isEmpty(), 422, 'Only lines reported unserved (in transit) can be written off.');

            foreach ($lines as $row) {
                InventoryTransaction::create([
                    'asset_id'         => $row->asset_id,
                    'reference_type'   => StockReceiving::class,
                    'reference_id'     => $row->id,
                    'location'         => $row->origin_location,
                    'transaction_type' => 'Unserved Written Off',
                    'quantity'         => 0,
                    'created_by'       => $userId,
                    'updated_by'       => $userId,
                ]);

                $row->update([
                    'status'            => 'Received',
                    'received_quantity' => 0,
                    'condition'         => 'Missing',
                    'remarks'           => $reason,
                    'received_by'       => $userName,
                    'received_at'       => $now,
                    'updated_by'        => $userId,
                ]);
            }

            $transferIds = $lines->pluck('stock_transfer_id')->filter()->unique()->values();
            if ($transferIds->isNotEmpty()) {
                StockTransfer::whereIn('id', $transferIds)->update([
                    'status'      => 'Received',
                    'received_by' => $userName,
                    'received_at' => $now,
                    'updated_by'  => $userId,
                ]);
            }

            return $lines->count();
        });

        return redirect()->back()->with(
            'success',
            "{$count} unserved unit(s) written off as lost in transit. {$stockReceiving->origin_location} stays deducted."
        );
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

    /**
     * Leave a line that received nothing open in transit, logging 'Receiving Unserved' once.
     * A line already reported keeps its reporter and reason.
     */
    private function markUnserved(StockReceiving $row, string $userName, ?int $userId): void
    {
        $row->update([
            'condition'         => 'Missing',
            'received_quantity' => 0,
            'received_by'       => $row->isInTransit() && $row->received_by ? $row->received_by : $userName,
            'received_at'       => null,
            'updated_by'        => $userId,
        ]);

        $alreadyLogged = InventoryTransaction::query()
            ->where('reference_type', StockReceiving::class)
            ->where('reference_id', $row->id)
            ->where('transaction_type', 'Receiving Unserved')
            ->exists();

        if (! $alreadyLogged) {
            InventoryTransaction::create([
                'asset_id'         => $row->asset_id,
                'reference_type'   => StockReceiving::class,
                'reference_id'     => $row->id,
                'location'         => $row->destination_location,
                'transaction_type' => 'Receiving Unserved',
                'quantity'         => 0,
                'created_by'       => $userId,
                'updated_by'       => $userId,
            ]);
        }
    }

    /**
     * Shared guard + input for Return to Source / Write Off: a required reason, and an
     * optional `ids` subset of the group's in-transit lines.
     *
     * @return array{0: string, 1: array<int, int>|null}
     */
    private function validateInTransitResolution(Request $request): array
    {
        abort_unless($request->user()->can('stock_receivings.post'), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'ids'    => ['sometimes', 'nullable', 'array'],
            'ids.*'  => ['integer'],
        ]);

        $reason = trim($validated['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'The reason field is required.']);
        }

        $ids = isset($validated['ids']) ? array_map('intval', $validated['ids']) : null;

        return [$reason, $ids];
    }

    /**
     * The anchor's group lines that are still in transit, re-read under lock (call inside
     * the transaction), narrowed to `$ids` when given.
     */
    private function lockedInTransitLines(StockReceiving $stockReceiving, ?array $ids): Collection
    {
        return $this->receivingGroupQuery($stockReceiving)
            ->lockForUpdate()
            ->get()
            ->filter(fn (StockReceiving $row) => $row->isInTransit()
                && ($ids === null || in_array($row->id, $ids, true)))
            ->values();
    }

    protected function groupedReceivingRows(StockReceiving $stockReceiving)
    {
        return $this->receivingGroupQuery($stockReceiving)
            ->with(['asset', 'pack', 'creator:id,name,email', 'updater:id,name,email', 'sourceStockIn', 'stockTransfer']);
    }

    /** One receiving (same no./date/route/status) as a plain query, without eager loads. */
    protected function receivingGroupQuery(StockReceiving $stockReceiving)
    {
        $query = StockReceiving::query();

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
