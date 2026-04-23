<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class VendorController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:vendors.view', only: ['index']),
            new Middleware('can:vendors.create', only: ['store']),
            new Middleware('can:vendors.edit', only: ['update']),
            new Middleware('can:vendors.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = Vendor::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $vendors = $query->orderBy('name')->paginate($request->get('per_page', 10))->withQueryString();

        return Inertia::render('Vendors/Index', [
            'vendors' => $vendors,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'code'           => 'nullable|string|max:50',
            'name'           => 'required|string|max:255|unique:vendors,name',
            'vendor_type'    => 'required|string|in:Supplier,Service Provider',
            'contact_person' => 'nullable|string|max:255',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:50',
            'address'        => 'nullable|string',
        ]);

        Vendor::create([
            'code'           => $request->code,
            'name'           => $request->name,
            'vendor_type'    => $request->vendor_type,
            'contact_person' => $request->contact_person,
            'email'          => $request->email,
            'phone'          => $request->phone,
            'address'        => $request->address,
            'is_active'      => true,
        ]);

        return redirect()->back()->with('success', 'Vendor created successfully');
    }

    public function update(Request $request, Vendor $vendor)
    {
        $request->validate([
            'code'           => 'nullable|string|max:50',
            'name'           => 'required|string|max:255|unique:vendors,name,' . $vendor->id,
            'vendor_type'    => 'required|string|in:Supplier,Service Provider',
            'contact_person' => 'nullable|string|max:255',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:50',
            'address'        => 'nullable|string',
            'is_active'      => 'boolean',
        ]);

        $vendor->update([
            'code'           => $request->code,
            'name'           => $request->name,
            'vendor_type'    => $request->vendor_type,
            'contact_person' => $request->contact_person,
            'email'          => $request->email,
            'phone'          => $request->phone,
            'address'        => $request->address,
            'is_active'      => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', 'Vendor updated successfully');
    }

    public function destroy(Vendor $vendor)
    {
        $vendor->delete();

        return redirect()->back()->with('success', 'Vendor deleted successfully');
    }
}
