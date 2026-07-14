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
        // A legacy value must remain visible and count as non-terminal everywhere,
        // even though new ticket validation only accepts the canonical statuses.
        $this->ticket($company, $store, 'waiting');

        // Inactive-store and child tickets are outside the shared dashboard universe.
        $inactiveStore = Store::create([
            'code' => 'TAL-X', 'name' => 'Inactive Tally Store', 'sector' => 1, 'area' => 'A',
            'brand' => 'B', 'class' => 'Regular', 'is_active' => false, 'company_id' => $company->id,
        ]);
        $this->ticket($company, $inactiveStore, 'open');
        $child = $this->ticket($company, $store, 'open');
        $child->forceFill(['parent_id' => Ticket::where('store_id', $store->id)->firstOrFail()->id])->save();

        $response = $this
            ->actingAs($viewer)
            ->withSession([CompanyContext::SESSION_KEY => $company->id])
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'stats,openTicketsList,closedTicketsList,ticketCharts,storeHealth,kanbanReport',
                'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            ])
            ->get(route('dashboard', [
                'skip_default_department' => 1,
                'year' => now()->year,
            ]));

        $response->assertOk();

        $stats = $response->json('props.stats');
        $charts = $response->json('props.ticketCharts.overall');
        $ticketFlow = $response->json('props.kanbanReport.totals.sub_unit');
        $storeHealthOpen = collect($response->json('props.storeHealth.entityHealth'))->sum('open_tickets')
            + collect($response->json('props.storeHealth.office.entityHealth'))->sum('open_tickets');

        // 5 current non-terminal statuses plus one legacy status are "open";
        // resolved + closed are "closed".
        $this->assertSame(6, $stats['open']);
        $this->assertSame(2, $stats['closed']);
        $this->assertSame(8, $stats['total']);
        // The partition is exact — nothing falls through the cracks.
        $this->assertSame($stats['total'], $stats['open'] + $stats['closed']);
        $this->assertSame($stats['open'], $charts['open']);
        $this->assertSame($stats['closed'], $charts['closed']);
        $this->assertSame($stats['total'], $charts['open'] + $charts['closed']);
        $this->assertSame($stats['open'], $storeHealthOpen);
        $this->assertSame($stats['total'], $ticketFlow['all']);
        $this->assertSame($stats['open'], $ticketFlow['backlogs'] + $ticketFlow['in_progress']);
        $this->assertSame($stats['closed'], $ticketFlow['resolved'] + $ticketFlow['closed']);

        // The drill-through lists mirror the tile counts.
        $this->assertCount(6, $response->json('props.openTicketsList'));
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
