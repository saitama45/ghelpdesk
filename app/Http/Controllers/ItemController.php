<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ItemController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:items.view', only: ['index']),
            new Middleware('can:items.create', only: ['store']),
            new Middleware('can:items.edit', only: ['update']),
            new Middleware('can:items.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = Item::with(['category', 'subCategory']);

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%")
                  ->orWhereHas('category', function($q) use ($request) {
                      $q->where('name', 'like', "%{$request->search}%");
                  })
                  ->orWhereHas('subCategory', function($q) use ($request) {
                      $q->where('name', 'like', "%{$request->search}%");
                  });
        }

        $items = $query->latest()->paginate($request->get('per_page', 10))->withQueryString();
        $categories = Category::where('is_active', true)->get();
        $subCategories = SubCategory::where('is_active', true)->get();
        $settings = \App\Models\Setting::all()->pluck('value', 'key');

        return Inertia::render('Items/Index', [
            'items' => $items,
            'categories' => $categories,
            'subCategories' => $subCategories,
            'settings' => $settings,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'name' => 'required|string|max:255|unique:items,name',
            'description' => 'nullable|string',
            'priority' => 'required|in:Low,Medium,High,Urgent',
            'is_active' => 'boolean',
        ]);

        Item::create($validated);

        return redirect()->back()->with('success', 'Item created successfully');
    }

    public function update(Request $request, Item $item)
    {
        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'name' => 'required|string|max:255|unique:items,name,' . $item->id,
            'description' => 'nullable|string',
            'priority' => 'required|in:Low,Medium,High,Urgent',
            'is_active' => 'boolean',
        ]);

        $item->update($validated);

        return redirect()->back()->with('success', 'Item updated successfully');
    }

    public function destroy(Item $item)
    {
        $item->delete();
        return redirect()->back()->with('success', 'Item deleted successfully');
    }
}
