<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LocatesInventoryUnits;
use App\Models\Asset;
use App\Models\InventoryTransaction;
use App\Models\StockIn;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use App\Models\TicketAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The prototype's "Inventory Management" work tool: one workspace with six tabs.
 * Asset Requests is the inventory side of the ticket→asset→procurement chain
 * (approve zero-stock purchases, advance the lifecycle); the other tabs surface
 * the real inventory modules' recent records with a link to manage them in full.
 */
class InventoryWorkspaceController extends Controller
{
    use LocatesInventoryUnits;

    /** Forward-only procurement transitions, keyed by action. */
    private const TRANSITIONS = [
        'approve' => ['from' => ['Pending Approval'], 'to' => 'Incoming'],
        'reserve' => ['from' => [null, ''], 'to' => 'For Setup'],   // stock available → reserve & release
        'receive' => ['from' => ['Incoming', 'Approved'], 'to' => 'Received'],
        'setup' => ['from' => ['Received'], 'to' => 'For Setup'],
        'deploy' => ['from' => ['For Setup'], 'to' => 'Deployed'],
    ];

    private function authorizeView(Request $request): void
    {
        abort_unless(
            $request->user()->can('assets.view')
            || $request->user()->can('stock_ins.view')
            || $request->user()->can('reports.inventory'),
            403
        );
    }

    public function index(Request $request)
    {
        $this->authorizeView($request);

        return Inertia::render('Inventory/Workspace', [
            'canManage' => (bool) $request->user()->can('assets.edit'),
            'assetRequests' => $this->assetRequests(),
            'assetMaster' => $this->recentAssets(),
            'stockIn' => $this->recentStockIn(),
            'assetMovement' => $this->recentTransfers(),
            'receivingStock' => $this->recentReceiving(),
            'assetManagement' => $this->inventoryByLocation(),
        ]);
    }

    /**
     * Advance a purchase-required tagged asset through its procurement lifecycle.
     */
    public function advance(Request $request, TicketAsset $ticketAsset)
    {
        abort_unless($request->user()->can('assets.edit'), 403);

        $validated = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::TRANSITIONS))],
        ]);

        $rule = self::TRANSITIONS[$validated['action']];
        $current = $ticketAsset->procurement_status;

        if (! in_array($current, $rule['from'], true)) {
            return redirect()->back()->with('error', 'That action is not valid for the current status.');
        }

        $ticketAsset->update([
            'procurement_status' => $rule['to'],
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', "Request moved to {$rule['to']}.");
    }

    /**
     * Purchase-required tagged assets across tickets (entity-scoped via the ticket),
     * with stock on hand and a derived status/next-action.
     */
    private function assetRequests(): array
    {
        $links = TicketAsset::query()
            ->where('purchase_required', true)
            ->whereHas('ticket') // applies the ticket's ActiveEntityScope
            ->with(['ticket:id,ticket_key,store_id', 'ticket.store:id,code', 'asset:id,item_code,brand,model,type'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return $links->map(function (TicketAsset $link) {
            $storeCode = $link->ticket?->store?->code;
            $isFixed = $link->asset?->type === 'Fixed';
            $soh = (! $isFixed && $storeCode) ? $this->stockOnHand($link->asset_id, $storeCode) : 0;
            $status = $link->procurement_status ?: ($soh > 0 ? 'Stock Available' : 'Pending Approval');

            // Next action offered for this row.
            $action = match (true) {
                $status === 'Pending Approval' => 'approve',
                $status === 'Stock Available' => 'reserve',
                in_array($status, ['Incoming', 'Approved'], true) => 'receive',
                $status === 'Received' => 'setup',
                $status === 'For Setup' => 'deploy',
                default => null,
            };

            return [
                'id' => $link->id,
                'ticket_id' => $link->ticket_id,
                'ticket_key' => $link->ticket?->ticket_key,
                'store' => $storeCode,
                'asset' => trim(($link->asset?->item_code ?? '') . ' ' . ($link->asset?->brand ?? '') . ' ' . ($link->asset?->model ?? '')),
                'condition' => $link->condition,
                'soh' => $soh,
                'status' => $status,
                'action' => $action,
            ];
        })->all();
    }

    private function recentAssets(): array
    {
        return [
            'total' => Asset::count(),
            'route' => 'assets.index',
            'rows' => Asset::orderByDesc('created_at')->limit(10)->get()
                ->map(fn (Asset $a) => [
                    'id' => $a->id,
                    'code' => $a->item_code,
                    'label' => trim(($a->brand ?? '') . ' ' . ($a->model ?? '')),
                    'meta' => $a->type,
                ])->all(),
        ];
    }

    private function recentStockIn(): array
    {
        return [
            'total' => StockIn::count(),
            'route' => 'stock-ins.index',
            'rows' => StockIn::orderByDesc('receive_date')->limit(10)->get()
                ->map(fn (StockIn $s) => [
                    'id' => $s->id,
                    'code' => $s->dr_no ?: ('SI-' . $s->id),
                    'label' => $s->destination_location,
                    'meta' => $s->status,
                ])->all(),
        ];
    }

    private function recentTransfers(): array
    {
        return [
            'total' => StockTransfer::count(),
            'route' => 'stock-transfers.index',
            'rows' => StockTransfer::orderByDesc('transfer_date')->limit(10)->get()
                ->map(fn (StockTransfer $t) => [
                    'id' => $t->id,
                    'code' => $t->transfer_no ?: ('MOV-' . $t->id),
                    'label' => trim(($t->origin_location ?? '') . ' → ' . ($t->destination_location ?? '')),
                    'meta' => $t->status,
                ])->all(),
        ];
    }

    private function recentReceiving(): array
    {
        return [
            'total' => StockReceiving::count(),
            'route' => 'stock-receivings.index',
            'rows' => StockReceiving::orderByDesc('receiving_date')->limit(10)->get()
                ->map(fn (StockReceiving $r) => [
                    'id' => $r->id,
                    'code' => $r->receiving_no ?: ('RCV-' . $r->id),
                    'label' => $r->destination_location,
                    'meta' => $r->serial_no ?: $r->barcode,
                ])->all(),
        ];
    }

    /** Sum the valid inventory ledger quantity for an asset at a store code. */
    private function stockOnHand(int $assetId, string $storeCode): int
    {
        return (int) InventoryTransaction::query()
            ->validInventoryLedger('inventory_transactions', 'workspace_asset_soh')
            ->where('inventory_transactions.asset_id', $assetId)
            ->whereIn('inventory_transactions.location', $this->locationVariants($storeCode))
            ->sum('inventory_transactions.quantity');
    }

    private function inventoryByLocation(): array
    {
        $rows = InventoryTransaction::query()
            ->selectRaw('location, SUM(quantity) as soh')
            ->groupBy('location')
            ->havingRaw('SUM(quantity) <> 0')
            ->orderByRaw('SUM(quantity) DESC')
            ->limit(12)
            ->get();

        return [
            'route' => 'reports.inventory',
            'total_locations' => $rows->count(),
            'rows' => $rows->map(fn ($r) => [
                'location' => $r->location,
                'soh' => (int) $r->soh,
            ])->all(),
        ];
    }
}
