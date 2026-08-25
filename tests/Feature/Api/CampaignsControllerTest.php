<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\StampProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function cbtl(): Company
    {
        return Company::create(['name' => 'Coffee Bean & Tea Leaf', 'code' => 'CBTL']);
    }

    private function otherEntity(): Company
    {
        return Company::create(['name' => 'Some Other Brand', 'code' => 'OTHER']);
    }

    private function bearerHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('test-device')->plainTextToken,
        ];
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/campaigns')->assertStatus(401);
    }

    public function test_returns_only_programs_assigned_to_cbtl(): void
    {
        $cbtl = $this->cbtl();
        $user = User::factory()->create();

        StampProgram::create([
            'name' => 'CBTL Campaign', 'year' => 2026, 'stamps_required' => 12,
            'company_id' => $cbtl->id, 'is_active' => true,
        ]);
        StampProgram::create([
            'name' => 'Other Brand Campaign', 'year' => 2026, 'stamps_required' => 10,
            'company_id' => $this->otherEntity()->id, 'is_active' => true,
        ]);

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/campaigns');

        $response->assertStatus(200);
        $names = collect($response->json('campaigns'))->pluck('name');
        $this->assertContains('CBTL Campaign', $names);
        $this->assertNotContains('Other Brand Campaign', $names);
        $this->assertCount(1, $names);
    }

    public function test_unassigned_programs_do_not_leak_into_the_app(): void
    {
        // Unlike the admin Programs tab (which still shows unassigned rows
        // everywhere for backward compatibility), the mobile app must never
        // show a program nobody has confirmed belongs to CBTL.
        $this->cbtl();
        $user = User::factory()->create();

        StampProgram::create([
            'name' => 'Unassigned Legacy Program', 'year' => 2026, 'stamps_required' => 12,
            'company_id' => null, 'is_active' => true,
        ]);

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/campaigns');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('campaigns'));
    }

    public function test_returns_empty_list_rather_than_erroring_when_cbtl_company_row_is_missing(): void
    {
        // No Company::create() for CBTL at all in this test.
        $user = User::factory()->create();
        StampProgram::create([
            'name' => 'Orphan Program', 'year' => 2026, 'stamps_required' => 12,
            'company_id' => null, 'is_active' => true,
        ]);

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/campaigns');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('campaigns'));
    }

    public function test_maps_every_field_the_mobile_app_contract_expects(): void
    {
        $cbtl = $this->cbtl();
        $user = User::factory()->create();

        $program = StampProgram::create([
            'name' => 'Autumn Harvest',
            'year' => 2026,
            'description' => 'Collect 10 stamps on hot drinks',
            'emoji' => '🍂',
            'tag' => 'Hot Drinks',
            'stamps_required' => 10,
            'eligible_items_description' => 'Any hot beverage',
            'reward_description' => 'One free Autumn Harvest Latte',
            'terms_and_conditions' => 'Valid at participating stores.',
            'starts_at' => '2026-01-01 00:00:00',
            'ends_at' => '2026-12-31 23:59:59',
            'display_order' => 1,
            'company_id' => $cbtl->id,
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/campaigns');

        $response->assertStatus(200);
        $campaign = collect($response->json('campaigns'))->firstWhere('name', 'Autumn Harvest');

        $this->assertSame("SP-{$program->id}", $campaign['code']);
        $this->assertSame('Collect 10 stamps on hot drinks', $campaign['description']);
        $this->assertSame('🍂', $campaign['emoji']);
        $this->assertSame('Hot Drinks', $campaign['tag']);
        $this->assertSame(10, $campaign['required_stamps']);
        $this->assertSame('Any hot beverage', $campaign['eligible_items_description']);
        $this->assertSame('One free Autumn Harvest Latte', $campaign['reward_description']);
        $this->assertSame('Valid at participating stores.', $campaign['terms_and_conditions']);
        $this->assertNotNull($campaign['starts_at']);
        $this->assertNotNull($campaign['ends_at']);
        $this->assertTrue($campaign['is_active']);
        $this->assertSame(1, $campaign['display_order']);
    }

    public function test_includes_inactive_campaigns_so_the_app_can_mirror_status(): void
    {
        $cbtl = $this->cbtl();
        $user = User::factory()->create();

        StampProgram::create([
            'name' => 'Retired Campaign', 'year' => 2025, 'stamps_required' => 12,
            'company_id' => $cbtl->id, 'is_active' => false,
        ]);

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/campaigns');

        $response->assertStatus(200);
        $campaign = collect($response->json('campaigns'))->firstWhere('name', 'Retired Campaign');
        $this->assertNotNull($campaign);
        $this->assertFalse($campaign['is_active']);
    }

    public function test_orders_by_display_order_then_id(): void
    {
        $cbtl = $this->cbtl();
        $user = User::factory()->create();

        $second = StampProgram::create([
            'name' => 'Second', 'year' => 2026, 'stamps_required' => 10,
            'company_id' => $cbtl->id, 'display_order' => 2, 'is_active' => true,
        ]);
        $first = StampProgram::create([
            'name' => 'First', 'year' => 2026, 'stamps_required' => 10,
            'company_id' => $cbtl->id, 'display_order' => 1, 'is_active' => true,
        ]);

        $response = $this->withHeaders($this->bearerHeaders($user))->getJson('/api/campaigns');

        $names = collect($response->json('campaigns'))->pluck('name')->values()->all();
        $this->assertSame(['First', 'Second'], $names);
    }
}
