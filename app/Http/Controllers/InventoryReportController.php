<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LocatesInventoryUnits;
use App\Models\Asset;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\StampRedemption;
use App\Models\StampRedemptionUnit;
use App\Models\StockIn;
use App\Models\StockReceiving;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\TicketAsset;
use App\Models\TicketComment;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InventoryReportController extends Controller implements HasMiddleware
{
    use LocatesInventoryUnits;

    private const EXCLUDED_REPORT_LOCATIONS = ['SUPPLIER'];

    public static function middleware(): array
    {
        return [
            new Middleware('can:reports.inventory', only: ['index', 'movement', 'units']),
        ];
    }

    public function index(Request $request)
    {
        $query = $this->inventoryRowsQuery($request);
        $activeCompanyId = CompanyContext::activeCompanyId();

        $assets = (clone $query)
            ->orderBy('location')
            ->orderBy('assets.item_code')
            ->paginate($request->integer('per_page', 10))
            ->withQueryString();

        $locationSummaries = DB::query()
            ->fromSub((clone $query)->toBase(), 'inventory_rows')
            ->select(
                'location',
                DB::raw('COUNT(*) as item_count'),
                DB::raw('SUM(soh) as total_soh'),
                DB::raw('SUM(total_value) as total_value')
            )
            ->groupBy('location')
            ->orderBy('location')
            ->get();

        $postedTransactionsBase = fn () => $this->reportLedgerQuery();

        $internalStockByAsset = $postedTransactionsBase()
            ->select('inventory_transactions.asset_id', DB::raw('SUM(inventory_transactions.quantity) as soh'))
            ->groupBy('inventory_transactions.asset_id');

        // Summary Data for Cards
        $summary = [
            'total_items' => Asset::count(),
            'total_soh' => $postedTransactionsBase()->sum('inventory_transactions.quantity') ?? 0,
            'total_inventory_value' => $postedTransactionsBase()
                ->join('assets', 'inventory_transactions.asset_id', '=', 'assets.id')
                ->selectRaw('SUM(inventory_transactions.quantity * assets.cost) as total')
                ->value('total') ?? 0,
            'out_of_stock_count' => Asset::leftJoinSub($internalStockByAsset, 'internal_stock', function ($join) {
                $join->on('assets.id', '=', 'internal_stock.asset_id');
            })
                ->whereRaw('COALESCE(internal_stock.soh, 0) <= 0')
                ->count(),
        ];

        return Inertia::render('Reports/Inventory', [
            'assets' => $assets,
            'locationSummaries' => $locationSummaries,
            'categories' => Category::query()
                ->when($activeCompanyId, fn ($query) => $query->where('company_id', $activeCompanyId))
                ->orderBy('name')
                ->get(),
            'brands' => Asset::whereNotNull('brand')->distinct()->pluck('brand'),
            'locations' => $postedTransactionsBase()
                ->whereNotNull('inventory_transactions.location')
                ->distinct()
                ->orderBy('inventory_transactions.location')
                ->pluck('inventory_transactions.location'),
            'summary' => $summary,
            'filters' => $request->only(['category_id', 'sub_category_id', 'type', 'brand', 'location', 'stock_status', 'search'])
        ]);
    }

    public function history(Asset $asset, Request $request)
    {
        $location = $request->location;

        $history = InventoryTransaction::query()
            ->validInventoryLedger('inventory_transactions', 'history_valid')
            ->where('inventory_transactions.asset_id', $asset->id)
            ->where('inventory_transactions.location', $location)
            ->leftJoin('stock_ins as stock_in_history', function ($join) {
                $join->on('inventory_transactions.reference_id', '=', 'stock_in_history.id')
                     ->where('inventory_transactions.reference_type', '=', StockIn::class);
            })
            ->leftJoin('stock_transfers as stock_transfer_history', function ($join) {
                $join->on('inventory_transactions.reference_id', '=', 'stock_transfer_history.id')
                    ->where('inventory_transactions.reference_type', '=', StockTransfer::class);
            })
            ->leftJoin('stock_receivings as stock_receiving_history', function ($join) {
                $join->on('inventory_transactions.reference_id', '=', 'stock_receiving_history.id')
                    ->where('inventory_transactions.reference_type', '=', StockReceiving::class);
            })
            ->leftJoin('stock_transfers as receiving_transfer_history', 'stock_receiving_history.stock_transfer_id', '=', 'receiving_transfer_history.id')
            ->leftJoin('stamp_redemptions as stamp_redemption_history', function ($join) {
                $join->on('inventory_transactions.reference_id', '=', 'stamp_redemption_history.id')
                    ->where('inventory_transactions.reference_type', '=', StampRedemption::class);
            })
            ->leftJoin('customers as stamp_customer', 'stamp_redemption_history.customer_id', '=', 'stamp_customer.id')
            ->leftJoin('stamp_programs as stamp_program_ref', 'stamp_redemption_history.stamp_program_id', '=', 'stamp_program_ref.id')
            ->leftJoin('users', 'inventory_transactions.created_by', '=', 'users.id')
            ->select(
                'inventory_transactions.transaction_type',
                'users.name as creator_name',
                'stock_in_history.dr_no',
                DB::raw('MIN(stock_in_history.id) as stock_in_reference_id'),
                'stock_in_history.receive_date',
                DB::raw('COALESCE(stamp_customer.name, stock_in_history.received_by, stock_receiving_history.received_by, receiving_transfer_history.received_by, stock_transfer_history.received_by) as received_by'),
                DB::raw('COALESCE(stock_transfer_history.transfer_no, receiving_transfer_history.transfer_no) as transfer_no'),
                DB::raw('MIN(COALESCE(stock_transfer_history.id, receiving_transfer_history.id)) as transfer_reference_id'),
                DB::raw('COALESCE(stock_transfer_history.origin_location, stock_receiving_history.origin_location, receiving_transfer_history.origin_location, stock_in_history.origin_location) as origin_location'),
                DB::raw('COALESCE(stock_transfer_history.destination_location, stock_receiving_history.destination_location, receiving_transfer_history.destination_location, stock_in_history.destination_location) as destination_location'),
                DB::raw('COALESCE(stock_receiving_history.remarks, stock_transfer_history.memo_remarks, receiving_transfer_history.memo_remarks) as remarks'),
                DB::raw('stamp_program_ref.name as stamp_program_name'),
                DB::raw('stamp_redemption_history.remarks as stamp_remarks'),
                DB::raw('stamp_redemption_history.id as stamp_redemption_reference_id'),
                DB::raw('SUM(inventory_transactions.quantity) as total_quantity'),
                DB::raw('COUNT(*) as record_count'),
                DB::raw('MAX(inventory_transactions.created_at) as latest_tx_at')
            )
            ->groupBy(
                'inventory_transactions.transaction_type',
                'users.name',
                'stock_in_history.dr_no',
                'stock_in_history.receive_date',
                DB::raw('COALESCE(stamp_customer.name, stock_in_history.received_by, stock_receiving_history.received_by, receiving_transfer_history.received_by, stock_transfer_history.received_by)'),
                DB::raw('COALESCE(stock_transfer_history.transfer_no, receiving_transfer_history.transfer_no)'),
                DB::raw('COALESCE(stock_transfer_history.origin_location, stock_receiving_history.origin_location, receiving_transfer_history.origin_location, stock_in_history.origin_location)'),
                DB::raw('COALESCE(stock_transfer_history.destination_location, stock_receiving_history.destination_location, receiving_transfer_history.destination_location, stock_in_history.destination_location)'),
                DB::raw('COALESCE(stock_receiving_history.remarks, stock_transfer_history.memo_remarks, receiving_transfer_history.memo_remarks)'),
                DB::raw('stamp_program_ref.name'),
                DB::raw('stamp_redemption_history.remarks'),
                DB::raw('stamp_redemption_history.id')
            )
            ->orderBy('latest_tx_at', 'desc')
            ->get();

        $unitsByRedemption = StampRedemptionUnit::query()
            ->whereIn('stamp_redemption_id', $history->pluck('stamp_redemption_reference_id')->filter())
            ->orderBy('id')
            ->get(['stamp_redemption_id', 'stock_in_id', 'serial_no', 'barcode', 'qrcode'])
            ->groupBy('stamp_redemption_id');

        $history->each(function ($transaction) use ($unitsByRedemption) {
            $transaction->redeemed_units = $unitsByRedemption
                ->get($transaction->stamp_redemption_reference_id, collect())
                ->values();
        });

        return response()->json([
            'asset' => $asset,
            'location' => $location,
            'history' => $history
        ]);
    }

    /**
     * Search physical units (Fixed) and consumable types currently at the ticket's store,
     * for tagging on a ticket.
     */
    /**
     * The individual stock units behind one Stock on Hand figure — what the
     * report's SOH cell opens when clicked.
     *
     * SOH is a ledger SUM, so on its own it answers "how many" but never
     * "which ones". This resolves the same asset+location pair down to the
     * actual `stock_ins` rows currently sitting there, with the codes staff
     * scan (serial / barcode / QR).
     *
     * Unit membership deliberately mirrors `StampController::redeemableUnitsAt`
     * — current location follows the latest Received transfer, units already
     * consumed by a stamp redemption are gone, and units on an unreceived
     * transfer have left the shelf — so this report and the redemption picker
     * can never disagree about what is physically there. The one difference:
     * units with no barcode or QR are still listed here. The picker excludes
     * them because it needs something to scan; a report that hid them would
     * be under-reporting real stock.
     *
     * Two mismatches are surfaced rather than hidden, because both mean
     * something is wrong upstream and quietly padding or truncating the list
     * would bury it:
     *  - `unit_count` < `soh` — ledger movements exist without unit rows
     *    (typical for stock booked in bulk on one quantity>1 stock-in);
     *  - `unit_count` > `soh` — units that left the shelf without being named.
     *
     * The second case has one dominant, knowable cause, so it is measured
     * rather than left as a mystery: redemptions predating
     * `stamp_redemption_units` deducted the ledger without recording WHICH
     * unit was handed over. `unattributed_redemptions` counts exactly that
     * shortfall, which lets the page say "one of these six is already gone,
     * and here is why" instead of showing an unexplained 5-vs-6.
     */
    public function units(Asset $asset, Request $request)
    {
        $location = $this->normalizeStoreCode($request->input('location'));

        if (! $location) {
            return response()->json([
                'message' => 'A location is required to list stock units.',
            ], 422);
        }

        $variants = $this->locationVariants($location);

        $soh = (int) InventoryTransaction::query()
            ->validInventoryLedger('inventory_transactions', 'report_units_soh')
            ->where('inventory_transactions.asset_id', $asset->id)
            ->whereIn('inventory_transactions.location', $variants)
            ->sum('inventory_transactions.quantity');

        $units = $this->fixedUnitsCurrentlyAt($variants, function ($query) use ($asset) {
            $query->where('stock_ins.asset_id', $asset->id)
                ->whereNotIn('stock_ins.id', StampRedemptionUnit::query()->select('stock_in_id'));
        })
            ->reject(fn (StockIn $unit) => $unit->sourceStockTransfers->contains(
                fn ($transfer) => in_array($transfer->status, ['For Posting', 'Posted'], true)
            ))
            ->map(fn (StockIn $unit) => [
                'stock_in_id' => $unit->id,
                'serial_no' => $unit->serial_no,
                'barcode' => $unit->barcode,
                'qrcode' => $unit->qrcode,
                'received_at' => optional($unit->created_at)->toIso8601String(),
            ])
            ->values();

        // Redemptions taken from THIS location whose units were never
        // recorded. Each is a physical item that left the shelf anonymously,
        // so it is still in the list above even though it is gone — the one
        // real reason `unit_count` can exceed `soh` here.
        //
        // Note `stamp_redemptions.location` is where the reward item came
        // OUT of stock, chosen by staff in the Redeem Reward modal. It is
        // unrelated to the store the customer's card was issued at, which is
        // why a card from another branch still shows up against this
        // location's inventory.
        $redemptions = StampRedemption::query()
            ->where('asset_id', $asset->id)
            ->whereIn('location', $variants)
            ->withCount('units')
            ->get(['id', 'quantity', 'created_at']);

        $unattributed = 0;
        $unattributedAt = [];
        foreach ($redemptions as $redemption) {
            $shortfall = max(0, (int) $redemption->quantity - (int) $redemption->units_count);
            if ($shortfall > 0) {
                $unattributed += $shortfall;
                $unattributedAt[] = optional($redemption->created_at)->toIso8601String();
            }
        }

        return response()->json([
            'asset' => $asset->only(['id', 'item_code', 'brand', 'model', 'description', 'type']),
            'location' => $location,
            'soh' => $soh,
            'unit_count' => $units->count(),
            'unattributed_redemptions' => $unattributed,
            'unattributed_redeemed_at' => array_values(array_filter($unattributedAt)),
            'units' => $units,
        ]);
    }

    public function assetsSearch(Request $request)
    {
        $search = trim((string) $request->input('q', ''));

        $activeCompanyId = CompanyContext::activeCompanyId();
        $storeCode = $request->filled('store_id')
            ? Store::query()
                ->when($activeCompanyId, fn ($query) => $query->where('company_id', $activeCompanyId))
                ->where('id', $request->store_id)
                ->value('code')
            : null;

        if (! $storeCode) {
            return response()->json([
                'results' => [],
                'store_code' => null,
                'requires_store' => true,
            ]);
        }

        $variants = $this->locationVariants($storeCode);
        $like = '%' . $search . '%';

        // Fixed serialized units currently located at this store.
        $fixedUnits = $this->fixedUnitsCurrentlyAt($variants, function ($query) use ($search, $like) {
            $query->with('asset:id,item_code,brand,model,type')
                ->whereHas('asset', fn ($q) => $q->where('type', 'Fixed'));

            if ($search !== '') {
                $query->where(function ($q) use ($like) {
                    $q->where('stock_ins.serial_no', 'like', $like)
                        ->orWhere('stock_ins.barcode', 'like', $like)
                        ->orWhere('stock_ins.qrcode', 'like', $like)
                        ->orWhereHas('asset', function ($a) use ($like) {
                            $a->where('item_code', 'like', $like)
                                ->orWhere('brand', 'like', $like)
                                ->orWhere('model', 'like', $like)
                                ->orWhere('description', 'like', $like);
                        });
                });
            }
        })
            ->take(20)
            ->map(fn (StockIn $unit) => [
                'result_type' => 'unit',
                'stock_in_id' => $unit->id,
                'asset_id' => $unit->asset_id,
                'serial_no' => $unit->serial_no,
                'barcode' => $unit->barcode,
                'qrcode' => $unit->qrcode,
                'item_code' => $unit->asset?->item_code,
                'brand' => $unit->asset?->brand,
                'model' => $unit->asset?->model,
                'type' => 'Fixed',
                'current_location' => $storeCode,
            ])
            ->values();

        // Consumable asset types with positive SOH at this store.
        $consumables = Asset::query()
            ->where('type', 'Consumables')
            ->when($search !== '', function ($query) use ($like) {
                $query->where(function ($query) use ($like) {
                    $query->where('item_code', 'like', $like)
                        ->orWhere('brand', 'like', $like)
                        ->orWhere('model', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->orderBy('item_code')
            ->limit(20)
            ->get(['id', 'item_code', 'brand', 'model', 'description', 'type'])
            ->map(fn (Asset $asset) => [
                'result_type' => 'consumable',
                'stock_in_id' => null,
                'asset_id' => $asset->id,
                'serial_no' => null,
                'barcode' => null,
                'item_code' => $asset->item_code,
                'brand' => $asset->brand,
                'model' => $asset->model,
                'type' => 'Consumables',
                'soh_at_store' => $this->stockOnHandAt($asset->id, $storeCode),
            ])
            ->filter(fn ($row) => $row['soh_at_store'] > 0)
            ->values();

        return response()->json([
            'results' => $fixedUnits->concat($consumables)->values(),
            'store_code' => $storeCode,
            'requires_store' => false,
        ]);
    }

    /**
     * Service activity (tickets) recorded against an asset across all locations,
     * including the specific unit serial/barcode tagged.
     */
    public function ticketActivity(Asset $asset, Request $request)
    {
        $actionTakenSub = TicketComment::query()
            ->select('action_taken')
            ->whereColumn('ticket_comments.ticket_id', 'tickets.id')
            ->whereNotNull('action_taken')
            ->where('action_taken', '!=', '')
            ->orderByDesc('created_at')
            ->limit(1);

        $rows = TicketAsset::query()
            ->where('ticket_assets.asset_id', $asset->id)
            ->join('tickets', 'ticket_assets.ticket_id', '=', 'tickets.id')
            ->leftJoin('stores', 'tickets.store_id', '=', 'stores.id')
            ->leftJoin('users', 'tickets.assignee_id', '=', 'users.id')
            ->whereNull('tickets.deleted_at')
            ->when(CompanyContext::activeCompanyId(), fn ($query, $companyId) => $query->where('tickets.company_id', $companyId))
            ->orderByDesc('ticket_assets.created_at')
            ->select([
                'ticket_assets.id',
                'ticket_assets.transaction_type',
                'ticket_assets.quantity',
                'ticket_assets.notes',
                'ticket_assets.serial_no',
                'ticket_assets.barcode',
                'ticket_assets.stock_in_id',
                'ticket_assets.created_at',
                'tickets.id as ticket_id',
                'tickets.ticket_key',
                'tickets.title',
                'tickets.status',
                'stores.code as store_code',
                'stores.name as store_name',
                'users.name as assignee_name',
            ])
            ->addSelect(['action_taken' => $actionTakenSub])
            ->get();

        return response()->json([
            'asset' => $asset,
            'activity' => $rows,
        ]);
    }

    public function movement(Request $request)
    {
        $activeCompanyId = CompanyContext::activeCompanyId();
        $stores = fn () => Store::query()
            ->when($activeCompanyId, fn ($query) => $query->where('company_id', $activeCompanyId));

        $warehouseLocations = $stores()->where('class', 'Office')
            ->whereNotIn('code', self::EXCLUDED_REPORT_LOCATIONS)
            ->pluck('code');

        $stagingLocations = $stores()->where('class', 'Regular')
            ->whereIn('code', ['CFE I', 'CFE II'])
            ->pluck('code');

        $userStoreLocations = $stores()->whereNotNull('code')
            ->whereNotIn('code', self::EXCLUDED_REPORT_LOCATIONS)
            ->where(function ($q) {
                $q->where('class', '!=', 'Office')
                  ->orWhereNull('class');
            })
            ->whereNotIn('code', ['CFE I', 'CFE II'])
            ->pluck('code');

        $countWithBreakdown = function ($baseQuery, string $locationColumn) {
            $rows = (clone $baseQuery)
                ->selectRaw("{$locationColumn} as loc, SUM(quantity) as qty")
                ->groupBy($locationColumn)
                ->get();

            return [
                'total' => (int) $rows->sum('qty'),
                'by_location' => $rows->mapWithKeys(fn ($r) => [$r->loc ?? 'Unknown' => (int) $r->qty])->all(),
            ];
        };

        $stages = [
            // Stage 1: Item Receive — StockIn For Posting at warehouse
            'item_receive' => $countWithBreakdown(
                StockIn::where('status', 'For Posting')
                    ->whereIn('destination_location', $warehouseLocations),
                'destination_location'
            ),

            // Stage 2: Basic Setup — StockTransfer For Posting, WH origin & WH destination
            'basic_setup' => $countWithBreakdown(
                StockTransfer::where('status', 'For Posting')
                    ->whereIn('origin_location', $warehouseLocations)
                    ->whereIn('destination_location', $warehouseLocations),
                'destination_location'
            ),

            // Stage 3: Item Allocation (Posted at WH, SD)
            'item_allocation_sd_posted' => $countWithBreakdown(
                StockTransfer::where('status', 'Posted')
                    ->whereIn('destination_location', $warehouseLocations),
                'destination_location'
            ),

            // Stage 4: Complete Setup — StockTransfer Posted (per user: regardless of is_allocation or destination)
            'complete_setup' => $countWithBreakdown(
                StockTransfer::where('status', 'Posted'),
                'destination_location'
            ),

            // Stage 5: Item Allocation (For Posting, SO/CT staging)
            'item_allocation_so_for_posting' => $countWithBreakdown(
                StockTransfer::where('status', 'For Posting')
                    ->whereIn('destination_location', $stagingLocations),
                'destination_location'
            ),

            // Stage 6: Customized Setup — StockTransfer For Posting, origin at SO/CT staging
            'customized_setup' => $countWithBreakdown(
                StockTransfer::where('status', 'For Posting')
                    ->whereIn('origin_location', $stagingLocations),
                'origin_location'
            ),

            // Stage 7: Item Allocation (Received at User Store)
            'item_allocation_user_store' => $countWithBreakdown(
                StockTransfer::where('status', 'Received')
                    ->whereIn('destination_location', $userStoreLocations),
                'destination_location'
            ),

            // Stage 8: Item Repair — StockIn with asset_type='For Repair'
            'item_repair' => $countWithBreakdown(
                StockIn::where('asset_type', 'For Repair')->where('status', 'Posted'),
                'destination_location'
            ),

            // Stage 9: Item Retire — StockIn with asset_type='For Disposal'
            'item_retire' => $countWithBreakdown(
                StockIn::where('asset_type', 'For Disposal')->where('status', 'Posted'),
                'destination_location'
            ),
        ];

        return response()->json([
            'stages' => $stages,
            'warehouse_locations' => $warehouseLocations->values(),
            'staging_locations' => $stagingLocations->values(),
            'user_store_locations_count' => $userStoreLocations->count(),
        ]);
    }

    private function inventoryRowsQuery(Request $request)
    {
        $query = $this->reportLedgerQuery()
            ->join('assets', 'inventory_transactions.asset_id', '=', 'assets.id')
            ->select(
                'inventory_transactions.asset_id',
                'inventory_transactions.location as location',
                DB::raw('SUM(inventory_transactions.quantity) as soh'),
                DB::raw('SUM(inventory_transactions.quantity * assets.cost) as total_value')
            )
            ->groupBy('inventory_transactions.asset_id', 'inventory_transactions.location', 'assets.id', 'assets.item_code')
            ->with(['asset.category', 'asset.subCategory']);

        if ($request->filled('category_id')) {
            $query->whereHas('asset', function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        if ($request->filled('sub_category_id')) {
            $query->whereHas('asset', function ($q) use ($request) {
                $q->where('sub_category_id', $request->sub_category_id);
            });
        }

        if ($request->filled('type')) {
            $query->whereHas('asset', function ($q) use ($request) {
                $q->where('type', $request->type);
            });
        }

        if ($request->filled('brand')) {
            $query->whereHas('asset', function ($q) use ($request) {
                $q->where('brand', $request->brand);
            });
        }

        if ($request->filled('location')) {
            $query->where('inventory_transactions.location', $request->location);
        }

        if ($request->filled('stock_status')) {
            if ($request->stock_status === 'in_stock') {
                $query->having(DB::raw('SUM(inventory_transactions.quantity)'), '>', 0);
            } elseif ($request->stock_status === 'out_of_stock') {
                $query->having(DB::raw('SUM(inventory_transactions.quantity)'), '<=', 0);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('asset', function ($q) use ($search) {
                $q->where('item_code', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    private function reportLedgerQuery()
    {
        return InventoryTransaction::query()
            // InventoryTransaction has no company_id of its own. Constrain it
            // through the scoped Asset relation so joins cannot leak another
            // entity's ledger rows and then render them as an empty asset.
            ->whereHas('asset')
            ->validInventoryLedger('inventory_transactions', 'report_valid')
            ->whereNotIn('inventory_transactions.location', self::EXCLUDED_REPORT_LOCATIONS);
    }

    /**
     * Sum the valid inventory ledger quantity for an asset at a store code.
     */
    private function stockOnHandAt(int $assetId, string $storeCode): int
    {
        return (int) InventoryTransaction::query()
            ->validInventoryLedger('inventory_transactions', 'asset_search_soh')
            ->where('inventory_transactions.asset_id', $assetId)
            ->whereIn('inventory_transactions.location', $this->locationVariants($storeCode))
            ->sum('inventory_transactions.quantity');
    }
}
