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

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);
        $header = array_map('trim', $header);

        $imported = 0;
        $errors = [];
        $row = 1;

        while (($line = fgetcsv($handle)) !== false) {
            $row++;
            if (count($line) !== count($header)) {
                $errors[] = "Row {$row}: column count mismatch, skipped.";
                continue;
            }
            $data = array_combine($header, array_map('trim', $line));

            $validator = \Validator::make($data, [
                'name' => 'required|string|max:255|unique:sub_categories,name',
                'description' => 'nullable|string',
                'is_active' => 'nullable|in:0,1',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$row}: " . implode(', ', $validator->errors()->all());
                continue;
            }

            SubCategory::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            ]);
            $imported++;
        }

        fclose($handle);

        return response()->json(['imported' => $imported, 'errors' => $errors]);
    }

    public function template()
    {
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sub-categories-import-template.csv"',
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $columns = ['name', 'description', 'is_active'];
        $example = ['Example Sub-Category', 'A short description', '1'];

        $callback = function () use ($columns, $example) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);
            fputcsv($file, $example);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
