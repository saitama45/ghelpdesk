<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\StampCard;
use App\Models\StampEntry;
use App\Models\StampProgram;
use App\Models\StampRedemption;
use App\Models\Store;
use App\Models\User;
use App\Services\LoyaltyQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyMemberControllerTest extends TestCase
{
    use RefreshDatabase;

    private function cbtl(): Company
    {
        return Company::create(['name' => 'Coffee Bean & Tea Leaf', 'code' => 'CBTL']);
    }

    private function memberWithCustomer(Company $cbtl): array
    {
        $customer = Customer::create(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'is_active' => true]);
        $user = User::factory()->create(['customer_id' => $customer->id]);

        return [$user, $customer];
    }

    private function program(Company $cbtl, array $overrides = []): StampProgram
    {
        return StampProgram::create(array_merge([
            'name' => 'CBTL Campaign', 'year' => 2026, 'stamps_required' => 12,
            'company_id' => $cbtl->id, 'is_active' => true,
        ], $overrides));
    }

    private function bearerHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test-device')->plainTextToken];
    }

    // ── GET /api/loyalty/qr-card ─────────────────────────────────────────

    public function test_qr_card_requires_authentication(): void
    {
        $this->getJson('/api/loyalty/qr-card')->assertStatus(401);
    }

    public function test_qr_card_returns_a_token_that_decodes_back_to_the_customer(): void
    {
        $cbtl = $this->cbtl();
        [$user, $customer] = $this->memberWithCustomer($cbtl);

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/loyalty/qr-card');

        $response->assertStatus(200);
        $this->assertSame($customer->id, LoyaltyQrService::decode($response->json('token')));
    }

    public function test_qr_card_422s_for_a_user_with_no_linked_customer(): void
    {
        $user = User::factory()->create(); // no customer_id — a staff account

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/loyalty/qr-card');

        $response->assertStatus(422);
    }

    // ── GET /api/loyalty/my-cards ─────────────────────────────────────────

    public function test_my_cards_returns_only_cbtl_scoped_progress(): void
    {
        $cbtl = $this->cbtl();
        $other = Company::create(['name' => 'Other Brand', 'code' => 'OTHER']);
        [$user, $customer] = $this->memberWithCustomer($cbtl);

        $cbtlProgram = $this->program($cbtl);
        $otherProgram = $this->program($other, ['name' => 'Other Campaign', 'company_id' => $other->id]);

        StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $cbtlProgram->id,
            'stamps_count' => 4, 'status' => 'active',
        ]);
        StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $otherProgram->id,
            'stamps_count' => 2, 'status' => 'active',
        ]);

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/loyalty/my-cards');

        $response->assertStatus(200);
        $cards = $response->json('cards');
        $this->assertCount(1, $cards);
        $this->assertSame("SP-{$cbtlProgram->id}", $cards[0]['code']);
        $this->assertSame(4, $cards[0]['stamps_count']);
        $this->assertSame(12, $cards[0]['stamps_required']);
    }

    public function test_my_cards_reports_the_holiday_split_and_card_numbers(): void
    {
        $cbtl = $this->cbtl();
        [$user, $customer] = $this->memberWithCustomer($cbtl);
        $program = $this->program($cbtl, [
            'stamps_required' => 20, 'holiday_stamps' => 4, 'max_cards_per_customer' => 3,
        ]);

        StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $program->id,
            'stamps_count' => 20, 'holiday_count' => 4, 'status' => 'redeemed',
        ]);
        StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $program->id,
            'stamps_count' => 6, 'holiday_count' => 4, 'status' => 'active',
        ]);
        StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $program->id,
            'stamps_count' => 1, 'holiday_count' => 1, 'status' => 'active',
        ]);

        $cards = $this->withHeaders($this->bearerHeaders($user))
            ->getJson('/api/loyalty/my-cards')
            ->assertOk()
            ->json('cards');

        $this->assertSame([1, 2, 3], array_column($cards, 'card_number'));
        $this->assertSame(3, $cards[1]['max_cards']);
        $this->assertSame(4, $cards[1]['holiday_stamps']);
        $this->assertSame(4, $cards[1]['holiday_count']);
        $this->assertSame(2, $cards[1]['regular_count']);
        $this->assertSame(1, $cards[2]['holiday_count']);
        $this->assertSame(0, $cards[2]['regular_count']);
    }

    public function test_my_transactions_shows_a_deduction_with_its_reason_but_hides_ordinary_notes(): void
    {
        $cbtl = $this->cbtl();
        [$user, $customer] = $this->memberWithCustomer($cbtl);
        $program = $this->program($cbtl);
        $card = StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $program->id,
            'stamps_count' => 1, 'status' => 'active',
        ]);
        StampEntry::create([
            'stamp_card_id' => $card->id, 'quantity' => 2, 'source' => 'scan',
            'purchase_amount' => 300, 'note' => 'staff-only remark',
        ]);
        StampEntry::create([
            'stamp_card_id' => $card->id, 'quantity' => -1, 'source' => 'deduction',
            'note' => 'Typed 2 stamps instead of 1',
        ]);

        $rows = collect($this->withHeaders($this->bearerHeaders($user))
            ->getJson('/api/loyalty/my-transactions')
            ->assertOk()
            ->json('transactions'))->keyBy('points');

        $this->assertSame('earn', $rows[-1]['type']);
        $this->assertSame('Stamp correction', $rows[-1]['product_name']);
        $this->assertSame('Typed 2 stamps instead of 1', $rows[-1]['note']);
        $this->assertNull($rows[2]['note'], 'an ordinary stamp\'s staff note never reaches the member');
        $this->assertNull($rows[2]['product_name']);
    }

    public function test_campaigns_expose_holiday_slots_and_the_card_limit(): void
    {
        $cbtl = $this->cbtl();
        [$user] = $this->memberWithCustomer($cbtl);
        $this->program($cbtl, ['stamps_required' => 20, 'holiday_stamps' => 4, 'max_cards_per_customer' => 3]);
        $this->program($cbtl, ['name' => 'Plain']);

        $campaigns = collect($this->withHeaders($this->bearerHeaders($user))
            ->getJson('/api/campaigns')
            ->assertOk()
            ->json('campaigns'))->keyBy('name');

        $this->assertSame(4, $campaigns['CBTL Campaign']['holiday_stamps']);
        $this->assertSame(3, $campaigns['CBTL Campaign']['max_cards_per_customer']);
        $this->assertSame(0, $campaigns['Plain']['holiday_stamps']);
        $this->assertNull($campaigns['Plain']['max_cards_per_customer']);
    }

    public function test_my_cards_is_empty_for_a_user_with_no_linked_customer(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/loyalty/my-cards');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('cards'));
    }

    // ── GET /api/loyalty/my-transactions ────────────────────────────────

    public function test_my_transactions_maps_a_real_scan_as_an_earn(): void
    {
        $cbtl = $this->cbtl();
        [$user, $customer] = $this->memberWithCustomer($cbtl);
        $program = $this->program($cbtl);
        $store = Store::create([
            'code' => 'A30', 'name' => 'CBTL Ayala 30th', 'company_id' => $cbtl->id,
            'sector' => 1, 'area' => 'Metro Manila', 'brand' => 'CBTL', 'cluster' => 'A',
        ]);

        $card = StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $program->id,
            'stamps_count' => 1, 'status' => 'active',
        ]);
        $entry = StampEntry::create([
            'stamp_card_id' => $card->id, 'store_id' => $store->id,
            'quantity' => 1, 'source' => 'scan',
        ]);

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/loyalty/my-transactions');

        $response->assertStatus(200);
        $txn = $response->json('transactions')[0];
        $this->assertSame("SE-{$entry->id}", $txn['reference']);
        $this->assertSame('earn', $txn['type']);
        $this->assertSame(1, $txn['points']);
        $this->assertSame("SP-{$program->id}", $txn['campaign_code']);
        $this->assertSame('A30 — CBTL Ayala 30th', $txn['store_name']);
        $this->assertNotNull($txn['occurred_at']);
    }

    public function test_my_transactions_maps_a_redemption_with_negative_points_for_the_full_card(): void
    {
        $cbtl = $this->cbtl();
        [$user, $customer] = $this->memberWithCustomer($cbtl);
        $program = $this->program($cbtl, ['stamps_required' => 12]);
        $category = Category::create(['name' => 'Consumables']);
        $asset = Asset::create([
            'item_code' => 'ASSET-1', 'description' => 'Free Latte', 'type' => 'Consumables',
            'category_id' => $category->id,
        ]);

        $card = StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $program->id,
            'stamps_count' => 12, 'status' => 'redeemed',
        ]);
        $redemption = StampRedemption::create([
            'stamp_card_id' => $card->id, 'customer_id' => $customer->id,
            'stamp_program_id' => $program->id, 'asset_id' => $asset->id,
            'location' => 'CBTL Ayala 30th', 'quantity' => 1,
        ]);

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/loyalty/my-transactions');

        $response->assertStatus(200);
        $txn = $response->json('transactions')[0];
        $this->assertSame("SR-{$redemption->id}", $txn['reference']);
        $this->assertSame('redeem', $txn['type']);
        // The whole card's worth, not the reward item count — matches the
        // Flutter app's own local redeemReward convention.
        $this->assertSame(-12, $txn['points']);
        $this->assertSame('Free Latte', $txn['product_name']);
        $this->assertSame('CBTL Ayala 30th', $txn['store_name']);
    }

    public function test_my_transactions_excludes_cards_outside_cbtl(): void
    {
        $cbtl = $this->cbtl();
        $other = Company::create(['name' => 'Other Brand', 'code' => 'OTHER']);
        [$user, $customer] = $this->memberWithCustomer($cbtl);
        $otherProgram = $this->program($other, ['name' => 'Other', 'company_id' => $other->id]);

        $card = StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $otherProgram->id,
            'stamps_count' => 1, 'status' => 'active',
        ]);
        StampEntry::create(['stamp_card_id' => $card->id, 'quantity' => 1, 'source' => 'scan']);

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/loyalty/my-transactions');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('transactions'));
    }

    public function test_my_transactions_orders_newest_first(): void
    {
        $cbtl = $this->cbtl();
        [$user, $customer] = $this->memberWithCustomer($cbtl);
        $program = $this->program($cbtl);
        $card = StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $program->id,
            'stamps_count' => 2, 'status' => 'active',
        ]);

        $old = StampEntry::create(['stamp_card_id' => $card->id, 'quantity' => 1, 'source' => 'scan']);
        $old->created_at = now()->subDays(2);
        $old->save();

        $new = StampEntry::create(['stamp_card_id' => $card->id, 'quantity' => 1, 'source' => 'scan']);
        $new->created_at = now();
        $new->save();

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/loyalty/my-transactions');

        $refs = collect($response->json('transactions'))->pluck('reference')->all();
        $this->assertSame(["SE-{$new->id}", "SE-{$old->id}"], $refs);
    }

    public function test_my_transactions_is_empty_for_a_user_with_no_linked_customer(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/loyalty/my-transactions');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('transactions'));
    }

    public function test_my_transactions_requires_authentication(): void
    {
        $this->getJson('/api/loyalty/my-transactions')->assertStatus(401);
    }
}
