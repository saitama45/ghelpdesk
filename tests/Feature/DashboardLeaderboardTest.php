<?php

namespace Tests\Feature;

use App\Models\AgentPointTransaction;
use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketSlaMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_leaderboard_returns_filtered_rankings_with_details(): void
    {
        $company = Company::create([
            'name' => 'Test Company',
            'code' => 'TC',
            'is_active' => true,
        ]);

        $store = Store::create([
            'code' => 'S001',
            'name' => 'Main Store',
            'sector' => 1,
            'area' => 'North',
            'brand' => 'Brand A',
            'is_active' => true,
            'company_id' => $company->id,
        ]);

        $otherStore = Store::create([
            'code' => 'S002',
            'name' => 'Other Store',
            'sector' => 1,
            'area' => 'South',
            'brand' => 'Brand A',
            'is_active' => true,
            'company_id' => $company->id,
        ]);

        $viewer = User::factory()->create(['company_id' => $company->id]);
        $viewerRole = Role::create(['name' => 'Dashboard Viewer', 'guard_name' => 'web']);
        $viewerRole->companies()->attach($company->id);
        $viewer->assignRole($viewerRole);

        $techRole = Role::create(['name' => 'Tech Role', 'guard_name' => 'web', 'is_assignable' => true]);
        $agentA = $this->tech('Alice Tech', $techRole);
        $agentB = $this->tech('Bert Tech', $techRole);
        $agentC = $this->tech('Cara Tech', $techRole);
        $zeroPointAgent = $this->tech('Zero Tech', $techRole);
        $otherStoreAgent = $this->tech('Other Store Tech', $techRole);

        $this->scoredTicket($company, $store, $agentA, 'fast_resolution', 10, 20, 120, '2026-05-05 10:00:00');
        $this->scoredTicket($company, $store, $agentA, 'happy_customer', 10, 40, 180, '2026-05-06 10:00:00');
        $this->scoredTicket($company, $store, $agentB, 'ontime_resolution', 15, 15, 60, '2026-05-07 10:00:00');
        $this->scoredTicket($company, $store, $agentC, 'late_resolution', -5, 90, 240, '2026-05-08 10:00:00');
        $this->scoredTicket($company, $otherStore, $otherStoreAgent, 'fast_resolution', 100, 5, 30, '2026-05-09 10:00:00');

        // leaderboard is a lazy (Inertia optional) prop — request it the way the
        // dashboard's "Top Techs / Trophies" tab does (partial reload).
        $partial = [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'leaderboard',
            'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
        ];

        $response = $this->actingAs($viewer)
            ->withHeaders($partial)
            ->get(route('dashboard', ['year' => 2026, 'month' => 5, 'store_id' => $store->id]))
            ->assertOk();

        $rankings = $response->json('props.leaderboard.rankings');
        $this->assertCount(3, $rankings);
        $this->assertCount(3, $response->json('props.leaderboard.top3'));
        $this->assertSame('Alice Tech', $rankings[0]['name']);
        $this->assertSame(20, $rankings[0]['total_points']);
        $this->assertSame(2, $rankings[0]['ticket_count']);
        $this->assertSame(30, $rankings[0]['avg_response_min']);
        $this->assertSame(150, $rankings[0]['avg_resolution_min']);
        $this->assertSame('Fast Resolution', $rankings[0]['point_breakdown'][0]['label']);
        $this->assertSame('Bert Tech', $rankings[1]['name']);
        $this->assertSame('Cara Tech', $rankings[2]['name']);

        // Top Brands ranks by the entity that OWNS the store (store ownership), counting
        // the store-filtered tickets — 4 closed tickets sit on the filtered Main Store.
        $topBrands = $response->json('props.leaderboard.topBrands');
        $this->assertCount(1, $topBrands);
        $this->assertSame($company->id, $topBrands[0]['id']);
        $this->assertSame(0, $topBrands[0]['open']);
        $this->assertSame(4, $topBrands[0]['closed']);

        $filtered = $this->actingAs($viewer)
            ->withHeaders($partial)
            ->get(route('dashboard', ['year' => 2026, 'month' => 5, 'store_id' => $store->id, 'user_id' => $agentB->id]))
            ->assertOk();

        $filteredRankings = $filtered->json('props.leaderboard.rankings');
        $this->assertCount(1, $filteredRankings);
        $this->assertSame('Bert Tech', $filteredRankings[0]['name']);
        $this->assertSame(15, $filteredRankings[0]['total_points']);
    }

    private function tech(string $name, Role $role): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    private function scoredTicket(
        Company $company,
        Store $store,
        User $agent,
        string $type,
        int $points,
        int $responseMinutes,
        int $resolutionMinutes,
        string $createdAt
    ): Ticket {
        $created = Carbon::parse($createdAt);

        $ticket = Ticket::create([
            'title' => 'POS issue',
            'description' => 'The terminal needs support.',
            'type' => 'task',
            'status' => 'closed',
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $company->id,
            'store_id' => $store->id,
            'assignee_id' => $agent->id,
            'is_deleted' => false,
        ]);

        $ticket->forceFill([
            'created_at' => $created,
            'updated_at' => $created,
        ])->save();

        TicketSlaMetric::create([
            'ticket_id' => $ticket->id,
            'first_response_at' => $created->copy()->addMinutes($responseMinutes),
            'resolved_at' => $created->copy()->addMinutes($resolutionMinutes),
            'is_response_breached' => false,
            'is_resolution_breached' => false,
        ]);

        AgentPointTransaction::create([
            'agent_id' => $agent->id,
            'ticket_id' => $ticket->id,
            'type' => $type,
            'points' => $points,
            'awarded_at' => $created->copy()->addDay(),
        ]);

        return $ticket;
    }
}
