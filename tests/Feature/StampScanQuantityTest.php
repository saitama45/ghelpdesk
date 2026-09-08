<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\StampCard;
use App\Models\StampProgram;
use App\Models\User;
use App\Services\LoyaltyQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * "Scan Customer" awards the number of stamps staff enter, not a fixed one —
 * a purchase that earns several is still a single scan at the counter.
 *
 * Runs against the isolated sqlite :memory: connection forced by phpunit.xml.
 */
class StampScanQuantityTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $staff = User::factory()->create();
        $staff->givePermissionTo(Permission::findOrCreate('stamps.create', 'web'));

        return $staff;
    }

    private function program(int $required = 12): StampProgram
    {
        return StampProgram::create([
            'name' => 'CBTL Campaign',
            'year' => 2026,
            'stamps_required' => $required,
            'is_active' => true,
        ]);
    }

    private function member(): Customer
    {
        return Customer::create(['name' => 'Gen', 'email' => 'gen@example.com', 'is_active' => true]);
    }

    public function test_the_requested_number_of_stamps_is_added_in_one_scan(): void
    {
        $customer = $this->member();
        $program = $this->program();

        $response = $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.add-stamp'), [
                'token' => LoyaltyQrService::encode($customer->id),
                'stamp_program_id' => $program->id,
                'quantity' => 4,
                'purchase_amount' => 750,
            ]);

        $response->assertOk();
        $response->assertJsonPath('applied', 4);

        $card = StampCard::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(4, (int) $card->stamps_count);
        $this->assertSame('active', $card->status);
        $this->assertSame(4, (int) $card->entries()->sum('quantity'));
        $this->assertSame('scan', $card->entries()->first()->source);
    }

    public function test_omitting_the_quantity_still_adds_a_single_stamp(): void
    {
        $customer = $this->member();
        $program = $this->program();

        $response = $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.add-stamp'), [
                'token' => LoyaltyQrService::encode($customer->id),
                'stamp_program_id' => $program->id,
                'purchase_amount' => 250,
            ]);

        $response->assertOk();
        $response->assertJsonPath('applied', 1);
        $this->assertSame(1, (int) StampCard::where('customer_id', $customer->id)->value('stamps_count'));
    }

    public function test_more_stamps_than_the_card_holds_fill_it_and_report_what_fit(): void
    {
        $customer = $this->member();
        $program = $this->program(5);
        $card = StampCard::create([
            'customer_id' => $customer->id,
            'stamp_program_id' => $program->id,
            'stamps_count' => 3,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.add-stamp'), [
                'token' => LoyaltyQrService::encode($customer->id),
                'stamp_program_id' => $program->id,
                'quantity' => 9,
                'purchase_amount' => 900,
            ]);

        $response->assertOk();
        // Two of the nine fit; the toast must say two, and the card completes
        // rather than overflowing past stamps_required.
        $response->assertJsonPath('applied', 2);
        $card->refresh();
        $this->assertSame(5, (int) $card->stamps_count);
        $this->assertSame('completed', $card->status);
    }

    public function test_a_quantity_below_one_is_rejected(): void
    {
        $customer = $this->member();
        $program = $this->program();

        $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.add-stamp'), [
                'token' => LoyaltyQrService::encode($customer->id),
                'stamp_program_id' => $program->id,
                'quantity' => 0,
                'purchase_amount' => 250,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(0, StampCard::where('customer_id', $customer->id)->count());
    }

    /**
     * A full card waiting to be redeemed must not cost the customer a sale.
     * They are at the counter buying again; the reward they have already
     * earned stays claimable, and this purchase starts the next cycle on a
     * new card.
     */
    public function test_a_full_unredeemed_card_starts_a_new_cycle_instead_of_refusing(): void
    {
        $customer = $this->member();
        $program = $this->program(3);
        $full = StampCard::create([
            'customer_id' => $customer->id,
            'stamp_program_id' => $program->id,
            'stamps_count' => 3,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.add-stamp'), [
                'token' => LoyaltyQrService::encode($customer->id),
                'stamp_program_id' => $program->id,
                'quantity' => 2,
                'purchase_amount' => 250,
            ]);

        $response->assertOk();
        $response->assertJsonPath('applied', 2);

        // The waiting reward is untouched — still full, still claimable.
        $full->refresh();
        $this->assertSame('completed', $full->status);
        $this->assertSame(3, (int) $full->stamps_count);

        // ...and the purchase opened a second, separate card.
        $cards = StampCard::where('customer_id', $customer->id)
            ->where('stamp_program_id', $program->id)->get();
        $this->assertCount(2, $cards);

        $fresh = $cards->firstWhere('id', '!=', $full->id);
        $this->assertSame('active', $fresh->status);
        $this->assertSame(2, (int) $fresh->stamps_count);
    }

    /**
     * The existing active card is still the one that gets stamped — a second
     * card is only ever opened when there is no active card to add to.
     */
    public function test_an_active_card_is_reused_rather_than_duplicated(): void
    {
        $customer = $this->member();
        $program = $this->program(12);
        $open = StampCard::create([
            'customer_id' => $customer->id,
            'stamp_program_id' => $program->id,
            'stamps_count' => 2,
            'status' => 'active',
        ]);

        $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.add-stamp'), [
                'token' => LoyaltyQrService::encode($customer->id),
                'stamp_program_id' => $program->id,
                'quantity' => 1,
                'purchase_amount' => 250,
            ])
            ->assertOk();

        $this->assertSame(1, StampCard::where('customer_id', $customer->id)
            ->where('stamp_program_id', $program->id)->count());
        $this->assertSame(3, (int) $open->refresh()->stamps_count);
    }

    /**
     * With a full card parked for redemption AND a fresh cycle already
     * running, the scan has to find the active one — not the completed card
     * sitting in front of it.
     */
    public function test_the_active_card_is_picked_over_a_full_one_awaiting_redemption(): void
    {
        $customer = $this->member();
        $program = $this->program(3);
        $full = StampCard::create([
            'customer_id' => $customer->id,
            'stamp_program_id' => $program->id,
            'stamps_count' => 3,
            'status' => 'completed',
        ]);
        $running = StampCard::create([
            'customer_id' => $customer->id,
            'stamp_program_id' => $program->id,
            'stamps_count' => 1,
            'status' => 'active',
        ]);

        $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.add-stamp'), [
                'token' => LoyaltyQrService::encode($customer->id),
                'stamp_program_id' => $program->id,
                'quantity' => 1,
                'purchase_amount' => 250,
            ])
            ->assertOk();

        $this->assertSame(2, (int) $running->refresh()->stamps_count);
        $this->assertSame(3, (int) $full->refresh()->stamps_count);
        $this->assertSame(2, StampCard::where('customer_id', $customer->id)
            ->where('stamp_program_id', $program->id)->count());
    }

    public function test_the_route_stays_behind_stamps_create(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('stamps.scan.add-stamp'), [
                'token' => LoyaltyQrService::encode($this->member()->id),
                'stamp_program_id' => $this->program()->id,
                'quantity' => 2,
                'purchase_amount' => 250,
            ])
            ->assertStatus(403);
    }
}
