<?php

namespace Tests\Feature;

use App\Mail\ScheduleChangeRequestNotification;
use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\ScheduleChangeRequest;
use App\Models\ScheduleStore;
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

    public function test_manager_can_directly_update_own_actual_times(): void
    {
        $manager = User::factory()->create(['is_manager' => true]);
        $schedule = $this->createSchedule($manager);

        $originalLog = AttendanceLog::create([
            'user_id' => $manager->id,
            'schedule_id' => $schedule->id,
            'type' => 'time_in',
            'photo_path' => 'attendance/test/manager-time-in.png',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'location_accuracy' => 8.5,
            'device_info' => 'Original mobile device',
            'ip_address' => '192.0.2.10',
            'log_time' => '2026-05-10 07:15:00',
        ]);

        $this->actingAs($manager)
            ->post(route('schedules.actual-times.update', $schedule), [
                'schedule_date' => '2026-05-10',
                'actual_time_in' => '2026-05-10T08:00',
                'actual_time_out' => '2026-05-10T17:30',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, AttendanceLog::where('type', 'time_in')->whereNotNull('voided_at')->count());
        $this->assertSame(1, AttendanceLog::where('type', 'time_in')->count());
        $this->assertDatabaseHas('attendance_logs', [
            'id' => $originalLog->id,
            'user_id' => $manager->id,
            'schedule_id' => $schedule->id,
            'type' => 'time_in',
            'log_time' => '2026-05-10 08:00:00',
            'photo_path' => 'attendance/test/manager-time-in.png',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'location_accuracy' => 8.5,
            'device_info' => 'Original mobile device',
            'ip_address' => '192.0.2.10',
            'voided_at' => null,
        ]);
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $manager->id,
            'schedule_id' => $schedule->id,
            'type' => 'time_out',
            'log_time' => '2026-05-10 17:30:00',
            'voided_at' => null,
        ]);
        $this->assertSame($manager->id, $schedule->fresh()->updated_by);
    }

    public function test_manager_subordinate_actual_time_adjustment_requires_approval(): void
    {
        Mail::fake();

        $subordinate = User::factory()->create();
        $manager = User::factory()->create(['is_manager' => true]);
        $approver = User::factory()->create(['is_manager' => true]);
        $approver->givePermissionTo('schedules.approve');
        $subordinate->managers()->attach($manager->id);
        $manager->managers()->attach($approver->id);
        $schedule = $this->createSchedule($subordinate);

        $this->actingAs($manager)
            ->post(route('schedules.actual-times.update', $schedule), [
                'schedule_date' => '2026-05-10',
                'actual_time_in' => '2026-05-10T08:00',
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->post(route('schedules.actual-time-requests.store', $schedule), [
                'schedule_date' => '2026-05-10',
                'actual_time_in' => '2026-05-10T08:00',
                'actual_time_out' => '2026-05-10T17:30',
                'requester_remarks' => 'Correct subordinate logs.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $changeRequest = ScheduleChangeRequest::first();
        $this->assertSame('actual_time_adjustment', $changeRequest->request_type);
        $this->assertSame([$approver->id], $changeRequest->assigned_approver_ids);
        $this->assertDatabaseMissing('attendance_logs', [
            'user_id' => $subordinate->id,
            'schedule_id' => $schedule->id,
        ]);

        $this->actingAs($approver)
            ->post(route('schedule-change-requests.approve', $changeRequest), ['remarks' => 'Approved'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $changeRequest->fresh()->status);
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $subordinate->id,
            'schedule_id' => $schedule->id,
            'type' => 'time_in',
            'log_time' => '2026-05-10 08:00:00',
            'voided_at' => null,
        ]);
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $subordinate->id,
            'schedule_id' => $schedule->id,
            'type' => 'time_out',
            'log_time' => '2026-05-10 17:30:00',
            'voided_at' => null,
        ]);
    }

    public function test_non_manager_can_request_own_missing_actual_time_out_for_approval(): void
    {
        Mail::fake();

        Permission::firstOrCreate(['name' => 'schedules.view']);

        $user = User::factory()->create();
        $user->givePermissionTo('schedules.view');
        $manager = User::factory()->create(['is_manager' => true]);
        $manager->givePermissionTo('schedules.approve');
        $user->managers()->attach($manager->id);
        $schedule = $this->createSchedule($user);

        AttendanceLog::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'type' => 'time_in',
            'photo_path' => 'attendance/test/member-time-in.png',
            'log_time' => '2026-05-10 08:00:00',
        ]);

        $this->actingAs($user)
            ->get(route('schedules.index', [
                'start' => '2026-05-01',
                'end' => '2026-05-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Schedules/Index')
                ->where('schedules.0.id', $schedule->id)
                ->where('schedules.0.can_edit_actual_time', false)
                ->where('schedules.0.can_request_actual_time', true)
            );

        $this->actingAs($user)
            ->post(route('schedules.actual-time-requests.store', $schedule), [
                'schedule_date' => '2026-05-10',
                'actual_time_out' => '2026-05-10T17:30',
                'requester_remarks' => 'Forgot to time out.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $changeRequest = ScheduleChangeRequest::first();
        $this->assertSame('actual_time_adjustment', $changeRequest->request_type);
        $this->assertSame([$manager->id], $changeRequest->assigned_approver_ids);
        $this->assertDatabaseMissing('attendance_logs', [
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'type' => 'time_out',
        ]);

        $this->actingAs($manager)
            ->post(route('schedule-change-requests.approve', $changeRequest), ['remarks' => 'Approved'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'type' => 'time_in',
            'photo_path' => 'attendance/test/member-time-in.png',
            'voided_at' => null,
        ]);
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'type' => 'time_out',
            'log_time' => '2026-05-10 17:30:00',
            'voided_at' => null,
        ]);
        $this->assertSame($manager->id, $schedule->fresh()->updated_by);
    }

    public function test_repeated_actual_time_request_updates_same_pending_request(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $manager = User::factory()->create(['is_manager' => true]);
        $manager->givePermissionTo('schedules.approve');
        $user->managers()->attach($manager->id);
        $schedule = $this->createSchedule($user);

        $this->actingAs($user)
            ->post(route('schedules.actual-time-requests.store', $schedule), [
                'schedule_date' => '2026-05-10',
                'actual_time_out' => '2026-05-10T17:30',
                'requester_remarks' => 'First request.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $firstRequest = ScheduleChangeRequest::first();

        $this->actingAs($user)
            ->post(route('schedules.actual-time-requests.store', $schedule), [
                'schedule_date' => '2026-05-10',
                'actual_time_out' => '2026-05-10T18:00',
                'requester_remarks' => 'Updated request.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ScheduleChangeRequest::count());
        $this->assertSame($firstRequest->id, ScheduleChangeRequest::first()->id);
        $this->assertSame('2026-05-10T18:00:00+08:00', ScheduleChangeRequest::first()->requested_payload['actual_time_out']);
        $this->assertSame('Updated request.', ScheduleChangeRequest::first()->requester_remarks);
    }

    public function test_actual_time_request_email_uses_actual_time_details(): void
    {
        $user = User::factory()->create();
        $manager = User::factory()->create(['is_manager' => true]);
        $schedule = $this->createSchedule($user);

        $changeRequest = ScheduleChangeRequest::create([
            'schedule_id' => $schedule->id,
            'requester_id' => $user->id,
            'request_type' => 'actual_time_adjustment',
            'assigned_approver_ids' => [$manager->id],
            'status' => 'pending',
            'requester_remarks' => 'Forgot to time out.',
            'original_payload' => [
                'schedule_store_id' => null,
                'schedule_date' => '2026-05-10',
                'actual_time_in' => '2026-05-10T08:00:00+08:00',
                'actual_time_out' => null,
                'clear_time_in' => false,
                'clear_time_out' => false,
            ],
            'requested_payload' => [
                'schedule_store_id' => null,
                'schedule_date' => '2026-05-10',
                'actual_time_in' => null,
                'actual_time_out' => '2026-05-10T17:30:00+08:00',
                'clear_time_in' => false,
                'clear_time_out' => false,
            ],
        ]);

        $mail = new ScheduleChangeRequestNotification($changeRequest, 'submitted', true);
        $html = $mail->render();

        $this->assertStringContainsString('Actual Time Adjustment Approval Required', $mail->envelope()->subject);
        $this->assertStringContainsString('Actual Time Adjustment Request', $html);
        $this->assertStringContainsString('Actual Time In', $html);
        $this->assertStringContainsString('Actual Time Out', $html);
        $this->assertStringContainsString('May 10, 2026 08:00 AM', $html);
        $this->assertStringContainsString('May 10, 2026 05:30 PM', $html);
        $this->assertStringContainsString('Forgot to time out.', $html);
        $this->assertStringNotContainsString('Deployment Entries', $html);
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

    public function test_scoped_schedule_edit_keeps_existing_actual_times_on_the_edited_schedule(): void
    {
        Permission::firstOrCreate(['name' => 'schedules.view']);

        $manager = User::factory()->create(['is_manager' => true]);
        $manager->givePermissionTo(['schedules.edit', 'schedules.view']);
        $store = Store::create([
            'code' => 'SPLIT-001',
            'name' => 'Split Schedule Store',
            'sector' => 1,
            'area' => 'Metro Manila',
            'brand' => 'GHelpdesk',
            'class' => 'Regular',
            'is_active' => true,
        ]);
        $schedule = Schedule::create([
            'user_id' => $manager->id,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
            'status' => 'WFH',
            'start_time' => '2026-05-10 08:00:00',
            'end_time' => '2026-05-11 17:00:00',
        ]);
        $editedStore = ScheduleStore::create([
            'schedule_id' => $schedule->id,
            'store_id' => $store->id,
            'start_time' => '2026-05-10 08:00:00',
            'end_time' => '2026-05-10 17:00:00',
            'grace_period_minutes' => 30,
        ]);
        ScheduleStore::create([
            'schedule_id' => $schedule->id,
            'store_id' => $store->id,
            'start_time' => '2026-05-11 08:00:00',
            'end_time' => '2026-05-11 17:00:00',
            'grace_period_minutes' => 30,
        ]);

        foreach ([
            ['type' => 'time_in', 'log_time' => '2026-05-10 08:00:00'],
            ['type' => 'time_out', 'log_time' => '2026-05-10 17:00:00'],
        ] as $log) {
            AttendanceLog::create([
                'user_id' => $manager->id,
                'schedule_id' => $schedule->id,
                'schedule_store_id' => $editedStore->id,
                'type' => $log['type'],
                'photo_path' => 'attendance/test/'.$log['type'].'.png',
                'log_time' => $log['log_time'],
            ]);
        }

        $this->actingAs($manager)
            ->put(route('schedules.update', $schedule), [
                'user_id' => $manager->id,
                'status' => 'WFH',
                'stores' => [[
                    'id' => $editedStore->id,
                    'store_id' => $store->id,
                    'ticket_id' => null,
                    'start_time' => '2026-05-10T08:00',
                    'end_time' => '2026-05-10T18:00',
                    'grace_period_minutes' => 30,
                    'remarks' => 'Extended shift',
                ]],
                'scope_date' => '2026-05-10',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $editedScheduleId = (int) $editedStore->fresh()->schedule_id;
        $this->assertNotSame((int) $schedule->id, $editedScheduleId);
        $this->assertSame(
            [$editedScheduleId],
            AttendanceLog::where('schedule_store_id', $editedStore->id)
                ->pluck('schedule_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all()
        );

        $this->actingAs($manager)
            ->get(route('schedules.index', [
                'start' => '2026-05-10',
                'end' => '2026-05-11',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedules', function ($schedules) use ($editedScheduleId) {
                    $editedSchedule = collect($schedules)->firstWhere('id', $editedScheduleId);

                    return data_get($editedSchedule, 'actual_time_in') !== null
                        && data_get($editedSchedule, 'actual_time_out') !== null
                        && data_get($editedSchedule, 'schedule_stores.0.actual_time_in') !== null
                        && data_get($editedSchedule, 'schedule_stores.0.actual_time_out') !== null;
                })
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
