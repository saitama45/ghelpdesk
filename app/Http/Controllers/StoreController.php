<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class StoreController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:stores.view', only: ['index']),
            new Middleware('can:stores.create', only: ['store']),
            new Middleware('can:stores.edit', only: ['update']),
            new Middleware('can:stores.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = Store::with('user');

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('code', 'like', "%{$request->search}%")
                  ->orWhere('area', 'like', "%{$request->search}%")
                  ->orWhere('brand', 'like', "%{$request->search}%")
                  ->orWhere('cluster', 'like', "%{$request->search}%")
                  ->orWhereHas('user', function($q) use ($request) {
                      $q->where('name', 'like', "%{$request->search}%");
                  });
        }

        $stores = $query->latest()->paginate($request->get('per_page', 10))->withQueryString();
        $users = User::active()->orderBy('name')->get();

        return Inertia::render('Stores/Index', [
            'stores' => $stores,
            'users' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'code' => 'required|string|max:50|unique:stores,code',
            'name' => 'required|string|max:255|unique:stores,name',
            'sector' => 'required|numeric|min:1|max:8',
            'area' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
            'cluster' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        Store::create($validated);

        return redirect()->back()->with('success', 'Store created successfully');
    }

    public function update(Request $request, Store $store)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'code' => 'required|string|max:50|unique:stores,code,' . $store->id,
            'name' => 'required|string|max:255|unique:stores,name,' . $store->id,
            'sector' => 'required|numeric|min:1|max:8',
            'area' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
            'cluster' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $store->update($validated);

        return redirect()->back()->with('success', 'Store updated successfully');
    }

    public function destroy(Store $store)
    {
        $store->delete();
        return redirect()->back()->with('success', 'Store deleted successfully');
    }
}
