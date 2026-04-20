<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AssetController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['index']),
            new Middleware('can:assets.create', only: ['store']),
            new Middleware('can:assets.edit', only: ['update']),
            new Middleware('can:assets.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = Asset::with(['category', 'subCategory']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('item_code', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%")
                  ->orWhere('model', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('category', fn($cq) => $cq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('subCategory', fn($sq) => $sq->where('name', 'like', "%{$search}%"));
            });
        }

        $assets = $query->latest()->paginate($request->input('per_page', 10));
        
        $categories = Category::orderBy('name')->get();
        $subCategories = SubCategory::orderBy('name')->get();

        return Inertia::render('Assets/Index', [
            'assets' => $assets,
            'categories' => $categories,
            'subCategories' => $subCategories,
            'filters' => $request->only(['search', 'per_page']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_code' => 'required|string|unique:assets,item_code',
            'category_id' => 'required|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'cost' => 'nullable|numeric|min:0',
            'type' => 'required|in:Fixed,Consumables',
            'eol_years' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        Asset::create($validated);

        return redirect()->back()->with('success', 'Asset created successfully');
    }

    public function update(Request $request, Asset $asset)
    {
        $validated = $request->validate([
            'item_code' => 'required|string|unique:assets,item_code,' . $asset->id,
            'category_id' => 'required|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'cost' => 'nullable|numeric|min:0',
            'type' => 'required|in:Fixed,Consumables',
            'eol_years' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $asset->update($validated);

        return redirect()->back()->with('success', 'Asset updated successfully');
    }

    public function destroy(Asset $asset)
    {
        $asset->delete();
        return redirect()->back()->with('success', 'Asset deleted successfully');
    }
}
