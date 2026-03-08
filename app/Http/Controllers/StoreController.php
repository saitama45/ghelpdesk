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
        $query = Store::with('users:id,name');

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('code', 'like', "%{$request->search}%")
                  ->orWhere('area', 'like', "%{$request->search}%")
                  ->orWhere('brand', 'like', "%{$request->search}%")
                  ->orWhere('cluster', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
                  ->orWhereHas('users', function($q) use ($request) {
                      $q->where('name', 'like', "%{$request->search}%");
                  });
        }

        $stores = $query->latest()->paginate($request->get('per_page', 10))->withQueryString();
        $users = User::active()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Stores/Index', [
            'stores' => $stores,
            'users' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:stores,code',
            'name' => 'required|string|max:255|unique:stores,name',
            'sector' => 'required|numeric|min:1|max:8',
            'area' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
            'cluster' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_meters' => 'nullable|integer|min:10|max:5000',
            'is_active' => 'boolean',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $store = Store::create($validated);

        if ($request->has('user_ids')) {
            $store->users()->sync($request->user_ids);
        }

        return redirect()->back()->with('success', 'Store created successfully');
    }

    public function update(Request $request, Store $store)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:stores,code,' . $store->id,
            'name' => 'required|string|max:255|unique:stores,name,' . $store->id,
            'sector' => 'required|numeric|min:1|max:8',
            'area' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
            'cluster' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_meters' => 'nullable|integer|min:10|max:5000',
            'is_active' => 'boolean',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $store->update($validated);

        if ($request->has('user_ids')) {
            $store->users()->sync($request->user_ids);
        } else {
            $store->users()->detach();
        }

        return redirect()->back()->with('success', 'Store updated successfully');
    }

    public function destroy(Store $store)
    {
        $store->delete();
        return redirect()->back()->with('success', 'Store deleted successfully');
    }
}
