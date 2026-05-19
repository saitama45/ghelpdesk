<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketIndexSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_summary_counts_all_matching_tickets_and_quick_filter_is_server_side(): void
    {
        $company = Company::create([
            'name' => 'Test Company',
            'code' => 'TC',
            'is_active' => true,
        ]);

        $department = Department::create([
            'name' => 'Support',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
        ]);

        $assignedUser = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
        ]);

        $newTicket = $this->ticket($company, ['title' => 'New visible ticket']);
        $this->ticket($company, ['title' => 'Assigned open ticket', 'assignee_id' => $assignedUser->id]);
        $this->ticket($company, ['title' => 'Unassigned in progress ticket', 'status' => 'in_progress']);

        $this->actingAs($user)
            ->get(route('tickets.index', ['dashboard_filter' => 'new']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->where('summaryStats.new', 1)
                ->where('summaryStats.unassigned', 1)
                ->where('filters.dashboard_filter', 'new')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $newTicket->id)
            );
    }

    private function ticket(Company $company, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'title' => 'POS issue',
            'description' => 'The terminal needs support.',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $company->id,
            'is_deleted' => false,
        ], $overrides));
    }
}
