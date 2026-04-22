<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\StockIn;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
            'entries' => 'required|array|min:1',
            'entries.*.serial_no' => 'nullable|string',
            'entries.*.barcode' => 'nullable|string',
            'entries.*.qrcode' => 'nullable|string',
            'entries.*.warranty_months' => 'required|integer|min:0',
            'entries.*.eol_months' => 'required|integer|min:0',
            'entries.*.cost' => 'required|numeric|min:0',
            'entries.*.price' => 'required|numeric|min:0',
            'entries.*.location' => 'nullable|string',
        ]);

        foreach ($validated['entries'] as $entry) {
            StockIn::create([
                'receive_date' => $validated['receive_date'],
                'asset_id' => $validated['asset_id'],
                'quantity' => 1,
                ...Arr::only($entry, [
                    'serial_no',
                    'barcode',
                    'qrcode',
                    'warranty_months',
                    'eol_months',
                    'cost',
                    'price',
                    'location',
                ]),
            ]);
        }

        return redirect()->back()->with('success', 'Stock In recorded successfully');
    }

    public function update(Request $request, StockIn $stockIn)
    {
        $validated = $request->validate([
            'receive_date' => 'required|date',
            'asset_id' => 'required|exists:assets,id',
            'quantity' => 'required|integer|min:1',
            'header_mode' => 'nullable|boolean',
            'entries' => 'nullable|array|min:1',
            'entries.*.serial_no' => 'nullable|string',
            'entries.*.barcode' => 'nullable|string',
            'entries.*.qrcode' => 'nullable|string',
            'entries.*.warranty_months' => 'required_with:entries|integer|min:0',
            'entries.*.eol_months' => 'required_with:entries|integer|min:0',
            'entries.*.cost' => 'required_with:entries|numeric|min:0',
            'entries.*.price' => 'required_with:entries|numeric|min:0',
            'entries.*.location' => 'nullable|string',
            'serial_no' => 'nullable|string',
            'barcode' => 'nullable|string',
            'qrcode' => 'nullable|string',
            'warranty_months' => 'required|integer|min:0',
            'eol_months' => 'required|integer|min:0',
            'cost' => 'required|numeric|min:0',
            'price' => 'required|numeric|min:0',
            'location' => 'nullable|string',
        ]);

        if (!empty($validated['header_mode'])) {
            $this->syncGroupedEntries($stockIn, $validated);

            return redirect()->back()->with('success', 'Stock In updated successfully');
        }

        $stockIn->update(Arr::except($validated, ['header_mode']));

        return redirect()->back()->with('success', 'Stock In updated successfully');
    }

    public function destroy(StockIn $stockIn)
    {
        $stockIn->delete();

        return redirect()->back()->with('success', 'Stock In deleted successfully');
    }

    protected function syncGroupedEntries(StockIn $stockIn, array $validated): void
    {
        $entries = $validated['entries'] ?? [[
            'serial_no' => $validated['serial_no'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'qrcode' => $validated['qrcode'] ?? null,
            'warranty_months' => $validated['warranty_months'],
            'eol_months' => $validated['eol_months'],
            'cost' => $validated['cost'],
            'price' => $validated['price'],
            'location' => $validated['location'] ?? null,
        ]];

        $relatedRows = StockIn::where('asset_id', $stockIn->asset_id)
            ->whereDate('receive_date', $stockIn->receive_date)
            ->orderBy('id')
            ->get();

        foreach (array_values($entries) as $index => $entry) {
            $payload = [
                'receive_date' => $validated['receive_date'],
                'asset_id' => $validated['asset_id'],
                'quantity' => 1,
                ...Arr::only($entry, [
                    'serial_no',
                    'barcode',
                    'qrcode',
                    'warranty_months',
                    'eol_months',
                    'cost',
                    'price',
                    'location',
                ]),
            ];

            if (isset($relatedRows[$index])) {
                $relatedRows[$index]->update($payload);
            } else {
                StockIn::create($payload);
            }
        }

        foreach ($relatedRows->slice(count($entries)) as $extraRow) {
            $extraRow->delete();
        }
    }
}
