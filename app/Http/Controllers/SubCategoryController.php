<?php

namespace App\Http\Controllers;

use App\Models\SubCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SubCategoryController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:subcategories.view', only: ['index']),
            new Middleware('can:subcategories.create', only: ['store']),
            new Middleware('can:subcategories.edit', only: ['update']),
            new Middleware('can:subcategories.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = SubCategory::query();

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
        }

        $subcategories = $query->latest()->paginate($request->get('per_page', 10))->withQueryString();

        return Inertia::render('SubCategories/Index', [
            'subcategories' => $subcategories,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:sub_categories,name',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        SubCategory::create($validated);

        return redirect()->back()->with('success', 'Sub-category created successfully');
    }

    public function update(Request $request, SubCategory $subCategory)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:sub_categories,name,' . $subCategory->id,
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $subCategory->update($validated);

        return redirect()->back()->with('success', 'Sub-category updated successfully');
    }

    public function destroy(SubCategory $subCategory)
    {
        $subCategory->delete();
        return redirect()->back()->with('success', 'Sub-category deleted successfully');
    }
}
