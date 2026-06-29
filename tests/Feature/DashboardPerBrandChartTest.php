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

    public function test_per_brand_chart_shows_all_accessible_active_brands_independent_of_active_entity(): void
    {
        $activeCompany = $this->company('Alpha Brand', 'ALPHA');
        $secondaryCompany = $this->company('Beta Brand', 'BETA');
        $emptyCompany = $this->company('Gamma Brand', 'GAMMA');
        $inactiveCompany = $this->company('Inactive Brand', 'INACTIVE', false);
        $unauthorizedCompany = $this->company('Unauthorized Brand', 'UNAUTH');

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

        $response = $this
            ->actingAs($viewer)
            ->withSession([CompanyContext::SESSION_KEY => $activeCompany->id])
            ->get(route('dashboard', [
                'skip_default_department' => 1,
                'year' => now()->year,
            ]));

        $response->assertOk();

        $charts = $response->viewData('page')['props']['ticketCharts'];
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

        $this->assertSame(1, $charts['overall']['open']);
        $this->assertSame(1, $charts['overall']['closed']);
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
