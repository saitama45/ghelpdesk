<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\StampCard;
use App\Models\StampEntry;
use App\Models\StampProgram;
use App\Models\User;
use App\Services\LoyaltyQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Holiday slots and the per-member card limit (StampAllocator).
 *
 * The program under test mirrors the paper holiday card: 20 stamps, 4 of them
 * Holiday slots and 16 Regular, at most 3 cards per member.
 *
 * Runs against the isolated sqlite :memory: connection forced by phpunit.xml.
 */
class StampHolidaySlotsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $this->staff = User::factory()->create();
        $this->staff->givePermissionTo(
            Permission::findOrCreate('stamps.create', 'web'),
            Permission::findOrCreate('stamps.edit', 'web'),
        );
    }

    private function program(array $overrides = []): StampProgram
    {
        return StampProgram::create(array_merge([
            'name' => '2026 Holiday Campaign',
            'year' => 2026,
            'stamps_required' => 20,
            'holiday_stamps' => 4,
            'max_cards_per_customer' => 3,
            'is_active' => true,
        ], $overrides));
    }

    private function member(): Customer
    {
        return Customer::create(['name' => 'Gen', 'email' => 'gen@example.com', 'is_active' => true]);
    }

    private function scan(Customer $customer, StampProgram $program, string $type, int $quantity = 1, float $amount = 250): TestResponse
    {
        return $this->actingAs($this->staff)->postJson(route('stamps.scan.add-stamp'), [
            'token' => LoyaltyQrService::encode($customer->id),
            'stamp_program_id' => $program->id,
            'stamp_type' => $type,
            'quantity' => $quantity,
            'purchase_amount' => $amount,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, StampCard> */
    private function cards(Customer $customer, StampProgram $program)
    {
        return StampCard::where('customer_id', $customer->id)
            ->where('stamp_program_id', $program->id)
            ->orderBy('id')
            ->get();
    }

    public function test_the_fifth_holiday_stamp_goes_to_the_second_card(): void
    {
        $customer = $this->member();
        $program = $this->program();

        for ($i = 0; $i < 5; $i++) {
            $this->scan($customer, $program, 'holiday')->assertOk()->assertJsonPath('holiday', 1);
        }

        $cards = $this->cards($customer, $program);
        $this->assertCount(2, $cards);

        // Card 1's holiday slots are full while its 16 regular slots are
        // still empty — the holiday stamp moved on rather than waiting.
        $this->assertSame(4, $cards[0]->holiday_count);
        $this->assertSame(4, $cards[0]->stamps_count);
        $this->assertSame('active', $cards[0]->status);
        $this->assertSame(1, $cards[1]->holiday_count);
        $this->assertSame(1, $cards[1]->stamps_count);

        $this->assertSame(5, (int) StampEntry::where('stamp_type', 'holiday')->sum('quantity'));
    }

    public function test_a_regular_stamp_fills_the_oldest_card_first(): void
    {
        $customer = $this->member();
        $program = $this->program();
        $this->scan($customer, $program, 'holiday', 5)->assertOk();

        $this->scan($customer, $program, 'regular')->assertOk()->assertJsonPath('regular', 1);

        $cards = $this->cards($customer, $program);
        $this->assertSame(5, $cards[0]->stamps_count);
        $this->assertSame(4, $cards[0]->holiday_count);
        $this->assertSame(1, $cards[1]->stamps_count);
        $this->assertSame('regular', StampEntry::latest('id')->value('stamp_type'));
    }

    public function test_one_purchase_worth_several_holiday_stamps_spreads_over_cards(): void
    {
        $customer = $this->member();
        $program = $this->program();

        $this->scan($customer, $program, 'holiday', 6, 900)
            ->assertOk()
            ->assertJsonPath('applied', 6)
            ->assertJsonPath('holiday', 6)
            ->assertJsonCount(2, 'card_ids');

        $cards = $this->cards($customer, $program);
        $this->assertSame([4, 2], $cards->pluck('holiday_count')->all());

        // One entry per card, and the sale is recorded once, not per card.
        $this->assertSame(2, StampEntry::count());
        $this->assertSame(900.0, (float) StampEntry::sum('purchase_amount'));
    }

    public function test_once_every_allowed_card_has_its_holiday_slots_filled_holiday_becomes_regular(): void
    {
        $customer = $this->member();
        $program = $this->program();
        $this->scan($customer, $program, 'holiday', 12)->assertOk();
        $this->assertCount(3, $this->cards($customer, $program));

        $response = $this->scan($customer, $program, 'holiday')->assertOk();

        $response->assertJsonPath('holiday', 0)
            ->assertJsonPath('regular', 1)
            ->assertJsonPath('converted', 1);
        $this->assertStringContainsString('became Regular', $response->json('message'));

        // No fourth card: the converted stamp took card 1's first regular slot.
        $cards = $this->cards($customer, $program);
        $this->assertCount(3, $cards);
        $this->assertSame(5, $cards[0]->stamps_count);
        $this->assertSame(4, $cards[0]->holiday_count);
        $this->assertSame('regular', StampEntry::latest('id')->value('stamp_type'));
    }

    public function test_regular_stamps_past_a_full_card_open_the_next_one(): void
    {
        $customer = $this->member();
        $program = $this->program();

        // 16 regular slots on card 1, so the 17th regular stamp cannot go
        // there even though its 4 holiday slots are empty.
        $this->scan($customer, $program, 'regular', 17)->assertOk()->assertJsonPath('applied', 17);

        $cards = $this->cards($customer, $program);
        $this->assertSame([16, 1], $cards->pluck('stamps_count')->all());
        $this->assertSame('active', $cards[0]->status, 'card 1 still has 4 empty holiday slots');

        $this->scan($customer, $program, 'holiday', 4)->assertOk();
        $card = $cards[0]->refresh();
        $this->assertSame(20, $card->stamps_count);
        $this->assertSame('completed', $card->status);
    }

    public function test_stamps_past_the_card_limit_are_refused_and_said_so(): void
    {
        $customer = $this->member();
        $program = $this->program(['stamps_required' => 2, 'holiday_stamps' => 0, 'max_cards_per_customer' => 1]);

        $response = $this->scan($customer, $program, 'regular', 3)->assertOk();
        $response->assertJsonPath('applied', 2)->assertJsonPath('refused', 1);
        $this->assertStringContainsString('limit of 1 card', $response->json('message'));

        // A full card counts against the limit until — and after — it is
        // redeemed, so the next purchase is turned away outright.
        $this->scan($customer, $program, 'regular')
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
        $this->assertCount(1, $this->cards($customer, $program));
    }

    public function test_redeemed_cards_count_against_the_limit(): void
    {
        $customer = $this->member();
        $program = $this->program(['max_cards_per_customer' => 1]);
        StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $program->id,
            'stamps_count' => 20, 'holiday_count' => 4, 'status' => 'redeemed',
        ]);

        $this->scan($customer, $program, 'regular')->assertStatus(422);
    }

    public function test_a_program_without_holiday_slots_takes_holiday_as_plain_regular(): void
    {
        $customer = $this->member();
        $program = $this->program(['stamps_required' => 12, 'holiday_stamps' => 0, 'max_cards_per_customer' => null]);

        $this->scan($customer, $program, 'holiday')
            ->assertOk()
            ->assertJsonPath('regular', 1)
            ->assertJsonPath('converted', 0);
    }

    public function test_add_stamps_on_a_card_uses_the_same_placement(): void
    {
        $customer = $this->member();
        $program = $this->program();
        $this->scan($customer, $program, 'holiday', 4)->assertOk();
        $card = $this->cards($customer, $program)->first();

        $this->actingAs($this->staff)
            ->post(route('stamps.cards.add-stamps', $card), [
                'stamp_type' => 'holiday',
                'quantity' => 1,
                'purchase_amount' => 180,
            ])
            ->assertSessionHas('success');

        $cards = $this->cards($customer, $program);
        $this->assertCount(2, $cards);
        $this->assertSame(1, $cards[1]->holiday_count);
    }

    public function test_holiday_slots_cannot_exceed_the_cards_stamps(): void
    {
        $this->actingAs($this->staff)
            ->post(route('stamps.programs.store'), [
                'name' => 'Too many', 'year' => 2026, 'stamps_required' => 20,
                'holiday_stamps' => 21, 'is_active' => true,
            ])
            ->assertSessionHasErrors('holiday_stamps');

        $this->actingAs($this->staff)
            ->post(route('stamps.programs.store'), [
                'name' => 'Holiday', 'year' => 2026, 'stamps_required' => 20,
                'holiday_stamps' => 4, 'max_cards_per_customer' => 3, 'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $program = StampProgram::where('name', 'Holiday')->firstOrFail();
        $this->assertSame(4, $program->holiday_stamps);
        $this->assertSame(3, $program->max_cards_per_customer);
    }

    public function test_a_blank_holiday_field_saves_as_none(): void
    {
        $program = $this->program();

        $this->actingAs($this->staff)
            ->put(route('stamps.programs.update', $program), [
                'name' => $program->name, 'year' => 2026, 'stamps_required' => 20,
                'holiday_stamps' => null, 'max_cards_per_customer' => null, 'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $program->refresh();
        $this->assertSame(0, $program->holiday_stamps);
        $this->assertNull($program->max_cards_per_customer);
    }

    public function test_a_card_cannot_be_opened_by_hand_past_the_limit(): void
    {
        $customer = $this->member();
        $program = $this->program(['max_cards_per_customer' => 1]);
        StampCard::create([
            'customer_id' => $customer->id, 'stamp_program_id' => $program->id,
            'stamps_count' => 20, 'holiday_count' => 4, 'status' => 'redeemed',
        ]);

        $this->actingAs($this->staff)
            ->post(route('stamps.cards.store'), [
                'customer_id' => $customer->id,
                'stamp_program_id' => $program->id,
            ])
            ->assertSessionHas('error');

        $this->assertCount(1, $this->cards($customer, $program));
    }

    public function test_resolve_scan_numbers_the_open_cards_and_counts_cards_held(): void
    {
        $customer = $this->member();
        $program = $this->program();
        $this->scan($customer, $program, 'holiday', 5)->assertOk();

        $response = $this->actingAs($this->staff)
            ->postJson(route('stamps.scan.resolve'), ['token' => LoyaltyQrService::encode($customer->id)])
            ->assertOk();

        $response->assertJsonPath('cards.0.card_number', 1)
            ->assertJsonPath('cards.0.holiday_filled', 4)
            ->assertJsonPath('cards.1.card_number', 2)
            ->assertJsonPath('cards.1.holiday_filled', 1)
            ->assertJsonPath("cards_held.{$program->id}", 2);
    }
}
