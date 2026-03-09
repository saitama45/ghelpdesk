<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CategoryController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:categories.view', only: ['index']),
            new Middleware('can:categories.create', only: ['store']),
            new Middleware('can:categories.edit', only: ['update']),
            new Middleware('can:categories.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = Category::query();
        
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
        }
        
        $categories = $query->paginate($request->get('per_page', 10))->withQueryString();
        
        return Inertia::render('Categories/Index', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
            'description' => 'nullable|string',
            'response_time_hours' => 'nullable|integer|min:0',
            'resolution_time_hours' => 'nullable|integer|min:0',
        ]);

        Category::create([
            'name' => $request->name,
            'description' => $request->description,
            'response_time_hours' => $request->response_time_hours,
            'resolution_time_hours' => $request->resolution_time_hours,
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Category created successfully');
    }

    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $category->id,
            'description' => 'nullable|string',
            'response_time_hours' => 'nullable|integer|min:0',
            'resolution_time_hours' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $category->update([
            'name' => $request->name,
            'description' => $request->description,
            'response_time_hours' => $request->response_time_hours,
            'resolution_time_hours' => $request->resolution_time_hours,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', 'Category updated successfully');
    }

    public function destroy(Category $category)
    {
        $category->delete();
        return redirect()->back()->with('success', 'Category deleted successfully');
    }
}
