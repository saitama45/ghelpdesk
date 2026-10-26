<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Store;

/**
 * GET /api/integrations/diwa/directory
 *
 * Called server-to-server by the diwa reporting app (X-Integration-Key). diwa
 * keeps no companies or stores of its own: its Companies and Locations pages,
 * its entity switcher and the store filter on its reports all read this
 * payload, so an edit made here on /companies or /stores is what diwa shows.
 *
 * Every store is listed, inactive ones included — a closed store still has
 * sales history in diwa that must stay attributed to its brand.
 */
class DiwaDirectoryController extends Controller
{
    public function __invoke()
    {
        $companies = Company::query()
            ->with(['entities:id', 'brands:id'])
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'code' => $company->code,
                'type' => $company->type ?: 'Entity',
                'description' => $company->description,
                'is_active' => (bool) $company->is_active,
                'logo_url' => $company->logo ? url('/serve-storage/'.$company->logo) : null,
                'entity_ids' => $company->entities->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'brand_ids' => $company->brands->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ]);

        // Mapped field by field rather than serialised: Store appends
        // cluster_name, which would lazy-load the clusters of every row.
        $stores = Store::query()
            ->orderBy('code')
            ->get()
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'code' => $store->code,
                'name' => $store->name,
                'company_id' => $store->company_id,
                'brand' => $store->brand,
                'class' => $store->class,
                'sector' => $store->sector,
                'area' => $store->area,
                'address' => $store->address,
                'legal_company' => $store->legal_company,
                'email' => $store->email,
                'contact_person' => $store->contact_person,
                'contact_details' => $store->contact_details,
                'opening_date' => $store->opening_date?->toDateString(),
                'is_active' => (bool) $store->is_active,
            ]);

        return response()->json([
            'companies' => $companies->values()->all(),
            'stores' => $stores->values()->all(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
