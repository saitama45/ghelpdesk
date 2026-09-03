<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LocatesInventoryUnits;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\StampCard;
use App\Models\StampEntry;
use App\Models\StampProgram;
use App\Models\StampRedemption;
use App\Models\StampRedemptionUnit;
use App\Models\StockIn;
use App\Models\Store;
use App\Services\LoyaltyQrService;
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
            new Middleware('can:stamps.view', only: ['index', 'assetsAtLocation', 'unitsAtLocation', 'cardEntries']),
            new Middleware('can:stamps.create', only: [
                'storeCustomer', 'storeProgram', 'storeCard', 'addStamps', 'recordPurchase',
                'resolveScan', 'scanAddStamp',
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
            // Programs assigned to another entity are hidden; unassigned ones
            // (company_id still null — every row before this scoping existed)
            // stay visible everywhere until explicitly assigned a company, so
            // nothing that was showing yesterday silently disappears today.
            //
            // Explicitly bypasses ActiveEntityScope (auto-applied because
            // StampProgram is in CompanyContext::SCOPED_MODELS) — that scope
            // does a strict equality match with no "or unassigned" allowance,
            // which would silently hide every legacy program the moment
            // company_id existed, contradicting the comment above.
            'programs' => StampProgram::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
                ->with('company:id,code,name')
                ->when($activeCompanyId, fn ($q) => $q->where(
                    fn ($q2) => $q2->where('company_id', $activeCompanyId)->orWhereNull('company_id')
                ))
                ->orderBy('name')
                ->get(),
            'companies' => Company::orderBy('name')->get(['id', 'code', 'name']),
            'cards' => StampCard::with(['customer:id,name,email', 'program:id,name,stamps_required,auto_stamp_amount', 'store:id,code,name'])
                ->when($activeCompanyId, $forActiveEntity('store'))
                ->orderByDesc('id')
                ->get(),
            'redemptions' => StampRedemption::with([
                'customer:id,name',
                'program:id,name',
                'asset:id,item_code,brand,model,description',
                'creator:id,name',
                'units:id,stamp_redemption_id,stock_in_id,serial_no,barcode,qrcode',
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
            // Stamp cards belong to customer-facing outlets only — warehouses and
            // offices never sell to a walk-in customer, so exclude every non-Regular
            // class rather than just filtering the dropdown's label.
            'stores' => Store::query()
                ->where('class', 'Regular')
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
            ...VoucherController::indexProps($activeCompanyId),
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
            'company_id' => 'nullable|exists:companies,id',
            'stamps_required' => 'required|integer|min:1|max:1000',
            'auto_stamp_amount' => 'nullable|numeric|min:0.01',
            'is_active' => 'boolean',
        ]);

        // No explicit default here: AppServiceProvider's `eloquent.creating`
        // listener already stamps the active entity onto company_id when it's
        // left empty (stamp_programs is in CompanyContext::MODULE_TABLES) —
        // the same mechanism every other module's controller relies on.
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
            'company_id' => 'nullable|exists:companies,id',
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

    /**
     * Resolve a scanned member QR to a customer, without committing anything
     * yet. Step 1 of the "Scan Customer" flow — lets the UI show who was
     * scanned (and their existing open cards) before staff pick a Program.
     */
    public function resolveScan(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|max:255']);

        $customerId = LoyaltyQrService::decode($data['token']);
        if (! $customerId) {
            throw ValidationException::withMessages([
                'token' => 'That code is not a valid loyalty member QR.',
            ]);
        }

        $customer = Customer::find($customerId);
        if (! $customer || ! $customer->is_active) {
            throw ValidationException::withMessages([
                'token' => 'That member could not be found or is inactive.',
            ]);
        }

        return response()->json([
            'customer' => $customer->only(['id', 'name', 'email', 'phone']),
            'cards' => $customer->stampCards()
                ->with('program:id,name,stamps_required')
                ->whereIn('status', ['active', 'completed'])
                ->get(['id', 'stamp_program_id', 'stamps_count', 'status']),
        ]);
    }

    /**
     * Step 2 of the "Scan Customer" flow: given the same token plus Program
     * (the field staff actually search/pick), add stamps — reusing the
     * customer's open card for that program if one exists, auto-creating one
     * otherwise. Quantity defaults to 1, the one-scan-one-stamp behaviour this
     * flow started with; staff can raise it for a purchase that earns several
     * at once, exactly like the manual Add Stamps modal.
     *
     * Store can't come from the QR itself — it's generated
     * once on the customer's phone before any store is involved — so the
     * frontend remembers "my current store" per browser (localStorage) and
     * sends it along here; staff pick it once per shift, not per scan.
     * Purchase amount is required (same rule as the manual Add Stamps modal);
     * note is optional.
     */
    public function scanAddStamp(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|max:255',
            'stamp_program_id' => 'required|exists:stamp_programs,id',
            'quantity' => 'nullable|integer|min:1|max:1000',
            'store_id' => 'nullable|exists:stores,id',
            'purchase_amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ]);

        $customerId = LoyaltyQrService::decode($data['token']);
        if (! $customerId) {
            throw ValidationException::withMessages([
                'token' => 'That code is not a valid loyalty member QR.',
            ]);
        }

        $customer = Customer::find($customerId);
        if (! $customer || ! $customer->is_active) {
            throw ValidationException::withMessages([
                'token' => 'That member could not be found or is inactive.',
            ]);
        }

        $applied = 0;

        $card = DB::transaction(function () use ($customer, $data, $request, &$applied) {
            $card = StampCard::where('customer_id', $customer->id)
                ->where('stamp_program_id', $data['stamp_program_id'])
                ->whereIn('status', ['active', 'completed'])
                ->first();

            if (! $card) {
                $card = StampCard::create([
                    'customer_id' => $customer->id,
                    'stamp_program_id' => $data['stamp_program_id'],
                    'store_id' => $data['store_id'] ?? null,
                    'stamps_count' => 0,
                    'status' => 'active',
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
            }

            $applied = $this->applyStamps(
                $card,
                (int) ($data['quantity'] ?? 1),
                'scan',
                $data['purchase_amount'],
                $data['note'] ?? null,
                $request->user()->id,
                $data['store_id'] ?? null,
            );

            return $card->fresh(['customer:id,name', 'program:id,name,stamps_required']);
        });

        // `applied` can be lower than what was asked for: `applyStamps` fills the
        // card and stops. The counter tells the toast what actually happened
        // rather than echoing the request back at the staff member.
        return response()->json(['card' => $card, 'applied' => $applied]);
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
        if (! $program) {
            throw ValidationException::withMessages([
                'purchase_amount' => 'This card has no stamp program assigned. Contact an admin to reassign it to a valid program.',
            ]);
        }
        if (! $program->auto_stamp_amount || (float) $program->auto_stamp_amount <= 0) {
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
     *
     * Returns how many were actually applied, which is what the card had room
     * for rather than what was asked for.
     */
    private function applyStamps(StampCard $card, int $quantity, string $source, $purchaseAmount, ?string $note, int $userId, ?int $storeId): int
    {
        if ($card->status !== 'active') {
            throw ValidationException::withMessages([
                'quantity' => 'Stamps can only be added to an active card.',
            ]);
        }

        // A card's stamp_program_id is a required, FK-constrained column, but a
        // card can still end up pointing at a program that's since become
        // unreachable (e.g. legacy/imported data). Without this check the missing
        // program silently reads as 0 stamps required below, and the card gets
        // reported as "already full" — which is misleading; the real problem is
        // the missing program.
        if (! $card->program) {
            throw ValidationException::withMessages([
                'quantity' => 'This card has no stamp program assigned, so stamps cannot be added. Contact an admin to reassign it to a valid program.',
            ]);
        }

        $required = (int) $card->program->stamps_required;
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

        return $applied;
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
            'stock_in_ids' => 'required|array|min:1|max:1000',
            'stock_in_ids.*' => 'required|integer|distinct|exists:stock_ins,id',
            'remarks' => 'nullable|string|max:255',
        ]);

        $stockInIds = collect($data['stock_in_ids'])->map(fn ($id) => (int) $id)->values();
        if ($stockInIds->count() !== (int) $data['quantity']) {
            throw ValidationException::withMessages([
                'stock_in_ids' => 'Select one specific barcode/QR code for each quantity being redeemed.',
            ]);
        }

        $asset = Asset::findOrFail($data['asset_id']);
        $location = $this->normalizeStoreCode($data['location']);
        $variants = $this->locationVariants($location);

        DB::transaction(function () use ($card, $asset, $location, $variants, $stockInIds, $data, $request) {
            $lockedCard = StampCard::query()->lockForUpdate()->findOrFail($card->id);
            if ($lockedCard->status !== 'completed') {
                throw ValidationException::withMessages([
                    'asset_id' => 'Only a completed card can be redeemed.',
                ]);
            }

            StockIn::query()->whereIn('id', $stockInIds)->lockForUpdate()->get();

            $soh = (int) InventoryTransaction::query()
                ->validInventoryLedger('inventory_transactions', 'stamp_redeem_valid')
                ->where('inventory_transactions.asset_id', $asset->id)
                ->whereIn('inventory_transactions.location', $variants)
                ->sum('inventory_transactions.quantity');

            if ($soh < $stockInIds->count()) {
                throw ValidationException::withMessages([
                    'quantity' => "Insufficient stock at {$location}. Available: {$soh}.",
                ]);
            }

            $availableUnits = $this->redeemableUnitsAt($asset, $variants, $soh)->keyBy('id');
            if ($stockInIds->diff($availableUnits->keys())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'stock_in_ids' => 'One or more selected barcode/QR codes are no longer available at this location.',
                ]);
            }

            $redemption = StampRedemption::create([
                'stamp_card_id' => $lockedCard->id,
                'customer_id' => $lockedCard->customer_id,
                'stamp_program_id' => $lockedCard->stamp_program_id,
                'asset_id' => $asset->id,
                'location' => $location,
                'quantity' => $stockInIds->count(),
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            foreach ($stockInIds as $stockInId) {
                $unit = $availableUnits->get($stockInId);
                StampRedemptionUnit::create([
                    'stamp_redemption_id' => $redemption->id,
                    'stock_in_id' => $unit->id,
                    'serial_no' => $unit->serial_no,
                    'barcode' => $unit->barcode,
                    'qrcode' => $unit->qrcode,
                ]);
            }

            $tx = InventoryTransaction::create([
                'asset_id' => $asset->id,
                'location' => $location,
                'transaction_type' => 'Stamp Redemption',
                'quantity' => -1 * $stockInIds->count(),
                'reference_type' => StampRedemption::class,
                'reference_id' => $redemption->id,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $redemption->update(['inventory_transaction_id' => $tx->id]);

            $lockedCard->update([
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'updated_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', 'Reward redeemed and deducted from inventory.');
    }

    /**
     * Active-entity inventory assets with positive stock-on-hand at a given
     * location for the redemption picker. Mirrors the inventory ledger rules
     * used by StockTransferController::assetsWithStock.
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
            ->orderBy('item_code')
            ->get(['id', 'item_code', 'brand', 'model', 'description', 'type', 'cost'])
            ->map(fn ($a) => array_merge($a->toArray(), [
                'soh' => (int) $sohData->get($a->id, 0),
            ]))
            ->values();

        return response()->json($assets);
    }

    /**
     * Specific, coded stock units that can be selected for a redemption.
     */
    public function unitsAtLocation(Asset $asset, Request $request)
    {
        $location = $this->normalizeStoreCode($request->input('location'));
        if (! $location) {
            return response()->json([]);
        }

        $variants = $this->locationVariants($location);
        $soh = (int) InventoryTransaction::query()
            ->validInventoryLedger('inventory_transactions', 'stamp_unit_valid')
            ->where('inventory_transactions.asset_id', $asset->id)
            ->whereIn('inventory_transactions.location', $variants)
            ->sum('inventory_transactions.quantity');

        if ($soh <= 0) {
            return response()->json([]);
        }

        return response()->json(
            $this->redeemableUnitsAt($asset, $variants, $soh)
                ->map(fn (StockIn $unit) => [
                    'stock_in_id' => $unit->id,
                    'serial_no' => $unit->serial_no,
                    'barcode' => $unit->barcode,
                    'qrcode' => $unit->qrcode,
                ])
                ->values()
        );
    }

    private function redeemableUnitsAt(Asset $asset, array $locationVariants, int $soh)
    {
        return $this->fixedUnitsCurrentlyAt($locationVariants, function ($query) use ($asset) {
            $query->where('stock_ins.asset_id', $asset->id)
                ->whereNotIn('stock_ins.id', StampRedemptionUnit::query()->select('stock_in_id'));
        })
            ->reject(fn (StockIn $unit) => $unit->sourceStockTransfers->contains(
                fn ($transfer) => in_array($transfer->status, ['For Posting', 'Posted'], true)
            ))
            ->filter(fn (StockIn $unit) => filled($unit->barcode) || filled($unit->qrcode))
            ->take(max(0, $soh))
            ->values();
    }
}
