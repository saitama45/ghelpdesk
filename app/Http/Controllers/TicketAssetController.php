<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LocatesInventoryUnits;
use App\Models\Asset;
use App\Models\InventoryTransaction;
use App\Models\StockIn;
use App\Models\Ticket;
use App\Models\TicketAsset;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketAssetController extends Controller
{
    use LocatesInventoryUnits;

    public const TRANSACTION_TYPES = ['PM', 'Repair', 'Stock Out', 'Stock In', 'Deployment'];

    /** Asset condition options (LINK Hub chain). */
    public const CONDITIONS = ['Healthy', 'For Checking', 'Not Working', 'For Replacement'];

    /** Conditions for which a "For Purchase" replacement is allowed. */
    public const PURCHASABLE_CONDITIONS = ['Not Working', 'For Replacement'];

    /** Procurement lifecycle beyond the initial Pending Approval (advanced states preserved on update). */
    public const ADVANCED_PROCUREMENT = ['Approved', 'Incoming', 'Received', 'For Setup', 'Deployed'];

    /**
     * List assets/units tagged to a ticket, including current SOH at the ticket's store
     * (for Consumables) — Fixed units carry their own serial/barcode.
     */
    public function index(Ticket $ticket)
    {
        $storeCode = $ticket->store?->code;

        $tagged = $ticket->taggedAssets()
            ->with(['asset.category', 'asset.subCategory', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TicketAsset $link) => $this->presentLink($link, $storeCode));

        return response()->json([
            'ticket_assets' => $tagged,
            'store_code' => $storeCode,
        ]);
    }

    /**
     * Tag an asset/unit to a ticket.
     */
    public function store(Request $request, Ticket $ticket)
    {
        abort_unless($request->user()->can('tickets.edit'), 403);

        $validated = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'stock_in_id' => ['nullable', 'integer', 'exists:stock_ins,id'],
            'transaction_type' => ['required', Rule::in(self::TRANSACTION_TYPES)],
            'condition' => ['nullable', Rule::in(self::CONDITIONS)],
            'purchase_required' => ['nullable', 'boolean'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $asset = Asset::findOrFail($validated['asset_id']);
        $isFixed = $asset->type === 'Fixed';

        $stockInId = null;
        $serialNo = null;
        $barcode = null;

        if ($isFixed) {
            // Fixed assets must be tagged by a specific physical unit.
            if (empty($validated['stock_in_id'])) {
                throw ValidationException::withMessages([
                    'stock_in_id' => 'A specific unit (serial/barcode) is required for fixed assets.',
                ]);
            }

            $unit = StockIn::where('id', $validated['stock_in_id'])
                ->where('asset_id', $asset->id)
                ->first();

            if (! $unit) {
                throw ValidationException::withMessages([
                    'stock_in_id' => 'The selected unit does not belong to this asset.',
                ]);
            }

            // Snapshot identity server-side (do not trust client values).
            $stockInId = $unit->id;
            $serialNo = $unit->serial_no;
            $barcode = $unit->barcode;
        }

        // De-duplication: Fixed → per unit; Consumable → per asset (type-level).
        $duplicate = $ticket->taggedAssets()
            ->when($isFixed,
                fn ($q) => $q->where('stock_in_id', $stockInId),
                fn ($q) => $q->whereNull('stock_in_id')->where('asset_id', $asset->id)
            )
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => $isFixed
                    ? 'This unit is already tagged to the ticket.'
                    : 'This asset is already tagged to the ticket.',
            ], 422);
        }

        [$condition, $purchaseRequired, $procurementStatus] = $this->deriveProcurement(
            $asset,
            $ticket->store?->code,
            $isFixed,
            $validated['condition'] ?? null,
            (bool) ($validated['purchase_required'] ?? false),
            null
        );

        $link = $ticket->taggedAssets()->create([
            'asset_id' => $asset->id,
            'stock_in_id' => $stockInId,
            'serial_no' => $serialNo,
            'barcode' => $barcode,
            'transaction_type' => $validated['transaction_type'],
            'condition' => $condition,
            'purchase_required' => $purchaseRequired,
            'procurement_status' => $procurementStatus,
            'quantity' => $validated['quantity'] ?? 1,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Asset tagged successfully.',
            'ticket_asset' => $this->presentLink(
                $link->fresh(['asset.category', 'asset.subCategory', 'creator:id,name']),
                $ticket->store?->code
            ),
        ], 201);
    }

    /**
     * Update a tagged asset's transaction type, quantity, or notes (unit identity is immutable).
     */
    public function update(Request $request, Ticket $ticket, TicketAsset $ticketAsset)
    {
        abort_unless($request->user()->can('tickets.edit'), 403);
        abort_unless($ticketAsset->ticket_id === $ticket->id, 404);

        $validated = $request->validate([
            'transaction_type' => ['required', Rule::in(self::TRANSACTION_TYPES)],
            'condition' => ['nullable', Rule::in(self::CONDITIONS)],
            'purchase_required' => ['nullable', 'boolean'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        [$condition, $purchaseRequired, $procurementStatus] = $this->deriveProcurement(
            $ticketAsset->asset,
            $ticket->store?->code,
            $ticketAsset->asset?->type === 'Fixed',
            $validated['condition'] ?? null,
            (bool) ($validated['purchase_required'] ?? false),
            $ticketAsset->procurement_status
        );

        $ticketAsset->update([
            'transaction_type' => $validated['transaction_type'],
            'condition' => $condition,
            'purchase_required' => $purchaseRequired,
            'procurement_status' => $procurementStatus,
            'quantity' => $validated['quantity'] ?? 1,
            'notes' => $validated['notes'] ?? null,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Asset updated successfully.',
            'ticket_asset' => $this->presentLink(
                $ticketAsset->fresh(['asset.category', 'asset.subCategory', 'creator:id,name']),
                $ticket->store?->code
            ),
        ]);
    }

    /**
     * Remove an asset tag from a ticket.
     */
    public function destroy(Request $request, Ticket $ticket, TicketAsset $ticketAsset)
    {
        abort_unless($request->user()->can('tickets.edit'), 403);
        abort_unless($ticketAsset->ticket_id === $ticket->id, 404);

        $ticketAsset->delete();

        return response()->json([
            'message' => 'Asset removed successfully.',
        ]);
    }

    /**
     * Resolve condition, purchase flag, and procurement status for a tag.
     *
     * Rules (LINK Hub chain):
     *  - "For Purchase" only applies to Not Working / For Replacement conditions.
     *  - When purchasing: stock on hand > 0 → reserve path (no procurement row,
     *    status null); stock 0 (or a Fixed unit) → 'Pending Approval'.
     *  - An already-advanced lifecycle status (Approved…Deployed) is preserved.
     *
     * @return array{0: ?string, 1: bool, 2: ?string} [condition, purchaseRequired, procurementStatus]
     */
    private function deriveProcurement(?Asset $asset, ?string $storeCode, bool $isFixed, ?string $condition, bool $purchaseRequired, ?string $existingStatus): array
    {
        $purchaseRequired = $purchaseRequired && in_array($condition, self::PURCHASABLE_CONDITIONS, true);

        if (! $purchaseRequired) {
            return [$condition, false, null];
        }

        // Preserve an in-flight procurement lifecycle rather than resetting it.
        if ($existingStatus && in_array($existingStatus, self::ADVANCED_PROCUREMENT, true)) {
            return [$condition, true, $existingStatus];
        }

        $stock = (! $isFixed && $asset && $storeCode)
            ? $this->stockOnHand($asset->id, $storeCode)
            : 0;

        // Stock available → reserve & release (no procurement). Zero stock → needs approval.
        return [$condition, true, $stock > 0 ? null : 'Pending Approval'];
    }

    private function presentLink(TicketAsset $link, ?string $storeCode): array
    {
        $isFixed = $link->asset?->type === 'Fixed';

        return [
            'id' => $link->id,
            'asset_id' => $link->asset_id,
            'stock_in_id' => $link->stock_in_id,
            'serial_no' => $link->serial_no,
            'barcode' => $link->barcode,
            'transaction_type' => $link->transaction_type,
            'condition' => $link->condition,
            'purchase_required' => (bool) $link->purchase_required,
            'procurement_status' => $link->procurement_status,
            'quantity' => $link->quantity,
            'notes' => $link->notes,
            'created_at' => $link->created_at,
            'created_by_name' => $link->creator?->name,
            'asset' => $link->asset ? [
                'id' => $link->asset->id,
                'item_code' => $link->asset->item_code,
                'brand' => $link->asset->brand,
                'model' => $link->asset->model,
                'description' => $link->asset->description,
                'type' => $link->asset->type,
                'category' => $link->asset->category?->name,
                'sub_category' => $link->asset->subCategory?->name,
            ] : null,
            // SOH only meaningful for Consumables (type-level); Fixed units are identified by serial.
            'soh_at_store' => (! $isFixed && $storeCode)
                ? $this->stockOnHand($link->asset_id, $storeCode)
                : null,
        ];
    }

    /**
     * Sum the valid inventory ledger quantity for an asset at a store code.
     */
    private function stockOnHand(int $assetId, string $storeCode): int
    {
        return (int) InventoryTransaction::query()
            ->validInventoryLedger('inventory_transactions', 'ticket_asset_soh')
            ->where('inventory_transactions.asset_id', $assetId)
            ->whereIn('inventory_transactions.location', $this->locationVariants($storeCode))
            ->sum('inventory_transactions.quantity');
    }
}
