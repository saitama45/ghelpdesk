<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CompanyController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:companies.view', only: ['index']),
            new Middleware('can:companies.create', only: ['store']),
            new Middleware('can:companies.edit', only: ['update']),
            new Middleware('can:companies.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = Company::query();
        
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('code', 'like', "%{$request->search}%");
        }
        
        $companies = $query->paginate($request->get('per_page', 10))->withQueryString();
        
        return Inertia::render('Companies/Index', [
            'companies' => $companies,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:companies',
            'description' => 'nullable|string',
        ]);

        Company::create($request->all());

        return redirect()->back()->with('success', 'Company created successfully');
    }

    public function update(Request $request, Company $company)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:companies,code,' . $company->id,
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $company->name = $request->name;
        $company->code = $request->code;
        $company->description = $request->description;
        $company->is_active = $request->boolean('is_active');
        $company->save();

        return redirect()->back()->with('success', 'Company updated successfully');
    }

    public function destroy(Company $company)
    {
        $company->delete();
        return redirect()->back()->with('success', 'Company deleted successfully');
    }
}
