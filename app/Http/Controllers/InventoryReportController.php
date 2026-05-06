<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Category;
use App\Models\StockIn;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InventoryReportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:reports.inventory', only: ['index']),
        ];
    }

    public function index(Request $request)
    {
        $query = StockIn::query()
            ->where('status', 'Posted')
            ->select(
                'asset_id',
                'destination_location as location',
                DB::raw('SUM(quantity) as soh'),
                DB::raw('SUM(quantity * cost) as total_value')
            )
            ->groupBy('asset_id', 'destination_location')
            ->with(['asset.category', 'asset.subCategory']);

        // Filters
        if ($request->filled('category_id')) {
            $query->whereHas('asset', function($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        if ($request->filled('sub_category_id')) {
            $query->whereHas('asset', function($q) use ($request) {
                $q->where('sub_category_id', $request->sub_category_id);
            });
        }

        if ($request->filled('type')) {
            $query->whereHas('asset', function($q) use ($request) {
                $q->where('type', $request->type);
            });
        }

        if ($request->filled('brand')) {
            $query->whereHas('asset', function($q) use ($request) {
                $q->where('brand', $request->brand);
            });
        }

        if ($request->filled('location')) {
            $query->where('destination_location', $request->location);
        }

        if ($request->filled('stock_status')) {
            if ($request->stock_status === 'in_stock') {
                $query->having(DB::raw('SUM(quantity)'), '>', 0);
            } elseif ($request->stock_status === 'out_of_stock') {
                $query->having(DB::raw('SUM(quantity)'), '<=', 0);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('asset', function($q) use ($search) {
                $q->where('item_code', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%")
                  ->orWhere('model', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $assets = $query->paginate(10)->withQueryString();

        // Summary Data for Cards
        $summary = [
            'total_items' => Asset::count(),
            'total_soh' => StockIn::where('status', 'Posted')->sum('quantity') ?? 0,
            'total_inventory_value' => StockIn::where('status', 'Posted')->selectRaw('SUM(quantity * cost) as total')->value('total') ?? 0,
            'out_of_stock_count' => Asset::where(function ($query) {
                $query->whereRaw('(SELECT ISNULL(SUM(quantity), 0) FROM stock_ins WHERE stock_ins.asset_id = assets.id AND status = \'Posted\') <= 0');
            })->count()
        ];

        return Inertia::render('Reports/Inventory', [
            'assets' => $assets,
            'categories' => Category::orderBy('name')->get(),
            'brands' => Asset::whereNotNull('brand')->distinct()->pluck('brand'),
            'locations' => StockIn::where('status', 'Posted')->whereNotNull('destination_location')->distinct()->pluck('destination_location'),
            'summary' => $summary,
            'filters' => $request->only(['category_id', 'sub_category_id', 'type', 'brand', 'location', 'stock_status', 'search'])
        ]);
    }
}
