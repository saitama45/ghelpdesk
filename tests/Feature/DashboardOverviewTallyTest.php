<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardOverviewTallyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Overview tab must tally to the same open/closed universe the Open vs Closed
     * tab and Store Health use: open = anything not terminal, closed = resolved OR
     * closed, and Total = Open + Closed with no status left uncounted.
     */
    public function test_overview_stats_count_resolved_as_closed_and_active_as_open(): void
    {
        $company = Company::create(['name' => 'Tally Brand', 'code' => 'TALLY', 'is_active' => true]);
        $viewer = User::factory()->create(['company_id' => $company->id]);
        // The dashboard counts on-store tickets only, so fixtures sit on an owned store.
        $store = Store::create([
            'code' => 'TAL-1', 'name' => 'Tally Store', 'sector' => 1, 'area' => 'A',
            'brand' => 'B', 'class' => 'Regular', 'is_active' => true, 'company_id' => $company->id,
        ]);

        // Every distinct status bucket, so we can prove the partition holds.
        $this->ticket($company, $store, 'open');
        $this->ticket($company, $store, 'for_schedule');
        $this->ticket($company, $store, 'in_progress');
        $this->ticket($company, $store, 'waiting_service_provider');
        $this->ticket($company, $store, 'waiting_client_feedback');
        $this->ticket($company, $store, 'resolved');
        $this->ticket($company, $store, 'closed');

        $response = $this
            ->actingAs($viewer)
            ->withSession([CompanyContext::SESSION_KEY => $company->id])
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'stats,openTicketsList,closedTicketsList',
                'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            ])
            ->get(route('dashboard', [
                'skip_default_department' => 1,
                'year' => now()->year,
            ]));

        $response->assertOk();

        $stats = $response->json('props.stats');

        // 5 non-terminal statuses are "open"; resolved + closed are "closed".
        $this->assertSame(5, $stats['open']);
        $this->assertSame(2, $stats['closed']);
        $this->assertSame(7, $stats['total']);
        // The partition is exact — nothing falls through the cracks.
        $this->assertSame($stats['total'], $stats['open'] + $stats['closed']);

        // The drill-through lists mirror the tile counts.
        $this->assertCount(5, $response->json('props.openTicketsList'));
        $this->assertCount(2, $response->json('props.closedTicketsList'));
    }

    private function ticket(Company $company, Store $store, string $status): Ticket
    {
        return Ticket::create([
            'title' => "{$company->code} {$status}",
            'description' => 'Overview tally fixture.',
            'type' => 'task',
            'status' => $status,
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $company->id,
            'store_id' => $store->id,
        ]);
    }
}
