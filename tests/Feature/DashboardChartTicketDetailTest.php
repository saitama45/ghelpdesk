<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardChartTicketDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_chart_ticket_endpoint_matches_bucket_and_concern_type(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TC', 'is_active' => true]);
        $viewer = User::factory()->create(['company_id' => $company->id]);
        $incident = Item::create(['name' => 'Incident Item', 'priority' => 'Medium', 'concern_type' => 'Incident', 'is_active' => true]);
        $service = Item::create(['name' => 'Service Item', 'priority' => 'Medium', 'concern_type' => 'Service Request', 'is_active' => true]);

        $openIncident = $this->ticket($company, $incident, 'in_progress');
        $this->ticket($company, $incident, 'closed');
        $this->ticket($company, $service, 'open');

        $this->actingAs($viewer)
            ->getJson(route('dashboard.chart-tickets', ['bucket' => 'open', 'concern_type' => 'Incident']))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('tickets.0.id', $openIncident->id)
            ->assertJsonPath('tickets.0.concern_type', 'Incident');
    }

    public function test_chart_ticket_export_returns_an_excel_download(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TC', 'is_active' => true]);
        $viewer = User::factory()->create(['company_id' => $company->id]);
        $item = Item::create(['name' => 'Incident Item', 'priority' => 'Medium', 'concern_type' => 'Incident', 'is_active' => true]);
        $this->ticket($company, $item, 'resolved');

        $this->actingAs($viewer)
            ->get(route('dashboard.chart-tickets.export', ['bucket' => 'closed', 'concern_type' => 'Incident']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_all_bucket_returns_both_open_and_closed_chart_tickets(): void
    {
        $company = Company::create(['name' => 'Test Company', 'code' => 'TC', 'is_active' => true]);
        $viewer = User::factory()->create(['company_id' => $company->id]);
        $item = Item::create(['name' => 'Incident Item', 'priority' => 'Medium', 'concern_type' => 'Incident', 'is_active' => true]);
        $this->ticket($company, $item, 'open');
        $this->ticket($company, $item, 'closed');

        $this->actingAs($viewer)
            ->getJson(route('dashboard.chart-tickets', ['bucket' => 'all', 'concern_type' => 'Incident']))
            ->assertOk()
            ->assertJsonPath('count', 2);
    }

    private function ticket(Company $company, Item $item, string $status): Ticket
    {
        // The dashboard counts on-store tickets only, so every fixture sits on a
        // store owned by the company.
        $store = Store::create([
            'code' => 'CH-'.uniqid(),
            'name' => 'Chart Store',
            'sector' => 1,
            'area' => 'A',
            'brand' => 'B',
            'class' => 'Regular',
            'is_active' => true,
            'company_id' => $company->id,
        ]);

        return Ticket::create([
            'title' => "{$status} chart ticket",
            'description' => 'Dashboard modal test.',
            'type' => 'task',
            'status' => $status,
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $company->id,
            'item_id' => $item->id,
            'store_id' => $store->id,
        ]);
    }
}
