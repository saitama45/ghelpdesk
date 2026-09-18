<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Store;
use App\Support\EntityReferenceScope;
use App\Support\DepartmentReferences;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ClusterController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:clusters.view', only: ['index']),
            new Middleware('can:clusters.create', only: ['store']),
            new Middleware('can:clusters.edit', only: ['update', 'assignStores']),
            new Middleware('can:clusters.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        // Follows the entity switcher; a brand also lists its tagged entities'
        // clusters, read-only (see EntityReferenceScope).
        $query = EntityReferenceScope::visible(
            Cluster::with(['stores:id,code,name', 'company:id,name,code']),
            'clusters.company_id'
        );

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('code', 'like', "%{$request->search}%")
                    ->orWhere('name', 'like', "%{$request->search}%");
            });
        }

        $clusters = $query->orderBy('code')->paginate($request->get('per_page', 10))->withQueryString();

        return Inertia::render('Clusters/Index', [
            'clusters' => $clusters,
            // Stores belong to the entity, not to a department: every department
            // clustering for this entity picks from the same list.
            'stores' => EntityReferenceScope::visible(Store::with('clusters:id,name'), 'stores.company_id')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', DepartmentReferences::unique('clusters', 'code')],
            'name' => ['required', 'string', 'max:255', DepartmentReferences::unique('clusters', 'name')],
        ]);

        Cluster::create($validated);

        return redirect()->back()->with('success', 'Cluster created successfully');
    }

    public function update(Request $request, Cluster $cluster)
    {
        EntityReferenceScope::ensureOwned($cluster);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', DepartmentReferences::unique('clusters', 'code', $cluster)],
            'name' => ['required', 'string', 'max:255', DepartmentReferences::unique('clusters', 'name', $cluster)],
        ]);

        $cluster->update($validated);

        return redirect()->back()->with('success', 'Cluster updated successfully');
    }

    public function assignStores(Request $request, Cluster $cluster)
    {
        EntityReferenceScope::ensureOwned($cluster);

        $validated = $request->validate([
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'exists:stores,id',
        ]);

        foreach ($validated['store_ids'] ?? [] as $storeId) {
            if (! EntityReferenceScope::visible(Store::query(), 'stores.company_id')->whereKey($storeId)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['store_ids' => 'Choose stores that belong to the current entity.']);
            }
        }

        // sync() replaces this cluster's store list (adds new, removes deselected)
        // without touching other clusters — stores can still belong to multiple clusters.
        $cluster->stores()->sync($validated['store_ids'] ?? []);

        return redirect()->back()->with('success', 'Stores assigned successfully');
    }

    public function destroy(Cluster $cluster)
    {
        EntityReferenceScope::ensureOwned($cluster);

        if ($cluster->stores()->exists()) {
            return redirect()->back()->withErrors([
                'cluster' => 'Cannot delete cluster because it is assigned to one or more stores.',
            ]);
        }

        $cluster->delete();

        return redirect()->back()->with('success', 'Cluster deleted successfully');
    }
}
