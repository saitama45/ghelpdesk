<?php

namespace Tests\Feature;

use App\Models\AgentPointTransaction;
use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use App\Services\BrandHealthService;
use App\Support\DashboardPeriod;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard period filter — Year / Month, or a From–To date range — has to
 * narrow every ticket tab the same way. Live Brand Health, Live Store Health and
 * Asset Operational Health used to ignore it, so picking a month changed nothing.
 */
class DashboardPeriodFilterTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-06 10:00:00');

        $this->alpha = Company::create(['name' => 'Alpha Brand', 'code' => 'ALPHA', 'type' => 'Brand', 'is_active' => true]);
        $one = $this->store('ALP-1');
        $two = $this->store('ALP-2');

        // Open backlog: 5 tickets. June 2026 holds two of them (plus one closed).
        $this->ticket($one, 'open', '2026-06-10 09:00:00');
        $this->ticket($one, 'open', '2026-06-20 09:00:00');
        $this->ticket($one, 'closed', '2026-06-12 09:00:00');
        $this->ticket($two, 'open', '2026-07-05 09:00:00');
        $this->ticket($two, 'open', '2025-06-15 09:00:00'); // June of another year
        $this->ticket($two, 'waiting_client_feedback', '2026-10-01 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_period_reads_year_month_or_a_date_range_but_never_both(): void
    {
        $this->assertTrue(DashboardPeriod::fromArray([])->isEmpty());
        $this->assertNull(DashboardPeriod::fromArray([])->label());

        $this->assertSame('June 2026', DashboardPeriod::fromArray(['year' => '2026', 'month' => '6'])->label());
        $this->assertSame('June, all years', DashboardPeriod::fromArray(['month' => 6])->label());
        $this->assertSame('2026', DashboardPeriod::fromArray(['year' => 2026])->label());

        // A date range wins over year / month.
        $range = DashboardPeriod::fromArray(['year' => 2025, 'month' => 1, 'date_from' => '2026-06-01', 'date_to' => '2026-06-30']);
        $this->assertTrue($range->isRange());
        $this->assertNull($range->year);
        $this->assertNull($range->month);
        $this->assertSame('Jun 1, 2026 – Jun 30, 2026', $range->label());

        // Reversed bounds are read as the range that was meant.
        $reversed = DashboardPeriod::fromArray(['date_from' => '2026-06-30', 'date_to' => '2026-06-01']);
        $this->assertSame(['2026-06-01', '2026-06-30'], [$reversed->from, $reversed->to]);

        // Open-ended ranges are allowed; junk is ignored rather than trusted.
        $this->assertSame('From Jul 1, 2026', DashboardPeriod::fromArray(['date_from' => '2026-07-01'])->label());
        $this->assertSame('Up to Dec 31, 2025', DashboardPeriod::fromArray(['date_to' => '2025-12-31'])->label());
        $this->assertTrue(DashboardPeriod::fromArray(['date_from' => '2026-02-31', 'date_to' => 'yesterday'])->isEmpty());
    }

    public function test_brand_health_counts_only_tickets_created_in_the_period(): void
    {
        $active = fn (array $period) => $this->brandHealth($period)['totals']['active_tickets'];

        $this->assertSame(5, $active([]));
        $this->assertSame(2, $active(['year' => 2026, 'month' => 6]));
        $this->assertSame(3, $active(['month' => 6])); // June of every year
        $this->assertSame(4, $active(['year' => 2026]));

        $this->assertSame(2, $active(['date_from' => '2026-06-01', 'date_to' => '2026-06-30']));
        $this->assertSame(2, $active(['date_from' => '2026-06-15', 'date_to' => '2026-07-31']));
        $this->assertSame(2, $active(['date_from' => '2026-07-01']));
        $this->assertSame(1, $active(['date_to' => '2025-12-31']));
        // The range wins even when a stale year / month rides along.
        $this->assertSame(2, $active(['year' => 2025, 'month' => 1, 'date_from' => '2026-06-01', 'date_to' => '2026-06-30']));

        // Every panel on the tab follows, not just the headline number.
        $june = $this->brandHealth(['year' => 2026, 'month' => 6]);
        $this->assertSame('June 2026', $june['period_label']);
        $this->assertSame(['ALP-1' => 2], collect($june['totals']['top_stores'])->pluck('count', 'code')->all());
        $this->assertSame([], $june['brands'][0]['wcf_register']);
        $this->assertSame(1, $june['totals']['stores_with_tickets']);

        $this->assertNull($this->brandHealth([])['period_label']);
        $this->assertCount(1, $this->brandHealth([])['brands'][0]['wcf_register']);
    }

    public function test_brand_health_drill_downs_carry_the_period(): void
    {
        $viewer = $this->viewer();
        $viewer->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('tickets.view', 'web'));

        // Month: two open + one closed ticket were raised in June 2026.
        $lists = $this->actingAs($viewer)
            ->getJson(route('dashboard.brand-health.top-lists', ['status' => 'all', 'year' => 2026, 'month' => 6]))
            ->assertOk()
            ->json();

        $this->assertSame(3, $lists['total']);
        $this->assertSame(['ALP-1' => 3], collect($lists['top_stores'])->pluck('count', 'code')->all());

        // Date range: the list behind a row is the same three tickets.
        $drill = $this->actingAs($viewer)
            ->getJson(route('dashboard.brand-health.tickets', ['status' => 'all', 'date_from' => '2026-06-01', 'date_to' => '2026-06-30']))
            ->assertOk()
            ->json();

        $this->assertSame(3, $drill['count']);

        // Without a period the whole history comes back.
        $this->assertSame(6, $this->actingAs($viewer)
            ->getJson(route('dashboard.brand-health.tickets', ['status' => 'all']))
            ->assertOk()
            ->json('count'));

        // A malformed date is rejected, never silently treated as "no filter".
        $this->actingAs($viewer)
            ->getJson(route('dashboard.brand-health.tickets', ['date_from' => '06/01/2026']))
            ->assertStatus(422);
    }

    public function test_dashboard_health_tabs_follow_the_month_and_the_date_range(): void
    {
        $viewer = $this->viewer();

        $brandActive = fn (array $query) => $this->lazyProp($viewer, 'brandHealth', $query)['totals']['active_tickets'];
        $storeOpen = fn (array $query) => collect($this->lazyProp($viewer, 'storeHealth', $query)['entityHealth'])->sum('open_tickets');

        // This is the reported bug: the Month filter left Live Brand Health untouched.
        $this->assertSame(5, $brandActive([]));
        $this->assertSame(2, $brandActive(['year' => 2026, 'month' => 6]));
        $this->assertSame(3, $brandActive(['month' => 6]));
        $this->assertSame(2, $brandActive(['date_from' => '2026-06-01', 'date_to' => '2026-06-30']));

        // Live Store Health had the same gap and now tallies with it.
        $this->assertSame(5, $storeOpen([]));
        $this->assertSame(2, $storeOpen(['year' => 2026, 'month' => 6]));
        $this->assertSame(2, $storeOpen(['date_from' => '2026-06-01', 'date_to' => '2026-06-30']));
    }

    public function test_leaderboard_ranks_points_across_a_date_range(): void
    {
        $viewer = $this->viewer();

        $techRole = Role::create(['name' => 'Tech Role', 'guard_name' => 'web', 'is_assignable' => true]);
        $agent = User::factory()->create(['name' => 'Alice Tech']);
        $agent->assignRole($techRole);

        $store = Store::where('code', 'ALP-1')->first();
        $this->points($agent, $this->ticket($store, 'closed', '2026-05-05 09:00:00'), 10, '2026-05-06 09:00:00');
        $this->points($agent, $this->ticket($store, 'closed', '2026-07-09 09:00:00'), 7, '2026-07-10 09:00:00');

        $total = fn (array $query) => collect($this->lazyProp($viewer, 'leaderboard', $query)['rankings'])->sum('total_points');

        $this->assertSame(10, $total(['year' => 2026, 'month' => 5]));
        $this->assertSame(10, $total(['date_from' => '2026-05-01', 'date_to' => '2026-05-31']));
        $this->assertSame(17, $total(['date_from' => '2026-05-01', 'date_to' => '2026-07-31']));
        // Nothing picked still means "this month" (October 2026) — no points yet.
        $this->assertSame(0, $total([]));
    }

    private function brandHealth(array $period): array
    {
        return app(BrandHealthService::class)->build(
            User::factory()->create(),
            null,
            [$this->alpha->id],
            DashboardPeriod::fromArray($period)
        );
    }

    /** Fetch one lazy dashboard tab the way the page does: an Inertia partial reload. */
    private function lazyProp(User $viewer, string $prop, array $query): array
    {
        return $this->actingAs($viewer)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => $prop,
                'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            ])
            ->get(route('dashboard', $query))
            ->assertOk()
            ->json("props.{$prop}");
    }

    private function viewer(): User
    {
        $viewer = User::factory()->create(['company_id' => $this->alpha->id]);
        $role = Role::create(['name' => 'Dashboard Viewer', 'guard_name' => 'web']);
        $role->companies()->attach($this->alpha->id);
        $viewer->assignRole($role);

        return $viewer;
    }

    private function store(string $code): Store
    {
        return Store::create([
            'code' => $code,
            'name' => "Store {$code}",
            'sector' => 1,
            'area' => 'Test Area',
            'brand' => $this->alpha->name,
            'class' => 'Regular',
            'is_active' => true,
            'company_id' => $this->alpha->id,
        ]);
    }

    private function ticket(Store $store, string $status, string $createdAt): Ticket
    {
        $ticket = Ticket::create([
            'title' => "{$store->code} {$status}",
            'description' => 'Dashboard period fixture.',
            'type' => 'task',
            'status' => $status,
            'priority' => 'medium',
            'severity' => 'minor',
            'store_id' => $store->id,
            'company_id' => $store->company_id,
        ]);

        $ticket->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $ticket;
    }

    private function points(User $agent, Ticket $ticket, int $points, string $awardedAt): void
    {
        AgentPointTransaction::create([
            'agent_id' => $agent->id,
            'ticket_id' => $ticket->id,
            'type' => 'fast_resolution',
            'points' => $points,
            'awarded_at' => $awardedAt,
        ]);
    }
}
