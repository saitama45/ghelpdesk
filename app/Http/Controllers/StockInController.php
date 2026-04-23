<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\StockIn;
use App\Models\Store;
use App\Models\Vendor;
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
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'vendors' => Vendor::active()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'receive_date' => 'required|date',
            'dr_no' => 'nullable|string|max:255',
            'dr_date' => 'nullable|date',
            'vendor' => 'nullable|string|max:255',
            'origin_location' => 'nullable|string|max:255',
            'received_by' => 'nullable|string|max:255',
            'posted_by' => 'nullable|string|max:255',
            'status' => 'required|in:For Posting,Posted',
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
            'entries.*.destination_location' => 'nullable|string|max:255',
        ]);

        foreach ($validated['entries'] as $entry) {
            StockIn::create([
                'receive_date' => $validated['receive_date'],
                'dr_no' => $validated['dr_no'] ?? null,
                'dr_date' => $validated['dr_date'] ?? null,
                'vendor' => $validated['vendor'] ?? null,
                'origin_location' => $this->normalizeStoreCode($validated['origin_location'] ?? null),
                'received_by' => $validated['received_by'] ?? null,
                'posted_by' => $validated['posted_by'] ?? null,
                'status' => $validated['status'],
                'asset_id' => $validated['asset_id'],
                'quantity' => 1,
                ...$this->normalizeStockEntry(Arr::only($entry, [
                    'serial_no',
                    'barcode',
                    'qrcode',
                    'warranty_months',
                    'eol_months',
                    'cost',
                    'price',
                    'destination_location',
                ])),
            ]);
        }

        return redirect()->back()->with('success', 'Stock In recorded successfully');
    }

    public function update(Request $request, StockIn $stockIn)
    {
        $validated = $request->validate([
            'receive_date' => 'required|date',
            'dr_no' => 'nullable|string|max:255',
            'dr_date' => 'nullable|date',
            'vendor' => 'nullable|string|max:255',
            'origin_location' => 'nullable|string|max:255',
            'received_by' => 'nullable|string|max:255',
            'posted_by' => 'nullable|string|max:255',
            'status' => 'required|in:For Posting,Posted',
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
            'entries.*.destination_location' => 'nullable|string|max:255',
            'serial_no' => 'nullable|string',
            'barcode' => 'nullable|string',
            'qrcode' => 'nullable|string',
            'warranty_months' => 'required_without:entries|integer|min:0',
            'eol_months' => 'required_without:entries|integer|min:0',
            'cost' => 'required_without:entries|numeric|min:0',
            'price' => 'required_without:entries|numeric|min:0',
            'destination_location' => 'nullable|string|max:255',
        ]);

        if (!empty($validated['header_mode'])) {
            $this->syncGroupedEntries($stockIn, $validated);

            return redirect()->back()->with('success', 'Stock In updated successfully');
        }

        $stockIn->update($this->normalizeStockEntry(Arr::except($validated, ['header_mode'])));

        return redirect()->back()->with('success', 'Stock In updated successfully');
    }

    public function destroy(StockIn $stockIn)
    {
        $stockIn->delete();

        return redirect()->back()->with('success', 'Stock In deleted successfully');
    }

    public function post(Request $request, StockIn $stockIn)
    {
        abort_unless($request->user()->can('stock_ins.post'), 403);

        StockIn::where('asset_id', $stockIn->asset_id)
            ->whereDate('receive_date', $stockIn->receive_date)
            ->update([
                'status' => 'Posted',
                'posted_by' => $request->user()->name,
            ]);

        return redirect()->back()->with('success', 'Stock In posted successfully');
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
            'destination_location' => $validated['destination_location'] ?? null,
        ]];

        $relatedRows = StockIn::where('asset_id', $stockIn->asset_id)
            ->whereDate('receive_date', $stockIn->receive_date)
            ->orderBy('id')
            ->get();

        foreach (array_values($entries) as $index => $entry) {
            $payload = [
                'receive_date' => $validated['receive_date'],
                'dr_no' => $validated['dr_no'] ?? null,
                'dr_date' => $validated['dr_date'] ?? null,
                'vendor' => $validated['vendor'] ?? null,
                'origin_location' => $this->normalizeStoreCode($validated['origin_location'] ?? null),
                'received_by' => $validated['received_by'] ?? null,
                'posted_by' => $validated['posted_by'] ?? null,
                'status' => $validated['status'],
                'asset_id' => $validated['asset_id'],
                'quantity' => 1,
                ...$this->normalizeStockEntry(Arr::only($entry, [
                    'serial_no',
                    'barcode',
                    'qrcode',
                    'warranty_months',
                    'eol_months',
                    'cost',
                    'price',
                    'destination_location',
                ])),
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

    protected function normalizeStockEntry(array $entry): array
    {
        if (array_key_exists('origin_location', $entry)) {
            $entry['origin_location'] = $this->normalizeStoreCode($entry['origin_location']);
        }

        if (array_key_exists('destination_location', $entry)) {
            $entry['destination_location'] = $this->normalizeStoreCode($entry['destination_location']);
        }

        return $entry;
    }

    protected function normalizeStoreCode(?string $value): ?string
    {
        if (!$value) {
            return $value;
        }

        $store = Store::query()
            ->where('code', $value)
            ->orWhere('name', $value)
            ->first(['code']);

        return $store?->code ?? $value;
    }
}
