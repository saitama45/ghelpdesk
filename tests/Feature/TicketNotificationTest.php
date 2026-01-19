<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Mail\NewTicketCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Spatie\Permission\Models\Permission;

class TicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed permissions if needed, or just create roles manually
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_it_sends_email_to_users_with_notify_on_create_role()
    {
        Mail::fake();

        $company = Company::create(['name' => 'Test Co', 'code' => 'TEST', 'is_active' => true]);
        
        $role = Role::create([
            'name' => 'Admin', 
            'guard_name' => 'web',
            'notify_on_ticket_create' => true
        ]);
        
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole($role);

        $reporter = User::factory()->create(['company_id' => $company->id]);
        
        $this->actingAs($reporter)
            ->post(route('tickets.store'), [
                'company_id' => $company->id,
                'title' => 'New Ticket',
                'description' => 'Test',
                'priority' => 'medium',
                'severity' => 'minor',
                'type' => 'bug',
                'status' => 'open',
            ]);

        Mail::assertSent(NewTicketCreated::class, function ($mail) use ($admin) {
            return $mail->hasTo($admin->email);
        });
    }

    public function test_it_does_not_send_email_if_notify_on_create_is_false()
    {
        Mail::fake();

        $company = Company::create(['name' => 'Test Co', 'code' => 'TEST', 'is_active' => true]);
        
        $role = Role::create([
            'name' => 'Manager', 
            'guard_name' => 'web',
            'notify_on_ticket_create' => false
        ]);
        
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole($role);

        $reporter = User::factory()->create(['company_id' => $company->id]);
        
        $this->actingAs($reporter)
            ->post(route('tickets.store'), [
                'company_id' => $company->id,
                'title' => 'New Ticket',
                'description' => 'Test',
                'priority' => 'medium',
                'severity' => 'minor',
                'type' => 'bug',
                'status' => 'open',
            ]);

        Mail::assertNotSent(NewTicketCreated::class, function ($mail) use ($manager) {
            return $mail->hasTo($manager->email);
        });
    }

    public function test_it_sends_email_to_assignee_if_notify_on_assign_is_true()
    {
        Mail::fake();

        $company = Company::create(['name' => 'Test Co', 'code' => 'TEST', 'is_active' => true]);
        
        $role = Role::create([
            'name' => 'Staff', 
            'guard_name' => 'web',
            'notify_on_ticket_assign' => true,
            'is_assignable' => true
        ]);
        
        $assignee = User::factory()->create(['company_id' => $company->id]);
        $assignee->assignRole($role);

        $reporter = User::factory()->create(['company_id' => $company->id]);
        
        $this->actingAs($reporter)
            ->post(route('tickets.store'), [
                'company_id' => $company->id,
                'title' => 'New Ticket',
                'description' => 'Test',
                'priority' => 'medium',
                'severity' => 'minor',
                'type' => 'bug',
                'status' => 'open',
                'assignee_id' => $assignee->id
            ]);

        Mail::assertSent(NewTicketCreated::class, function ($mail) use ($assignee) {
            return $mail->hasTo($assignee->email);
        });
    }

    public function test_it_does_not_send_email_to_assignee_if_notify_on_assign_is_false()
    {
        Mail::fake();

        $company = Company::create(['name' => 'Test Co', 'code' => 'TEST', 'is_active' => true]);
        
        $role = Role::create([
            'name' => 'Staff', 
            'guard_name' => 'web',
            'notify_on_ticket_assign' => false,
            'is_assignable' => true
        ]);
        
        $assignee = User::factory()->create(['company_id' => $company->id]);
        $assignee->assignRole($role);

        $reporter = User::factory()->create(['company_id' => $company->id]);
        
        $this->actingAs($reporter)
            ->post(route('tickets.store'), [
                'company_id' => $company->id,
                'title' => 'New Ticket',
                'description' => 'Test',
                'priority' => 'medium',
                'severity' => 'minor',
                'type' => 'bug',
                'status' => 'open',
                'assignee_id' => $assignee->id
            ]);

        Mail::assertNotSent(NewTicketCreated::class, function ($mail) use ($assignee) {
            return $mail->hasTo($assignee->email);
        });
    }
}
