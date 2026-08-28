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

    public function test_a_full_card_refuses_the_scan(): void
    {
        $customer = $this->member();
        $program = $this->program(3);
        StampCard::create([
            'customer_id' => $customer->id,
            'stamp_program_id' => $program->id,
            'stamps_count' => 3,
            'status' => 'completed',
        ]);

        $this->actingAs($this->staff())
            ->postJson(route('stamps.scan.add-stamp'), [
                'token' => LoyaltyQrService::encode($customer->id),
                'stamp_program_id' => $program->id,
                'quantity' => 2,
                'purchase_amount' => 250,
            ])
            ->assertStatus(422);
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
