<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Customer;
use App\Models\StampCard;
use App\Models\StampEntry;
use App\Models\StampProgram;
use App\Models\StampRedemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Deleting a stamp card must not be able to erase a customer's history.
 *
 * `stamp_entries.stamp_card_id` is `cascadeOnDelete()`, so removing a card
 * takes every stamp earned on it too. Guarding on `status === 'redeemed'`
 * alone was not enough: a card at 11 of 12 stamps is not redeemed, so it
 * could be deleted in one click and its months of history went with it.
 *
 * Deletion is now for one thing only — clearing a card created by mistake,
 * before anything was recorded against it.
 *
 * Runs against the isolated sqlite :memory: connection forced by phpunit.xml.
 */
class StampCardDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $staff = User::factory()->create();
        $staff->givePermissionTo(Permission::findOrCreate('stamps.delete', 'web'));

        return $staff;
    }

    private function card(string $status = 'active', int $stamps = 0): StampCard
    {
        $customer = Customer::create([
            'name' => 'Gen', 'email' => 'gen' . uniqid() . '@example.com', 'is_active' => true,
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
        ]);
    }

    public function test_a_card_with_earned_stamps_cannot_be_deleted(): void
    {
        $card = $this->card(stamps: 11);
        StampEntry::create([
            'stamp_card_id' => $card->id,
            'quantity' => 11,
            'source' => 'scan',
            'purchase_amount' => 900,
        ]);

        $this->actingAs($this->staff())
            ->delete(route('stamps.cards.destroy', $card->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('stamp_cards', ['id' => $card->id]);
        $this->assertSame(1, StampEntry::where('stamp_card_id', $card->id)->count(),
            'the cascade must never have had the chance to run');
    }

    public function test_a_redeemed_card_still_cannot_be_deleted(): void
    {
        $card = $this->card('redeemed', stamps: 12);

        $this->actingAs($this->staff())
            ->delete(route('stamps.cards.destroy', $card->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('stamp_cards', ['id' => $card->id]);
    }

    public function test_a_card_carrying_a_redemption_cannot_be_deleted(): void
    {
        // Status says otherwise, but the redemption is the history that matters.
        $card = $this->card('completed', stamps: 12);

        $category = Category::create(['name' => 'Loyalty Rewards', 'is_active' => true]);
        $asset = Asset::create([
            'item_code' => 'SKU100081',
            'category_id' => $category->id,
            'description' => 'OREO TUMBLER',
            'type' => 'Fixed',
            'cost' => 1000,
            'is_active' => true,
        ]);

        StampRedemption::create([
            'stamp_card_id' => $card->id,
            'customer_id' => $card->customer_id,
            'stamp_program_id' => $card->stamp_program_id,
            'asset_id' => $asset->id,
            'location' => 'CBTL EWM',
            'quantity' => 1,
        ]);

        $this->actingAs($this->staff())
            ->delete(route('stamps.cards.destroy', $card->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('stamp_cards', ['id' => $card->id]);
    }

    public function test_an_empty_card_created_by_mistake_can_still_be_removed(): void
    {
        // The one case deletion is actually for — nothing recorded against it.
        $card = $this->card();

        $this->actingAs($this->staff())
            ->delete(route('stamps.cards.destroy', $card->id))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('stamp_cards', ['id' => $card->id]);
    }

    public function test_deletion_still_requires_the_delete_permission(): void
    {
        $card = $this->card();
        $nobody = User::factory()->create();

        $this->actingAs($nobody)
            ->delete(route('stamps.cards.destroy', $card->id))
            ->assertForbidden();

        $this->assertDatabaseHas('stamp_cards', ['id' => $card->id]);
    }
}
