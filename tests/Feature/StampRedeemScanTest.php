<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\StampCard;
use App\Models\StampProgram;
use App\Models\User;
use App\Services\LoyaltyRedeemQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * "Scan Redeem QR" — the member taps Redeem Now in the mobile app, staff scan
 * the code it shows, and this resolves it to the one full card it authorizes.
 *
 * The cases that matter are the refusals: a code names a card, so the only
 * thing stopping a screenshotted code from being spent twice is that the
 * card's status has moved on. That check is asserted here directly.
 *
 * Runs against the isolated sqlite :memory: connection forced by phpunit.xml.
 */
class StampRedeemScanTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $staff = User::factory()->create();
        $staff->givePermissionTo(Permission::findOrCreate('stamps.redeem', 'web'));

        return $staff;
    }

    private function card(string $status, int $stamps = 12): StampCard
    {
        $customer = Customer::create([
            'name' => 'Gen', 'email' => 'gen@example.com', 'is_active' => true,
        ]);
        $program = StampProgram::create([
            'name' => 'CBTL Campaign', 'year' => 2026,
            'stamps_required' => 12, 'is_active' => true,
        ]);

        return StampCard::create([
            'customer_id' => $customer->id,
            'stamp_program_id' => $program->id,
            'stamps_count' => $stamps,
            'status' => $status,
            'redeemed_at' => $status === 'redeemed' ? now() : null,
        ]);
    }

    public function test_a_full_cards_code_resolves_to_that_card(): void
    {
        $card = $this->card('completed');

        $response = $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.resolve-redeem'), [
                'token' => LoyaltyRedeemQrService::encode($card),
            ]);

        $response->assertOk();
        $response->assertJsonPath('card.id', $card->id);
        $response->assertJsonPath('card.customer.name', 'Gen');
        $response->assertJsonPath('card.program.name', 'CBTL Campaign');
    }

    public function test_an_already_redeemed_cards_code_is_refused(): void
    {
        $card = $this->card('redeemed');

        $response = $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.resolve-redeem'), [
                'token' => LoyaltyRedeemQrService::encode($card),
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'already been redeemed',
            $response->json('errors.token.0'),
        );
    }

    public function test_a_card_that_is_not_full_yet_is_refused(): void
    {
        $card = $this->card('active', stamps: 4);

        $response = $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.resolve-redeem'), [
                'token' => LoyaltyRedeemQrService::encode($card),
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('not full yet', $response->json('errors.token.0'));
    }

    public function test_a_tampered_code_is_refused(): void
    {
        $card = $this->card('completed');
        // Same card id, signature from a different one — the whole point of
        // signing is that this cannot be forged by editing the payload.
        $forged = 'LRDM1:' . $card->id . ':' . str_repeat('a', 24);

        $response = $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.resolve-redeem'), ['token' => $forged]);

        $response->assertStatus(422);
        $this->assertStringContainsString('not a valid', $response->json('errors.token.0'));
    }

    public function test_a_member_code_is_not_accepted_as_a_redemption_code(): void
    {
        $card = $this->card('completed');

        $response = $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.resolve-redeem'), [
                'token' => \App\Services\LoyaltyQrService::encode($card->customer_id),
            ]);

        $response->assertStatus(422);
    }

    public function test_staff_without_the_redeem_permission_cannot_resolve_a_code(): void
    {
        $card = $this->card('completed');
        $nobody = User::factory()->create();

        $response = $this->actingAs($nobody)
            ->postJson(route('stamps.scan.resolve-redeem'), [
                'token' => LoyaltyRedeemQrService::encode($card),
            ]);

        $response->assertForbidden();
    }

    public function test_the_signed_code_round_trips_and_is_card_specific(): void
    {
        $one = $this->card('completed');

        $this->assertSame($one->id, LoyaltyRedeemQrService::decode(
            LoyaltyRedeemQrService::encode($one),
        ));
        // A code for card N must not validate as card N+1.
        $this->assertNull(LoyaltyRedeemQrService::decode(
            'LRDM1:' . ($one->id + 1) . ':' . explode(':', LoyaltyRedeemQrService::encode($one))[2],
        ));
        $this->assertNull(LoyaltyRedeemQrService::decode('nonsense'));
    }
}
