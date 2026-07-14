<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPerBrandChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_store_brand_chart_groups_by_store_ownership_and_follows_entity_filter(): void
    {
        $activeCompany = $this->company('Alpha Brand', 'ALPHA');
        $secondaryCompany = $this->company('Beta Brand', 'BETA');
        $emptyCompany = $this->company('Gamma Brand', 'GAMMA');
        $inactiveCompany = $this->company('Inactive Brand', 'INACTIVE', false);
        $unauthorizedCompany = $this->company('Unauthorized Brand', 'UNAUTH');

        // Permission gating the Entity/Company filter.
        \Spatie\Permission\Models\Permission::findOrCreate('dashboard.filter_entity', 'web');

        $viewer = User::factory()->create(['company_id' => $activeCompany->id]);
        $role = Role::create(['name' => 'Cross-brand dashboard viewer', 'guard_name' => 'web']);
        $role->companies()->attach([
            $activeCompany->id,
            $secondaryCompany->id,
            $emptyCompany->id,
            $inactiveCompany->id,
        ]);
        $role->givePermissionTo('dashboard.filter_entity');
        $viewer->assignRole($role);

        // Each brand OWNS a store; the chart counts tickets sitting on that store,
        // regardless of the ticket's stamped company (store ownership).
        $alphaStore = $this->store($activeCompany, 'ALP-1');
        $alphaInactiveStore = $this->store($activeCompany, 'ALP-X', 'Regular', false);
        $betaStore = $this->store($secondaryCompany, 'BET-1');
        $this->store($emptyCompany, 'GAM-1');
        $inactiveStore = $this->store($inactiveCompany, 'INA-1');
        $unauthStore = $this->store($unauthorizedCompany, 'UNA-1');

        $this->ticket($alphaStore, 'open');
        $this->ticket($alphaStore, 'resolved');
        // Inactive stores are excluded from every dashboard tally.
        $this->ticket($alphaInactiveStore, 'open');
        $this->ticket($betaStore, 'closed');
        // Out-of-period ticket on Beta's store — excluded by the year filter below.
        $this->ticket($betaStore, 'open')->forceFill([
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ])->save();
        $this->ticket($inactiveStore, 'open');
        $this->ticket($unauthStore, 'open');

        $response = $this
            ->actingAs($viewer)
            ->withSession([CompanyContext::SESSION_KEY => $activeCompany->id])
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'ticketCharts',
                'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            ])
            ->get(route('dashboard', [
                'skip_default_department' => 1,
                'year' => now()->year,
                'entity_ids' => [$activeCompany->id, $secondaryCompany->id, $emptyCompany->id, $inactiveCompany->id, $unauthorizedCompany->id],
            ]));

        $response->assertOk();

        $charts = $response->json('props.ticketCharts');
        $brands = collect($charts['perStoreBrand'])->keyBy('code');

        // Only the accessible active brands are rows; INACTIVE/UNAUTH are excluded.
        $this->assertSame(['ALPHA', 'BETA', 'GAMMA'], $brands->keys()->all());
        $this->assertSame(1, $brands['ALPHA']['open']);
        $this->assertSame(1, $brands['ALPHA']['closed']);
        $this->assertSame(0, $brands['BETA']['open']);
        $this->assertSame(1, $brands['BETA']['closed']);
        $this->assertSame(0, $brands['GAMMA']['open']);
        $this->assertSame(0, $brands['GAMMA']['closed']);
        $this->assertFalse($brands->has('INACTIVE'));
        $this->assertFalse($brands->has('UNAUTH'));

        // Overall spans the selected brands' stores (Alpha open+resolved, Beta closed).
        $this->assertSame(1, $charts['overall']['open']);
        $this->assertSame(2, $charts['overall']['closed']);
    }

    private function company(string $name, string $code, bool $active = true): Company
    {
        return Company::create([
            'name' => $name,
            'code' => $code,
            'is_active' => $active,
        ]);
    }

    private function store(Company $company, string $code, string $class = 'Regular', bool $active = true): Store
    {
        return Store::create([
            'code' => $code,
            'name' => "Store {$code}",
            'sector' => 1,
            'area' => 'Test Area',
            'brand' => 'Test Brand',
            'class' => $class,
            'is_active' => $active,
            'company_id' => $company->id,
        ]);
    }

    private function ticket(Store $store, string $status): Ticket
    {
        return Ticket::create([
            'title' => "{$store->code} ticket",
            'description' => 'Dashboard chart regression fixture.',
            'type' => 'task',
            'status' => $status,
            'priority' => 'medium',
            'severity' => 'minor',
            'store_id' => $store->id,
            'company_id' => $store->company_id,
        ]);
    }
}
