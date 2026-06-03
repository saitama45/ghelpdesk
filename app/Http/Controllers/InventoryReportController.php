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
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InventoryReportController extends Controller implements HasMiddleware
{
    private const EXCLUDED_REPORT_LOCATIONS = ['SUPPLIER'];

    public static function middleware(): array
    {
        return [
            new Middleware('can:reports.inventory', only: ['index', 'movement']),
        ];
    }

    public function index(Request $request)
    {
        $query = $this->inventoryRowsQuery($request);

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
            'categories' => Category::orderBy('name')->get(),
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
            ->leftJoin('users', 'inventory_transactions.created_by', '=', 'users.id')
            ->select(
                'inventory_transactions.transaction_type',
                'users.name as creator_name',
                'stock_in_history.dr_no',
                DB::raw('MIN(stock_in_history.id) as stock_in_reference_id'),
                'stock_in_history.receive_date',
                DB::raw('COALESCE(stock_in_history.received_by, stock_receiving_history.received_by, receiving_transfer_history.received_by, stock_transfer_history.received_by) as received_by'),
                DB::raw('COALESCE(stock_transfer_history.transfer_no, receiving_transfer_history.transfer_no) as transfer_no'),
                DB::raw('MIN(COALESCE(stock_transfer_history.id, receiving_transfer_history.id)) as transfer_reference_id'),
                DB::raw('COALESCE(stock_transfer_history.origin_location, stock_receiving_history.origin_location, receiving_transfer_history.origin_location, stock_in_history.origin_location) as origin_location'),
                DB::raw('COALESCE(stock_transfer_history.destination_location, stock_receiving_history.destination_location, receiving_transfer_history.destination_location, stock_in_history.destination_location) as destination_location'),
                DB::raw('COALESCE(stock_receiving_history.remarks, stock_transfer_history.memo_remarks, receiving_transfer_history.memo_remarks) as remarks'),
                DB::raw('SUM(inventory_transactions.quantity) as total_quantity'),
                DB::raw('COUNT(*) as record_count'),
                DB::raw('MAX(inventory_transactions.created_at) as latest_tx_at')
            )
            ->groupBy(
                'inventory_transactions.transaction_type',
                'users.name',
                'stock_in_history.dr_no',
                'stock_in_history.receive_date',
                DB::raw('COALESCE(stock_in_history.received_by, stock_receiving_history.received_by, receiving_transfer_history.received_by, stock_transfer_history.received_by)'),
                DB::raw('COALESCE(stock_transfer_history.transfer_no, receiving_transfer_history.transfer_no)'),
                DB::raw('COALESCE(stock_transfer_history.origin_location, stock_receiving_history.origin_location, receiving_transfer_history.origin_location, stock_in_history.origin_location)'),
                DB::raw('COALESCE(stock_transfer_history.destination_location, stock_receiving_history.destination_location, receiving_transfer_history.destination_location, stock_in_history.destination_location)'),
                DB::raw('COALESCE(stock_receiving_history.remarks, stock_transfer_history.memo_remarks, receiving_transfer_history.memo_remarks)')
            )
            ->orderBy('latest_tx_at', 'desc')
            ->get();

        return response()->json([
            'asset' => $asset,
            'location' => $location,
            'history' => $history
        ]);
    }

    public function movement(Request $request)
    {
        $warehouseLocations = Store::where('class', 'Office')
            ->whereNotIn('code', self::EXCLUDED_REPORT_LOCATIONS)
            ->pluck('code');

        $stagingLocations = Store::where('class', 'Regular')
            ->whereIn('code', ['CFE I', 'CFE II'])
            ->pluck('code');

        $userStoreLocations = Store::whereNotNull('code')
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
            ->validInventoryLedger('inventory_transactions', 'report_valid')
            ->whereNotIn('inventory_transactions.location', self::EXCLUDED_REPORT_LOCATIONS);
    }
}
