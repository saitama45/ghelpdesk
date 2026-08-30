<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ReferenceOption;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

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
        $query = Company::with('entities:id,name,code');

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('code', 'like', "%{$request->search}%");
        }

        $companies = $query->paginate($request->get('per_page', 10))->withQueryString();

        return Inertia::render('Companies/Index', [
            'companies' => $companies,
            'companyTypeOptions' => ReferenceOption::ofType('company_type'),
            'entityOptions' => Company::where('type', 'Entity')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'code'        => 'required|string|max:50|unique:companies',
            'type'        => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'logo'        => 'nullable|image|max:2048',
            'entity_company_ids' => 'nullable|array',
            'entity_company_ids.*' => ['integer', Rule::exists('companies', 'id')->where('type', 'Entity')],
        ]);

        $data = $request->only(['name', 'code', 'description']);
        $data['type'] = $request->input('type') ?: 'Entity';

        if ($request->hasFile('logo')) {
            $data['logo'] = str_replace('\\', '/', $request->file('logo')->store('company-logos', 'public'));
        }

        $company = Company::create($data);
        if ($company->type === 'Brand') {
            $company->entities()->sync($request->input('entity_company_ids', []));
        }

        return redirect()->back()->with('success', 'Company created successfully');
    }

    public function update(Request $request, Company $company)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'code'        => 'required|string|max:50|unique:companies,code,' . $company->id,
            'type'        => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
            'logo'        => 'nullable|image|max:2048',
            'remove_logo' => 'nullable|boolean',
            'entity_company_ids' => 'nullable|array',
            'entity_company_ids.*' => ['integer', Rule::exists('companies', 'id')->where('type', 'Entity')],
        ]);

        $company->name        = $request->name;
        $company->code        = $request->code;
        $company->type        = $request->input('type') ?: 'Entity';
        $company->description = $request->description;
        $company->is_active   = $request->boolean('is_active');

        if ($request->boolean('remove_logo') && $company->logo) {
            Storage::disk('public')->delete($company->logo);
            $company->logo = null;
        }

        if ($request->hasFile('logo')) {
            if ($company->logo) {
                Storage::disk('public')->delete($company->logo);
            }
            $company->logo = str_replace('\\', '/', $request->file('logo')->store('company-logos', 'public'));
        }

        $company->save();
        $company->entities()->sync($company->type === 'Brand' ? $request->input('entity_company_ids', []) : []);

        return redirect()->back()->with('success', 'Company updated successfully');
    }

    /**
     * Set the active entity for the current session. Any authenticated user may
     * switch, but only to an entity they can access.
     */
    public function switch(Request $request)
    {
        $request->validate([
            'company_id' => 'required|integer',
        ]);

        $accessible = CompanyContext::accessibleCompanies($request->user());

        if (!$accessible->contains('id', (int) $request->company_id)) {
            return redirect()->back()->with('error', 'You do not have access to that entity.');
        }

        session([CompanyContext::SESSION_KEY => (int) $request->company_id]);
        CompanyContext::flushMemo();

        $name = $accessible->firstWhere('id', (int) $request->company_id)->name;

        return redirect()->back()->with('success', "Switched to {$name}.");
    }

    public function destroy(Company $company)
    {
        if ($company->logo) {
            Storage::disk('public')->delete($company->logo);
        }

        $company->brands()->detach();
        $company->entities()->detach();
        $company->delete();
        return redirect()->back()->with('success', 'Company deleted successfully');
    }
}
