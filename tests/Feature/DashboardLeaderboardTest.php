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
        ]);

        $otherStore = Store::create([
            'code' => 'S002',
            'name' => 'Other Store',
            'sector' => 1,
            'area' => 'South',
            'brand' => 'Brand A',
            'is_active' => true,
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

        $this->actingAs($viewer)
            ->get(route('dashboard', ['year' => 2026, 'month' => 5, 'store_id' => $store->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('leaderboard.rankings', 3)
                ->has('leaderboard.top3', 3)
                ->where('leaderboard.rankings.0.name', 'Alice Tech')
                ->where('leaderboard.rankings.0.total_points', 20)
                ->where('leaderboard.rankings.0.ticket_count', 2)
                ->where('leaderboard.rankings.0.avg_response_min', 30)
                ->where('leaderboard.rankings.0.avg_resolution_min', 150)
                ->where('leaderboard.rankings.0.point_breakdown.0.label', 'Fast Resolution')
                ->where('leaderboard.rankings.1.name', 'Bert Tech')
                ->where('leaderboard.rankings.2.name', 'Cara Tech')
            );

        $this->actingAs($viewer)
            ->get(route('dashboard', ['year' => 2026, 'month' => 5, 'store_id' => $store->id, 'user_id' => $agentB->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('leaderboard.rankings', 1)
                ->where('leaderboard.rankings.0.name', 'Bert Tech')
                ->where('leaderboard.rankings.0.total_points', 15)
            );
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
