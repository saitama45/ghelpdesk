<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\StockIn;
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
            new Middleware('can:reports.inventory', only: ['index']),
        ];
    }

    public function index(Request $request)
    {
        $query = $this->inventoryRowsQuery($request);

        $assets = (clone $query)
            ->orderBy('stock_ins.destination_location')
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

        $postedTransactionsBase = fn () => InventoryTransaction::query()
            ->join('stock_ins', function ($join) {
                $join->on('inventory_transactions.reference_id', '=', 'stock_ins.id')
                     ->where('inventory_transactions.reference_type', '=', StockIn::class);
            })
            ->where('stock_ins.status', 'Posted')
            ->whereNotIn('inventory_transactions.location', self::EXCLUDED_REPORT_LOCATIONS);

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
                ->whereRaw('ISNULL(internal_stock.soh, 0) <= 0')
                ->count(),
        ];

        return Inertia::render('Reports/Inventory', [
            'assets' => $assets,
            'locationSummaries' => $locationSummaries,
            'categories' => Category::orderBy('name')->get(),
            'brands' => Asset::whereNotNull('brand')->distinct()->pluck('brand'),
            'locations' => StockIn::whereNotNull('destination_location')
                ->where('status', 'Posted')
                ->whereNotIn('destination_location', self::EXCLUDED_REPORT_LOCATIONS)
                ->distinct()
                ->orderBy('destination_location')
                ->pluck('destination_location'),
            'summary' => $summary,
            'filters' => $request->only(['category_id', 'sub_category_id', 'type', 'brand', 'location', 'stock_status', 'search'])
        ]);
    }

    public function history(Asset $asset, Request $request)
    {
        $location = $request->location;

        $history = InventoryTransaction::where('inventory_transactions.asset_id', $asset->id)
            ->where('inventory_transactions.location', $location)
            ->leftJoin('stock_ins', function($join) {
                $join->on('inventory_transactions.reference_id', '=', 'stock_ins.id')
                     ->where('inventory_transactions.reference_type', '=', StockIn::class);
            })
            ->leftJoin('users', 'inventory_transactions.created_by', '=', 'users.id')
            ->select(
                'inventory_transactions.transaction_type',
                'users.name as creator_name',
                'stock_ins.dr_no',
                'stock_ins.receive_date',
                'stock_ins.received_by',
                'stock_ins.origin_location',
                'stock_ins.destination_location',
                DB::raw('SUM(inventory_transactions.quantity) as total_quantity'),
                DB::raw('COUNT(*) as record_count'),
                DB::raw('MAX(inventory_transactions.created_at) as latest_tx_at')
            )
            ->groupBy(
                'inventory_transactions.transaction_type',
                'users.name',
                'stock_ins.dr_no',
                'stock_ins.receive_date',
                'stock_ins.received_by',
                'stock_ins.origin_location',
                'stock_ins.destination_location'
            )
            ->orderBy('latest_tx_at', 'desc')
            ->get();

        return response()->json([
            'asset' => $asset,
            'location' => $location,
            'history' => $history
        ]);
    }

    private function inventoryRowsQuery(Request $request)
    {
        $query = InventoryTransaction::query()
            ->join('assets', 'inventory_transactions.asset_id', '=', 'assets.id')
            ->join('stock_ins', function ($join) {
                $join->on('inventory_transactions.reference_id', '=', 'stock_ins.id')
                     ->where('inventory_transactions.reference_type', '=', StockIn::class);
            })
            ->where('stock_ins.status', 'Posted')
            ->whereNotIn('stock_ins.destination_location', self::EXCLUDED_REPORT_LOCATIONS)
            ->select(
                'inventory_transactions.asset_id',
                'stock_ins.destination_location as location',
                DB::raw('SUM(inventory_transactions.quantity) as soh'),
                DB::raw('SUM(inventory_transactions.quantity * assets.cost) as total_value')
            )
            ->groupBy('inventory_transactions.asset_id', 'stock_ins.destination_location', 'assets.id', 'assets.item_code')
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
            $query->where('stock_ins.destination_location', $request->location);
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
}
