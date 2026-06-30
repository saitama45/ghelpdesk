<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPerBrandChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_brand_chart_follows_the_entity_filter_selection(): void
    {
        $activeCompany = $this->company('Alpha Brand', 'ALPHA');
        $secondaryCompany = $this->company('Beta Brand', 'BETA');
        $emptyCompany = $this->company('Gamma Brand', 'GAMMA');
        $inactiveCompany = $this->company('Inactive Brand', 'INACTIVE', false);
        $unauthorizedCompany = $this->company('Unauthorized Brand', 'UNAUTH');

        // Permission gating the Entity/Company filter.
        \Spatie\Permission\Models\Permission::findOrCreate('dashboard.filter_entity', 'web');

        $viewer = User::factory()->create(['company_id' => $activeCompany->id]);
        $role = Role::create([
            'name' => 'Cross-brand dashboard viewer',
            'guard_name' => 'web',
        ]);
        $role->companies()->attach([
            $activeCompany->id,
            $secondaryCompany->id,
            $emptyCompany->id,
            $inactiveCompany->id,
        ]);
        $role->givePermissionTo('dashboard.filter_entity');
        $viewer->assignRole($role);

        $this->ticket($activeCompany, 'open');
        $this->ticket($activeCompany, 'resolved');
        $this->ticket($secondaryCompany, 'closed');
        $outsidePeriodTicket = $this->ticket($secondaryCompany, 'open');
        $outsidePeriodTicket->forceFill([
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ])->save();
        $this->ticket($inactiveCompany, 'open');
        $this->ticket($unauthorizedCompany, 'open');

        // ticketCharts is a lazy (Inertia optional) prop — request it the way the
        // dashboard's "Open vs Closed / Per Brand" tab does (partial reload). The
        // Entity/Company filter selects the three accessible active brands; the
        // chart now reflects exactly that selection (INACTIVE/UNAUTH excluded as
        // they are not selectable). Default (no selection) shows the active entity.
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
        $brands = collect($charts['perBrand'])->keyBy('code');

        $this->assertSame(['ALPHA', 'BETA', 'GAMMA'], $brands->keys()->all());
        $this->assertSame(1, $brands['ALPHA']['open']);
        $this->assertSame(1, $brands['ALPHA']['closed']);
        $this->assertSame(0, $brands['BETA']['open']);
        $this->assertSame(1, $brands['BETA']['closed']);
        $this->assertSame(0, $brands['GAMMA']['open']);
        $this->assertSame(0, $brands['GAMMA']['closed']);
        $this->assertFalse($brands->has('INACTIVE'));
        $this->assertFalse($brands->has('UNAUTH'));

        // Overall now spans the selected brands (ALPHA open+resolved, BETA closed).
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

    private function ticket(Company $company, string $status): Ticket
    {
        return Ticket::create([
            'title' => "{$company->code} ticket",
            'description' => 'Dashboard chart regression fixture.',
            'type' => 'task',
            'status' => $status,
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $company->id,
        ]);
    }
}
