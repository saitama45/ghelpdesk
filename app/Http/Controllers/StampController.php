<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LocatesInventoryUnits;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\StampCard;
use App\Models\StampEntry;
use App\Models\StampProgram;
use App\Models\StampRedemption;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StampController extends Controller implements HasMiddleware
{
    use LocatesInventoryUnits;

    public static function middleware(): array
    {
        return [
            new Middleware('can:stamps.view', only: ['index', 'assetsAtLocation', 'cardEntries']),
            new Middleware('can:stamps.create', only: [
                'storeCustomer', 'storeProgram', 'storeCard', 'addStamps', 'recordPurchase',
            ]),
            new Middleware('can:stamps.edit', only: ['updateCustomer', 'updateProgram']),
            new Middleware('can:stamps.delete', only: ['destroyCustomer', 'destroyProgram', 'destroyCard']),
            new Middleware('can:stamps.redeem', only: ['redeem']),
        ];
    }

    public function index(Request $request)
    {
        // Scope all stamp records to the stores of the active entity (company).
        // Stamp cards/entries/redemptions are tied to a store, and each store
        // belongs to a company, so we filter through that relationship.
        $activeCompanyId = \App\Support\CompanyContext::activeCompanyId();

        $forActiveEntity = fn ($relation) => function ($query) use ($activeCompanyId, $relation) {
            $query->whereHas($relation, fn ($q) => $q->where('company_id', $activeCompanyId));
        };

        return Inertia::render('Stamps/Index', [
            'tab' => $request->get('tab', 'cards'),
            'customers' => Customer::orderBy('name')->get(),
            'programs' => StampProgram::orderBy('name')->get(),
            'cards' => StampCard::with(['customer:id,name,email', 'program:id,name,stamps_required,auto_stamp_amount', 'store:id,code,name'])
                ->when($activeCompanyId, $forActiveEntity('store'))
                ->orderByDesc('id')
                ->get(),
            'redemptions' => StampRedemption::with([
                'customer:id,name',
                'program:id,name',
                'asset:id,item_code,brand,model,description',
                'creator:id,name',
            ])
                ->select('stamp_redemptions.*')
                ->addSelect([
                    'total_purchase_amount' => StampEntry::query()
                        ->selectRaw('COALESCE(SUM(purchase_amount), 0)')
                        ->whereColumn('stamp_entries.stamp_card_id', 'stamp_redemptions.stamp_card_id'),
                ])
                ->when($activeCompanyId, $forActiveEntity('card.store'))
                ->orderByDesc('id')
                ->get(),
            'stores' => Store::query()
                ->when($activeCompanyId, fn ($q) => $q->where('company_id', $activeCompanyId))
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'summary' => [
                'customers' => Customer::count(),
                'active_cards' => StampCard::where('status', 'active')->when($activeCompanyId, $forActiveEntity('store'))->count(),
                'completed_cards' => StampCard::where('status', 'completed')->when($activeCompanyId, $forActiveEntity('store'))->count(),
                'redeemed_cards' => StampCard::where('status', 'redeemed')->when($activeCompanyId, $forActiveEntity('store'))->count(),
                'total_amount' => StampEntry::when($activeCompanyId, $forActiveEntity('store'))->sum('purchase_amount'),
            ],
        ]);
    }

    /* ----------------------------------------------------------------------
     | Customers
     * ------------------------------------------------------------------- */

    public function storeCustomer(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:customers,email',
            'phone' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        Customer::create($data);

        return back()->with('success', 'Customer created.');
    }

    public function updateCustomer(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:customers,email,' . $customer->id,
            'phone' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $data['updated_by'] = $request->user()->id;
        $customer->update($data);

        return back()->with('success', 'Customer updated.');
    }

    public function destroyCustomer(Customer $customer)
    {
        if ($customer->stampCards()->exists()) {
            return back()->with('error', 'Cannot delete a customer that already has stamp cards.');
        }

        $customer->delete();

        return back()->with('success', 'Customer deleted.');
    }

    /* ----------------------------------------------------------------------
     | Programs (configuration: threshold + earning rule)
     * ------------------------------------------------------------------- */

    public function storeProgram(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'year' => 'required|integer|min:2000|max:2100',
            'description' => 'nullable|string|max:1000',
            'stamps_required' => 'required|integer|min:1|max:1000',
            'auto_stamp_amount' => 'nullable|numeric|min:0.01',
            'is_active' => 'boolean',
        ]);

        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        StampProgram::create($data);

        return back()->with('success', 'Stamp program created.');
    }

    public function updateProgram(Request $request, StampProgram $program)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'year' => 'required|integer|min:2000|max:2100',
            'description' => 'nullable|string|max:1000',
            'stamps_required' => 'required|integer|min:1|max:1000',
            'auto_stamp_amount' => 'nullable|numeric|min:0.01',
            'is_active' => 'boolean',
        ]);

        $data['updated_by'] = $request->user()->id;
        $program->update($data);

        return back()->with('success', 'Stamp program updated.');
    }

    public function destroyProgram(StampProgram $program)
    {
        if ($program->stampCards()->exists()) {
            return back()->with('error', 'Cannot delete a program that already has stamp cards.');
        }

        $program->delete();

        return back()->with('success', 'Stamp program deleted.');
    }

    /* ----------------------------------------------------------------------
     | Cards & stamps
     * ------------------------------------------------------------------- */

    public function storeCard(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'stamp_program_id' => 'required|exists:stamp_programs,id',
            'store_id' => 'nullable|exists:stores,id',
        ]);

        $existing = StampCard::where('customer_id', $data['customer_id'])
            ->where('stamp_program_id', $data['stamp_program_id'])
            ->whereIn('status', ['active', 'completed'])
            ->exists();

        if ($existing) {
            return back()->with('error', 'This customer already has an open card for that program.');
        }

        StampCard::create([
            'customer_id' => $data['customer_id'],
            'stamp_program_id' => $data['stamp_program_id'],
            'store_id' => $data['store_id'] ?? null,
            'stamps_count' => 0,
            'status' => 'active',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Stamp card created.');
    }

    public function destroyCard(StampCard $card)
    {
        if ($card->status === 'redeemed') {
            return back()->with('error', 'Cannot delete a redeemed card.');
        }

        $card->delete();

        return back()->with('success', 'Stamp card deleted.');
    }

    public function addStamps(Request $request, StampCard $card)
    {
        $data = $request->validate([
            'quantity' => 'required|integer|min:1|max:1000',
            'purchase_amount' => 'required|numeric|min:0.01',
            'store_id' => 'nullable|exists:stores,id',
            'note' => 'nullable|string|max:255',
        ]);

        $this->applyStamps($card, $data['quantity'], 'manual', $data['purchase_amount'], $data['note'] ?? null, $request->user()->id, $data['store_id'] ?? null);

        return back()->with('success', 'Stamps added.');
    }

    public function cardEntries(StampCard $card)
    {
        $entries = $card->entries()
            ->with(['store:id,code,name', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'card' => $card->load(['customer:id,name', 'program:id,name,stamps_required']),
            'entries' => $entries,
        ]);
    }

    public function recordPurchase(Request $request, StampCard $card)
    {
        $data = $request->validate([
            'purchase_amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        $program = $card->program;
        if (! $program || ! $program->auto_stamp_amount || (float) $program->auto_stamp_amount <= 0) {
            throw ValidationException::withMessages([
                'purchase_amount' => 'This program has no amount-based earning rule configured.',
            ]);
        }

        $earned = (int) floor((float) $data['purchase_amount'] / (float) $program->auto_stamp_amount);
        if ($earned < 1) {
            throw ValidationException::withMessages([
                'purchase_amount' => 'Purchase amount is below the value required to earn a stamp.',
            ]);
        }

        $this->applyStamps($card, $earned, 'purchase', $data['purchase_amount'], $data['note'] ?? null, $request->user()->id, null);

        return back()->with('success', "Recorded purchase — {$earned} stamp(s) earned.");
    }

    /**
     * Apply stamps to a card, capping at the program threshold and flipping
     * the card to "completed" when the threshold is reached.
     */
    private function applyStamps(StampCard $card, int $quantity, string $source, $purchaseAmount, ?string $note, int $userId, ?int $storeId): void
    {
        if ($card->status !== 'active') {
            throw ValidationException::withMessages([
                'quantity' => 'Stamps can only be added to an active card.',
            ]);
        }

        $required = (int) ($card->program->stamps_required ?? 0);
        $remaining = max(0, $required - $card->stamps_count);
        $applied = min($quantity, $remaining);

        if ($applied < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'This card is already full.',
            ]);
        }

        DB::transaction(function () use ($card, $applied, $source, $purchaseAmount, $note, $userId, $storeId, $required) {
            StampEntry::create([
                'stamp_card_id' => $card->id,
                'store_id' => $storeId,
                'quantity' => $applied,
                'source' => $source,
                'purchase_amount' => $purchaseAmount,
                'note' => $note,
                'created_by' => $userId,
            ]);

            $card->stamps_count += $applied;
            $card->updated_by = $userId;

            if ($card->stamps_count >= $required) {
                $card->stamps_count = $required;
                $card->status = 'completed';
                $card->completed_at = now();
            }

            $card->save();
        });
    }

    /* ----------------------------------------------------------------------
     | Redemption (deducts inventory)
     * ------------------------------------------------------------------- */

    public function redeem(Request $request, StampCard $card)
    {
        $data = $request->validate([
            'asset_id' => 'required|exists:assets,id',
            'location' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1|max:1000',
            'remarks' => 'nullable|string|max:255',
        ]);

        if ($card->status !== 'completed') {
            throw ValidationException::withMessages([
                'asset_id' => 'Only a completed card can be redeemed.',
            ]);
        }

        $asset = Asset::findOrFail($data['asset_id']);
        if ($asset->type !== 'Consumables') {
            throw ValidationException::withMessages([
                'asset_id' => 'Only consumable assets can be redeemed.',
            ]);
        }

        $location = $this->normalizeStoreCode($data['location']);
        $variants = $this->locationVariants($location);

        $soh = (int) InventoryTransaction::query()
            ->validInventoryLedger('inventory_transactions', 'stamp_redeem_valid')
            ->where('inventory_transactions.asset_id', $asset->id)
            ->whereIn('inventory_transactions.location', $variants)
            ->sum('inventory_transactions.quantity');

        if ($soh < $data['quantity']) {
            throw ValidationException::withMessages([
                'quantity' => "Insufficient stock at {$location}. Available: {$soh}.",
            ]);
        }

        DB::transaction(function () use ($card, $asset, $location, $data, $request) {
            $redemption = StampRedemption::create([
                'stamp_card_id' => $card->id,
                'customer_id' => $card->customer_id,
                'stamp_program_id' => $card->stamp_program_id,
                'asset_id' => $asset->id,
                'location' => $location,
                'quantity' => $data['quantity'],
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $tx = InventoryTransaction::create([
                'asset_id' => $asset->id,
                'location' => $location,
                'transaction_type' => 'Stamp Redemption',
                'quantity' => -1 * (int) $data['quantity'],
                'reference_type' => StampRedemption::class,
                'reference_id' => $redemption->id,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $redemption->update(['inventory_transaction_id' => $tx->id]);

            $card->update([
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'updated_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', 'Reward redeemed and deducted from inventory.');
    }

    /**
     * Consumable assets with positive stock-on-hand at a given location,
     * for the redemption picker. Mirrors StockTransferController::assetsWithStock
     * but limited to consumable (redeemable) items.
     */
    public function assetsAtLocation(Request $request)
    {
        $location = $this->normalizeStoreCode($request->input('location'));
        if (! $location) {
            return response()->json([]);
        }

        $variants = $this->locationVariants($location);

        $sohData = InventoryTransaction::query()
            ->validInventoryLedger('inventory_transactions', 'stamp_assets_valid')
            ->whereIn('inventory_transactions.location', $variants)
            ->groupBy('inventory_transactions.asset_id')
            ->selectRaw('inventory_transactions.asset_id, SUM(inventory_transactions.quantity) as total')
            ->pluck('total', 'asset_id')
            ->filter(fn ($soh) => $soh > 0);

        if ($sohData->isEmpty()) {
            return response()->json([]);
        }

        $assets = Asset::whereIn('id', $sohData->keys())
            ->where('type', 'Consumables')
            ->orderBy('item_code')
            ->get(['id', 'item_code', 'brand', 'model', 'description', 'type', 'cost'])
            ->map(fn ($a) => array_merge($a->toArray(), [
                'soh' => (int) $sohData->get($a->id, 0),
            ]))
            ->values();

        return response()->json($assets);
    }
}
