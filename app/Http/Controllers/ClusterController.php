<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Store;
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
        $query = Cluster::with(['stores:id,code,name,cluster_id']);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('code', 'like', "%{$request->search}%")
                    ->orWhere('name', 'like', "%{$request->search}%");
            });
        }

        $clusters = $query->orderBy('code')->paginate($request->get('per_page', 10))->withQueryString();

        return Inertia::render('Clusters/Index', [
            'clusters' => $clusters,
            'stores' => Store::with('cluster:id,name')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'cluster_id']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:clusters,code',
            'name' => 'required|string|max:255|unique:clusters,name',
        ]);

        Cluster::create($validated);

        return redirect()->back()->with('success', 'Cluster created successfully');
    }

    public function update(Request $request, Cluster $cluster)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:clusters,code,' . $cluster->id,
            'name' => 'required|string|max:255|unique:clusters,name,' . $cluster->id,
        ]);

        $cluster->update($validated);

        return redirect()->back()->with('success', 'Cluster updated successfully');
    }

    public function assignStores(Request $request, Cluster $cluster)
    {
        $validated = $request->validate([
            'store_ids' => 'required|array|min:1',
            'store_ids.*' => 'exists:stores,id',
        ]);

        Store::whereIn('id', $validated['store_ids'])->update([
            'cluster_id' => $cluster->id,
        ]);

        return redirect()->back()->with('success', 'Stores assigned successfully');
    }

    public function destroy(Cluster $cluster)
    {
        if ($cluster->stores()->exists()) {
            return redirect()->back()->withErrors([
                'cluster' => 'Cannot delete cluster because it is assigned to one or more stores.',
            ]);
        }

        $cluster->delete();

        return redirect()->back()->with('success', 'Cluster deleted successfully');
    }
}
