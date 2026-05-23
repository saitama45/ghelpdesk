<?php

namespace Tests\Feature;

use App\Mail\ScheduleChangeRequestNotification;
use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\ScheduleChangeRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ScheduleChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'schedules.edit']);
        Permission::firstOrCreate(['name' => 'schedules.approve']);
    }

    public function test_user_without_edit_permission_cannot_directly_update_schedule(): void
    {
        $user = User::factory()->create();
        $schedule = $this->createSchedule($user);

        $this->actingAs($user)
            ->put(route('schedules.update', $schedule), $this->payload($user, 'Restday'))
            ->assertForbidden();

        $this->assertSame('WFH', $schedule->fresh()->status);
    }

    public function test_own_schedule_is_marked_requestable_not_directly_editable_without_edit_permission(): void
    {
        Permission::firstOrCreate(['name' => 'schedules.view']);

        $user = User::factory()->create();
        $user->givePermissionTo('schedules.view');
        $schedule = $this->createSchedule($user);

        $this->actingAs($user)
            ->get(route('schedules.index', [
                'start' => '2026-05-01',
                'end' => '2026-05-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Schedules/Index')
                ->where('schedules.0.id', $schedule->id)
                ->where('schedules.0.can_edit', false)
                ->where('schedules.0.can_request_change', true)
            );
    }

    public function test_user_can_request_change_for_own_schedule_and_notifies_direct_manager(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $manager = User::factory()->create(['is_manager' => true]);
        $manager->givePermissionTo('schedules.approve');
        $user->managers()->attach($manager->id);
        $schedule = $this->createSchedule($user);

        $response = $this->actingAs($user)
            ->post(route('schedules.change-requests.store', $schedule), $this->payload($user, 'Restday'));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('schedule_change_requests', [
            'schedule_id' => $schedule->id,
            'requester_id' => $user->id,
            'status' => 'pending',
        ]);

        $changeRequest = ScheduleChangeRequest::first();
        $this->assertSame([$manager->id], $changeRequest->assigned_approver_ids);

        Mail::assertSent(ScheduleChangeRequestNotification::class, fn ($mail) =>
            $mail->hasTo($manager->email)
            && $mail->isApprover
        );
    }

    public function test_manager_without_approve_permission_does_not_see_subordinate_request(): void
    {
        Permission::firstOrCreate(['name' => 'schedules.view']);

        $user = User::factory()->create();
        $manager = User::factory()->create(['is_manager' => true]);
        $manager->givePermissionTo('schedules.view');
        $user->managers()->attach($manager->id);
        $schedule = $this->createSchedule($user);

        ScheduleChangeRequest::create([
            'schedule_id' => $schedule->id,
            'requester_id' => $user->id,
            'assigned_approver_ids' => [$manager->id],
            'status' => 'pending',
            'original_payload' => $this->payload($user, 'WFH'),
            'requested_payload' => $this->payload($user, 'Restday'),
        ]);

        $this->actingAs($manager)
            ->get(route('schedules.index', [
                'start' => '2026-05-01',
                'end' => '2026-05-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Schedules/Index')
                ->where('scheduleChangeRequests', [])
            );
    }

    public function test_requesting_another_users_schedule_is_forbidden(): void
    {
        $requester = User::factory()->create();
        $otherUser = User::factory()->create();
        $schedule = $this->createSchedule($otherUser);

        $this->actingAs($requester)
            ->post(route('schedules.change-requests.store', $schedule), $this->payload($otherUser, 'Restday'))
            ->assertForbidden();
    }

    public function test_repeated_submission_replaces_existing_pending_request(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $manager = User::factory()->create(['is_manager' => true]);
        $manager->givePermissionTo('schedules.approve');
        $user->managers()->attach($manager->id);
        $schedule = $this->createSchedule($user);

        $this->actingAs($user)
            ->post(route('schedules.change-requests.store', $schedule), $this->payload($user, 'Restday'))
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('schedules.change-requests.store', $schedule), $this->payload($user, 'Holiday'))
            ->assertRedirect();

        $this->assertSame(1, ScheduleChangeRequest::count());
        $this->assertSame('Holiday', ScheduleChangeRequest::first()->requested_payload['status']);
    }

    public function test_assigned_approver_can_approve_and_apply_requested_schedule_change(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $manager = User::factory()->create(['is_manager' => true]);
        $manager->givePermissionTo('schedules.approve');
        $user->managers()->attach($manager->id);
        $schedule = $this->createSchedule($user);

        $this->actingAs($user)
            ->post(route('schedules.change-requests.store', $schedule), $this->payload($user, 'Restday'))
            ->assertRedirect();

        $changeRequest = ScheduleChangeRequest::first();

        $this->actingAs($manager)
            ->post(route('schedule-change-requests.approve', $changeRequest), ['remarks' => 'Approved'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Restday', $schedule->fresh()->status);
        $this->assertSame('approved', $changeRequest->fresh()->status);

        Mail::assertSent(ScheduleChangeRequestNotification::class, fn ($mail) =>
            $mail->hasTo($user->email)
            && $mail->action === 'approved'
        );
    }

    public function test_schedule_change_request_email_includes_requested_schedule_details(): void
    {
        $user = User::factory()->create();
        $manager = User::factory()->create(['is_manager' => true]);
        $schedule = $this->createSchedule($user);
        $store = Store::create([
            'code' => 'ST-001',
            'name' => 'Main Branch',
            'sector' => 1,
            'area' => 'North',
            'brand' => 'Brand A',
            'cluster' => 'Cluster A',
            'is_active' => true,
        ]);
        $ticket = Ticket::create([
            'ticket_key' => 'GH-1001',
            'title' => 'POS check',
            'description' => 'Deployment support',
            'reporter_id' => $user->id,
        ]);

        $payload = $this->payload($user, 'On-site');
        $payload['stores'][0]['store_id'] = $store->id;
        $payload['stores'][0]['ticket_id'] = $ticket->id;
        $payload['pickup_start'] = '08:00';
        $payload['pickup_end'] = '09:00';
        $payload['backlogs_start'] = '18:00';
        $payload['backlogs_end'] = '19:00';
        $payload['stores'][0]['grace_period_minutes'] = 45;

        $changeRequest = ScheduleChangeRequest::create([
            'schedule_id' => $schedule->id,
            'requester_id' => $user->id,
            'assigned_approver_ids' => [$manager->id],
            'status' => 'pending',
            'requester_remarks' => 'Need an on-site visit.',
            'original_payload' => $this->payload($user, 'WFH'),
            'requested_payload' => $payload,
        ]);

        $html = (new ScheduleChangeRequestNotification($changeRequest))->render();

        $this->assertStringContainsString('WFH', $html);
        $this->assertStringContainsString('On-site', $html);
        $this->assertStringContainsString('May 10, 2026 07:00 AM', $html);
        $this->assertStringContainsString('May 10, 2026 05:00 PM', $html);
        $this->assertStringContainsString('ST-001 - Main Branch', $html);
        $this->assertStringContainsString('GH-1001', $html);
        $this->assertStringContainsString('45 min', $html);
        $this->assertStringContainsString('Requested change', $html);
        $this->assertStringContainsString('Need an on-site visit.', $html);
    }

    public function test_approve_request_reports_attendance_log_outside_requested_window(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $manager = User::factory()->create(['is_manager' => true]);
        $manager->givePermissionTo('schedules.approve');
        $user->managers()->attach($manager->id);
        $schedule = $this->createSchedule($user);

        AttendanceLog::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'type' => 'time_in',
            'log_time' => '2026-05-10 07:15:00',
        ]);

        $payload = $this->payload($user, 'Restday');
        $payload['stores'][0]['start_time'] = '2026-05-10T09:00';
        $payload['stores'][0]['end_time'] = '2026-05-10T19:00';
        $payload['stores'][0]['grace_period_minutes'] = 30;

        $changeRequest = ScheduleChangeRequest::create([
            'schedule_id' => $schedule->id,
            'requester_id' => $user->id,
            'assigned_approver_ids' => [$manager->id],
            'status' => 'pending',
            'original_payload' => $this->payload($user, 'WFH'),
            'requested_payload' => $payload,
        ]);

        $response = $this->actingAs($manager)
            ->post(route('schedule-change-requests.approve', $changeRequest), ['remarks' => 'Approved'])
            ->assertSessionHasErrors('stores');

        $this->assertStringContainsString(
            'time in log at May 10, 2026 07:15 AM',
            $response->baseResponse->getSession()->get('errors')->first('stores')
        );
    }

    private function createSchedule(User $user): Schedule
    {
        return Schedule::create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'status' => 'WFH',
            'start_time' => '2026-05-10 07:00:00',
            'end_time' => '2026-05-10 17:00:00',
            'remarks' => 'Original schedule',
        ]);
    }

    private function payload(User $user, string $status): array
    {
        return [
            'user_id' => $user->id,
            'status' => $status,
            'stores' => [[
                'store_id' => null,
                'ticket_id' => null,
                'start_time' => '2026-05-10T07:00',
                'end_time' => '2026-05-10T17:00',
                'grace_period_minutes' => 30,
                'remarks' => 'Requested change',
            ]],
            'pickup_start' => null,
            'pickup_end' => null,
            'backlogs_start' => null,
            'backlogs_end' => null,
            'requester_remarks' => 'Please update this schedule.',
        ];
    }
}
