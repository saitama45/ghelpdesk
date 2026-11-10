<?php

namespace Tests\Feature;

use App\Mail\ScheduleChangeRequestNotification;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Schedule;
use App\Models\ScheduleChangeRequest;
use App\Models\ScheduleStore;
use App\Models\Store;
use App\Models\User;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DTR clock-ins that break the schedule are recorded, then sent to the "Reports To" leader:
 * - Time In on a scheduled rest day;
 * - Time In outside the assigned store's geofence (location mismatch).
 */
class AttendanceScheduleAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PHOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WnSUswAAAAASUVORK5CYII=';

    private Store $cfeOne;

    private Store $cfeTwo;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 09:43:00', 'Asia/Manila'));
        Storage::fake('public');
        Mail::fake();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['attendance.view', 'attendance.create', 'schedules.approve'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // ~1.2 km apart, 100 m geofences.
        $this->cfeTwo = $this->store('CFE2', 'CFE II', 14.5995, 120.9842);
        $this->cfeOne = $this->store('CFE1', 'CFE I', 14.6100, 120.9842);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rest_day_time_in_is_recorded_and_prompts_for_a_schedule_adjustment(): void
    {
        [$user] = $this->employeeWithLeader();
        [$schedule, $entry] = $this->restDay($user);

        $response = $this->actingAs($user)->post(route('attendance.log'), $this->payload($this->cfeOne));

        $log = AttendanceLog::sole();
        $this->assertSame('time_in', $log->type);
        $this->assertSame($schedule->id, $log->schedule_id);
        $this->assertSame($entry->id, $log->schedule_store_id);
        $this->assertSame(AttendanceLog::ADJUSTMENT_REST_DAY, $log->adjustment_reason);
        $this->assertSame($this->cfeOne->id, $log->detected_store_id);

        $response->assertRedirect(route('attendance.index'));
    }

    public function test_rest_day_time_out_closes_the_rest_day_session(): void
    {
        [$user] = $this->employeeWithLeader();
        [$schedule] = $this->restDay($user);

        $this->actingAs($user)->post(route('attendance.log'), $this->payload($this->cfeOne));
        Carbon::setTestNow(Carbon::parse('2026-11-10 18:05:00', 'Asia/Manila'));

        $this->actingAs($user)
            ->post(route('attendance.log'), $this->payload($this->cfeOne, ['expected_type' => 'time_out']))
            ->assertRedirect(route('attendance.logs'));

        $timeOut = AttendanceLog::where('type', 'time_out')->sole();
        $this->assertSame($schedule->id, $timeOut->schedule_id);
        $this->assertNull($timeOut->adjustment_reason);
    }

    public function test_dtr_offers_the_rest_day_adjustment_with_sensible_defaults(): void
    {
        [$user] = $this->employeeWithLeader();
        $this->restDay($user);
        $this->actingAs($user)->post(route('attendance.log'), $this->payload($this->cfeOne));
        $log = AttendanceLog::sole();

        $this->actingAs($user)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Index')
                ->where('todaySchedule.status', 'Restday')
                ->where('todaySchedule.store', null)
                ->where('pendingAdjustment.log_id', strtolower($log->id))
                ->where('pendingAdjustment.reason', 'rest_day')
                ->where('pendingAdjustment.detected_store.name', 'CFE I')
                ->where('pendingAdjustment.request', null)
                ->where('pendingAdjustment.defaults.status', 'On-site')
                ->where('pendingAdjustment.defaults.store_id', $this->cfeOne->id)
                ->where('pendingAdjustment.defaults.start_time', '2026-11-10T09:00:00+08:00')
                ->where('pendingAdjustment.defaults.end_time', '2026-11-10T18:00:00+08:00')
                ->has('adjustmentStores', 2)
            );
    }

    public function test_time_in_outside_the_assigned_store_is_recorded_as_a_location_mismatch(): void
    {
        [$user] = $this->employeeWithLeader();
        [$schedule, $entry] = $this->onSite($user, $this->cfeTwo);

        $response = $this->actingAs($user)->post(route('attendance.log'), $this->payload($this->cfeOne));

        $log = AttendanceLog::sole();
        $this->assertSame($schedule->id, $log->schedule_id);
        $this->assertSame($entry->id, $log->schedule_store_id);
        $this->assertSame(AttendanceLog::ADJUSTMENT_LOCATION_MISMATCH, $log->adjustment_reason);
        $this->assertSame($this->cfeOne->id, $log->detected_store_id);
        $response->assertRedirect(route('attendance.index'));
    }

    public function test_mismatch_far_from_every_store_is_recorded_without_a_detected_store(): void
    {
        [$user] = $this->employeeWithLeader();
        $this->onSite($user, $this->cfeTwo);

        $this->actingAs($user)->post(route('attendance.log'), $this->payload(null, [
            'latitude' => 14.7000,
            'longitude' => 121.1000,
        ]))->assertRedirect(route('attendance.index'));

        $log = AttendanceLog::sole();
        $this->assertSame(AttendanceLog::ADJUSTMENT_LOCATION_MISMATCH, $log->adjustment_reason);
        $this->assertNull($log->detected_store_id);
    }

    public function test_time_in_inside_the_assigned_store_is_not_flagged(): void
    {
        [$user] = $this->employeeWithLeader();
        $this->onSite($user, $this->cfeTwo);

        $this->actingAs($user)
            ->post(route('attendance.log'), $this->payload($this->cfeTwo))
            ->assertRedirect(route('attendance.logs'));

        $this->assertNull(AttendanceLog::sole()->adjustment_reason);
    }

    public function test_rest_day_request_goes_to_the_reports_to_leader_and_approval_applies_it(): void
    {
        [$user, $leader] = $this->employeeWithLeader();
        [$schedule, $entry] = $this->restDay($user);
        $this->actingAs($user)->post(route('attendance.log'), $this->payload($this->cfeOne));
        $log = AttendanceLog::sole();

        $this->actingAs($user)
            ->from(route('attendance.index'))
            ->post(route('attendance.adjustment-request', strtolower($log->id)), [
                'status' => 'On-site',
                'store_id' => $this->cfeOne->id,
                'start_time' => '2026-11-10T09:00',
                'end_time' => '2026-11-10T18:00',
                'requester_remarks' => 'Covered the CFE I store opening.',
            ])
            ->assertRedirect(route('attendance.index'))
            ->assertSessionHasNoErrors();

        $changeRequest = ScheduleChangeRequest::sole();
        $this->assertSame('pending', $changeRequest->status);
        $this->assertSame('schedule_change', $changeRequest->request_type);
        $this->assertSame(strtolower($log->id), strtolower($changeRequest->attendance_log_id));
        $this->assertSame([$leader->id], $changeRequest->assigned_approver_ids);
        $this->assertSame('2026-11-10', $changeRequest->requested_payload['scope_date']);
        $this->assertSame('On-site', $changeRequest->requested_payload['status']);
        $this->assertSame($entry->id, (int) $changeRequest->requested_payload['stores'][0]['id']);
        $this->assertSame($this->cfeOne->id, (int) $changeRequest->requested_payload['stores'][0]['store_id']);
        Mail::assertSent(ScheduleChangeRequestNotification::class, fn ($mail) => $mail->hasTo($leader->email));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $leader->id]);

        // The banner now shows the request as pending (no second request offered).
        $this->actingAs($user)
            ->get(route('attendance.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingAdjustment.request.status', 'pending')
                ->has('adjustmentStores', 0)
            );

        $this->actingAs($leader)
            ->post(route('schedule-change-requests.approve', $changeRequest), ['remarks' => 'OK'])
            ->assertSessionHasNoErrors();

        $schedule->refresh();
        $entry->refresh();
        $this->assertSame('approved', $changeRequest->fresh()->status);
        $this->assertSame('On-site', $schedule->status);
        $this->assertSame($this->cfeOne->id, $entry->store_id);
        $this->assertSame('2026-11-10 09:00:00', $entry->start_time->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10 18:00:00', $entry->end_time->format('Y-m-d H:i:s'));
        // The clock-in stays attached to the same (updated) entry.
        $this->assertSame($entry->id, $log->fresh()->schedule_store_id);
        $this->assertSame($schedule->id, $log->fresh()->schedule_id);
    }

    public function test_location_request_moves_the_entry_to_the_detected_store_on_approval(): void
    {
        [$user, $leader] = $this->employeeWithLeader();
        [$schedule, $entry] = $this->onSite($user, $this->cfeTwo);
        $this->actingAs($user)->post(route('attendance.log'), $this->payload($this->cfeOne));
        $log = AttendanceLog::sole();

        $this->actingAs($user)
            ->post(route('attendance.adjustment-request', $log->id), [
                'status' => 'On-site',
                'store_id' => $this->cfeOne->id,
                'start_time' => '2026-11-10T07:00',
                'end_time' => '2026-11-10T17:00',
                'requester_remarks' => 'Reassigned to CFE I today.',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($leader)
            ->post(route('schedule-change-requests.approve', ScheduleChangeRequest::sole()))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->cfeOne->id, $entry->fresh()->store_id);
        $this->assertSame('On-site', $schedule->fresh()->status);
        $this->assertSame($entry->id, $log->fresh()->schedule_store_id);
    }

    public function test_leader_on_another_active_entity_can_still_approve(): void
    {
        $leaderEntity = Company::create(['name' => 'Leader Entity', 'code' => 'LEAD', 'is_active' => true]);
        $staffEntity = Company::create(['name' => 'Staff Entity', 'code' => 'STAFF', 'is_active' => true]);
        [$user, $leader] = $this->employeeWithLeader();
        $user->update(['company_id' => $staffEntity->id]);
        $leader->update(['company_id' => $leaderEntity->id]);
        [$schedule, $entry] = $this->restDay($user);
        $schedule->forceFill(['company_id' => $staffEntity->id])->save();

        $this->actingAs($user)->post(route('attendance.log'), $this->payload($this->cfeOne));
        $this->actingAs($user)
            ->post(route('attendance.adjustment-request', AttendanceLog::sole()->id), [
                'status' => 'On-site',
                'store_id' => $this->cfeOne->id,
                'start_time' => '2026-11-10T09:00',
                'end_time' => '2026-11-10T18:00',
                'requester_remarks' => 'Rest-day coverage.',
            ])
            ->assertSessionHasNoErrors();
        CompanyContext::flushMemo();

        // The leader's active entity (LEAD) is not the schedule's (STAFF).
        $this->actingAs($leader)
            ->post(route('schedule-change-requests.approve', ScheduleChangeRequest::sole()))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', ScheduleChangeRequest::sole()->status);
        $this->assertSame('On-site', $schedule->fresh()->status);
        $this->assertSame($this->cfeOne->id, $entry->fresh()->store_id);
    }

    public function test_requested_hours_must_still_include_the_clock_in(): void
    {
        [$user] = $this->employeeWithLeader();
        $this->restDay($user);
        $this->actingAs($user)->post(route('attendance.log'), $this->payload($this->cfeOne));
        $log = AttendanceLog::sole();

        $this->actingAs($user)
            ->post(route('attendance.adjustment-request', $log->id), [
                'status' => 'On-site',
                'store_id' => $this->cfeOne->id,
                'start_time' => '2026-11-10T11:00',
                'end_time' => '2026-11-10T20:00',
                'requester_remarks' => 'Late start.',
            ])
            ->assertSessionHasErrors('start_time');

        $this->assertDatabaseCount('schedule_change_requests', 0);
    }

    public function test_request_without_any_leader_is_refused_with_a_clear_error(): void
    {
        // The approver fallback looks for these roles; none of their holders exist here.
        Role::findOrCreate('Admin');
        Role::findOrCreate('Solutions Admin');
        $user = $this->employee();
        $this->restDay($user);
        $this->actingAs($user)->post(route('attendance.log'), $this->payload($this->cfeOne));
        $log = AttendanceLog::sole();

        $this->actingAs($user)
            ->post(route('attendance.adjustment-request', $log->id), [
                'status' => 'On-site',
                'store_id' => $this->cfeOne->id,
                'start_time' => '2026-11-10T09:00',
                'end_time' => '2026-11-10T18:00',
                'requester_remarks' => 'Worked on rest day.',
            ])
            ->assertSessionHasErrors('approver');

        $this->assertDatabaseCount('schedule_change_requests', 0);
    }

    public function test_only_the_owner_can_request_an_adjustment_for_a_clock_in(): void
    {
        [$user] = $this->employeeWithLeader();
        $this->restDay($user);
        $this->actingAs($user)->post(route('attendance.log'), $this->payload($this->cfeOne));
        $log = AttendanceLog::sole();
        $other = $this->employee();

        $this->actingAs($other)
            ->post(route('attendance.adjustment-request', $log->id), [
                'status' => 'On-site',
                'store_id' => $this->cfeOne->id,
                'start_time' => '2026-11-10T09:00',
                'end_time' => '2026-11-10T18:00',
                'requester_remarks' => 'Not mine.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('schedule_change_requests', 0);
    }

    public function test_a_normal_clock_in_cannot_be_turned_into_an_adjustment_request(): void
    {
        [$user] = $this->employeeWithLeader();
        $this->onSite($user, $this->cfeTwo);
        $this->actingAs($user)->post(route('attendance.log'), $this->payload($this->cfeTwo));

        $this->actingAs($user)
            ->post(route('attendance.adjustment-request', AttendanceLog::sole()->id), [
                'status' => 'On-site',
                'store_id' => $this->cfeOne->id,
                'start_time' => '2026-11-10T07:00',
                'end_time' => '2026-11-10T17:00',
                'requester_remarks' => 'Nothing to adjust.',
            ])
            ->assertStatus(422);
    }

    private function employee(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['attendance.view', 'attendance.create']);

        return $user;
    }

    /** @return array{0: User, 1: User} */
    private function employeeWithLeader(): array
    {
        $user = $this->employee();
        $leader = User::factory()->create(['is_manager' => true]);
        $leader->givePermissionTo('schedules.approve');
        $user->managers()->attach($leader->id);

        return [$user, $leader];
    }

    private function store(string $code, string $name, float $latitude, float $longitude): Store
    {
        return Store::create([
            'code' => $code,
            'name' => $name,
            'sector' => 1,
            'area' => 'Metro',
            'brand' => 'GHelpdesk',
            'class' => 'Office',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_meters' => 100,
            'is_active' => true,
        ]);
    }

    private function restDay(User $user): array
    {
        return $this->schedule($user, 'Restday', null, '2026-11-10 00:00:00', '2026-11-10 23:59:00');
    }

    private function onSite(User $user, Store $store): array
    {
        return $this->schedule($user, 'On-site', $store, '2026-11-10 07:00:00', '2026-11-10 17:00:00');
    }

    private function schedule(User $user, string $status, ?Store $store, string $start, string $end): array
    {
        $schedule = Schedule::create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'status' => $status,
            'start_time' => Carbon::parse($start, 'Asia/Manila'),
            'end_time' => Carbon::parse($end, 'Asia/Manila'),
        ]);

        $entry = ScheduleStore::create([
            'schedule_id' => $schedule->id,
            'store_id' => $store?->id,
            'start_time' => Carbon::parse($start, 'Asia/Manila'),
            'end_time' => Carbon::parse($end, 'Asia/Manila'),
            'grace_period_minutes' => 30,
        ]);

        return [$schedule, $entry];
    }

    private function payload(?Store $at, array $overrides = []): array
    {
        return array_merge([
            'latitude' => $at?->latitude,
            'longitude' => $at?->longitude,
            'location_accuracy' => 10,
            'location_captured_at' => Carbon::now('UTC')->toIso8601String(),
            'location_received_at' => Carbon::now('UTC')->toIso8601String(),
            'location_client' => 'native',
            'location_provider' => 'capacitor',
            'photo' => self::TEST_PHOTO,
            'device_info' => 'Attendance Test Device',
            'public_ip' => '127.0.0.1',
        ], $overrides);
    }
}
