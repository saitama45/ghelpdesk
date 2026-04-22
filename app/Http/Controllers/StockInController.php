<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\StockIn;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockInController extends Controller
{
    public function index()
    {
        return Inertia::render('StockIn/Index', [
            'stockIns' => StockIn::with('asset')->latest()->paginate(10),
            'assets' => Asset::all(),
            'permissions' => [
                'create' => auth()->user()->can('stock_ins.create'),
                'edit' => auth()->user()->can('stock_ins.edit'),
                'delete' => auth()->user()->can('stock_ins.delete'),
            ]
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'receive_date' => 'required|date',
            'asset_id' => 'required|exists:assets,id',
            'quantity' => 'required|integer|min:1',
            'serial_no' => 'nullable|string',
            'warranty_months' => 'required|integer|min:0',
            'eol_months' => 'required|integer|min:0',
            'cost' => 'required|numeric|min:0',
            'price' => 'required|numeric|min:0',
            'location' => 'nullable|string',
        ]);

        StockIn::create($validated);

        return redirect()->back()->with('success', 'Stock In recorded successfully');
    }

    public function update(Request $request, StockIn $stockIn)
    {
        $validated = $request->validate([
            'receive_date' => 'required|date',
            'asset_id' => 'required|exists:assets,id',
            'quantity' => 'required|integer|min:1',
            'serial_no' => 'nullable|string',
            'warranty_months' => 'required|integer|min:0',
            'eol_months' => 'required|integer|min:0',
            'cost' => 'required|numeric|min:0',
            'price' => 'required|numeric|min:0',
            'location' => 'nullable|string',
        ]);

        $stockIn->update($validated);

        return redirect()->back()->with('success', 'Stock In updated successfully');
    }

    public function destroy(StockIn $stockIn)
    {
        $stockIn->delete();

        return redirect()->back()->with('success', 'Stock In deleted successfully');
    }
}
