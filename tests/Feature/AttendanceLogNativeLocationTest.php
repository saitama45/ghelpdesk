<?php

namespace Tests\Feature;

use App\Http\Controllers\AttendanceController;
use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\ScheduleStore;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendanceLogNativeLocationTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PHOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WnSUswAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('attendance.create');
        Storage::fake('public');
    }

    public function test_geofenced_log_is_accepted_from_browser_when_request_was_recent_even_if_provider_timestamp_is_old(): void
    {
        [$user, $store, $schedule, $scheduleStore] = $this->createGeofencedAttendanceContext();
        $capturedAt = Carbon::now('UTC')->subMinutes(5);
        $receivedAt = Carbon::now('UTC')->subSeconds(5);

        $response = $this->actingAs($user)
            ->post(route('attendance.log'), $this->payload([
                'latitude' => $store->latitude,
                'longitude' => $store->longitude,
                'location_accuracy' => 80,
                'location_captured_at' => $capturedAt->toIso8601String(),
                'location_received_at' => $receivedAt->toIso8601String(),
                'location_client' => 'web',
                'location_provider' => 'browser',
            ]));

        $response->assertRedirect(route('attendance.logs'));
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'schedule_store_id' => $scheduleStore->id,
            'type' => 'time_in',
            'location_client' => 'web',
            'location_provider' => 'browser',
        ]);

        $log = AttendanceLog::firstOrFail();
        $this->assertNotNull($log->location_captured_at);
        $this->assertNotNull($log->location_received_at);
    }

    public function test_geofenced_log_is_rejected_from_browser_when_location_is_stale(): void
    {
        [$user, $store] = $this->createGeofencedAttendanceContext();

        $response = $this->actingAs($user)
            ->from('/dtr')
            ->post(route('attendance.log'), $this->payload([
                'latitude' => $store->latitude,
                'longitude' => $store->longitude,
                'location_accuracy' => 80,
                'location_captured_at' => Carbon::now('UTC')->subMinutes(5)->toIso8601String(),
                'location_received_at' => Carbon::now('UTC')->subSeconds(61)->toIso8601String(),
                'location_client' => 'web',
                'location_provider' => 'browser',
            ]));

        $response->assertRedirect('/dtr');
        $response->assertSessionHas('error');
        $this->assertSame(
            'Browser location is stale. Refresh GPS and wait for a fresh fix from your current position before logging attendance.',
            session('error')
        );
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_geofenced_log_is_rejected_from_browser_when_accuracy_is_too_broad(): void
    {
        [$user, $store] = $this->createGeofencedAttendanceContext();

        $response = $this->actingAs($user)
            ->from('/dtr')
            ->post(route('attendance.log'), $this->payload([
                'latitude' => $store->latitude,
                'longitude' => $store->longitude,
                'location_accuracy' => 250,
                'location_captured_at' => Carbon::now('UTC')->toIso8601String(),
                'location_received_at' => Carbon::now('UTC')->toIso8601String(),
                'location_client' => 'web',
                'location_provider' => 'browser',
            ]));

        $response->assertRedirect('/dtr');
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Browser location accuracy is too broad', session('error'));
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_geofenced_log_is_rejected_when_outside_store_radius(): void
    {
        [$user, $store] = $this->createGeofencedAttendanceContext();

        $response = $this->actingAs($user)
            ->from('/dtr')
            ->post(route('attendance.log'), $this->payload([
                'latitude' => $store->latitude + 0.01,
                'longitude' => $store->longitude + 0.01,
                'location_accuracy' => 10,
                'location_client' => 'native',
                'location_provider' => 'capacitor',
            ]));

        $response->assertRedirect('/dtr');
        $response->assertSessionHas('error');
        $this->assertStringContainsString('outside the active schedule store vicinity', session('error'));
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_valid_native_geofenced_log_is_accepted_and_stores_location_metadata(): void
    {
        [$user, $store, $schedule, $scheduleStore] = $this->createGeofencedAttendanceContext();

        $response = $this->actingAs($user)
            ->post(route('attendance.log'), $this->payload([
                'latitude' => $store->latitude,
                'longitude' => $store->longitude,
                'location_accuracy' => 8.5,
                'location_captured_at' => Carbon::now('UTC')->subSeconds(3)->toIso8601String(),
                'location_client' => 'native',
                'location_provider' => 'capacitor',
            ]));

        $response->assertRedirect(route('attendance.logs'));

        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'schedule_store_id' => $scheduleStore->id,
            'type' => 'time_in',
            'location_accuracy' => 8.5,
            'location_client' => 'native',
            'location_provider' => 'capacitor',
        ]);

        $log = AttendanceLog::firstOrFail();
        Storage::disk('public')->assertExists($log->photo_path);
        $this->assertNotNull($log->location_captured_at);
    }

    public function test_web_attendance_log_reuses_client_request_id_without_duplicate_insert(): void
    {
        [$user, $store, $schedule, $scheduleStore] = $this->createGeofencedAttendanceContext();
        $clientRequestId = '550e8400-e29b-41d4-a716-446655440000';
        $payload = $this->payload([
            'client_request_id' => $clientRequestId,
            'latitude' => $store->latitude,
            'longitude' => $store->longitude,
            'location_accuracy' => 10,
            'location_client' => 'native',
            'location_provider' => 'capacitor',
        ]);

        $firstResponse = $this->actingAs($user)->post(route('attendance.log'), $payload);
        $retryResponse = $this->actingAs($user)->post(route('attendance.log'), $payload);

        $firstResponse->assertRedirect(route('attendance.logs'));
        $retryResponse->assertRedirect(route('attendance.logs'));

        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $user->id,
            'client_request_id' => $clientRequestId,
            'schedule_id' => $schedule->id,
            'schedule_store_id' => $scheduleStore->id,
            'type' => 'time_in',
        ]);
    }

    public function test_web_attendance_log_rejects_stale_time_out_when_no_time_in_exists(): void
    {
        [$user, $store] = $this->createGeofencedAttendanceContext();

        $response = $this->actingAs($user)
            ->from('/dtr')
            ->post(route('attendance.log'), $this->payload([
                'expected_type' => 'time_out',
                'latitude' => $store->latitude,
                'longitude' => $store->longitude,
                'location_client' => 'native',
                'location_provider' => 'capacitor',
            ]));

        $response->assertRedirect('/dtr');
        $response->assertSessionHasErrors('attendance');
        $response->assertSessionHas('error', 'Attendance state changed before saving. Please refresh DTR and try again.');
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_web_time_out_is_allowed_outside_geofence_after_existing_time_in(): void
    {
        [$user, $store, $schedule, $scheduleStore] = $this->createGeofencedAttendanceContext();

        $timeInLog = AttendanceLog::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'schedule_store_id' => $scheduleStore->id,
            'type' => 'time_in',
            'latitude' => $store->latitude,
            'longitude' => $store->longitude,
            'photo_path' => 'attendance/test/time-in.png',
            'log_time' => Carbon::now('Asia/Manila')->subMinutes(10),
        ]);
        $timeInLog->forceFill([
            'created_at' => Carbon::now('Asia/Manila')->subMinutes(10),
            'updated_at' => Carbon::now('Asia/Manila')->subMinutes(10),
        ])->save();

        $response = $this->actingAs($user)
            ->post(route('attendance.log'), $this->payload([
                'expected_type' => 'time_out',
                'latitude' => $store->latitude + 0.01,
                'longitude' => $store->longitude + 0.01,
                'location_client' => 'native',
                'location_provider' => 'capacitor',
            ]));

        $response->assertRedirect(route('attendance.logs'));
        $this->assertDatabaseCount('attendance_logs', 2);
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'schedule_store_id' => $scheduleStore->id,
            'type' => 'time_out',
        ]);
    }

    public function test_web_duplicate_client_request_id_must_match_expected_type(): void
    {
        [$user, $store] = $this->createGeofencedAttendanceContext();
        $clientRequestId = '550e8400-e29b-41d4-a716-446655440000';

        $this->actingAs($user)
            ->post(route('attendance.log'), $this->payload([
                'client_request_id' => $clientRequestId,
                'expected_type' => 'time_in',
                'latitude' => $store->latitude,
                'longitude' => $store->longitude,
            ]))
            ->assertRedirect(route('attendance.logs'));

        $response = $this->actingAs($user)
            ->from('/dtr')
            ->post(route('attendance.log'), $this->payload([
                'client_request_id' => $clientRequestId,
                'expected_type' => 'time_out',
                'latitude' => $store->latitude,
                'longitude' => $store->longitude,
            ]));

        $response->assertRedirect('/dtr');
        $response->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_work_hours_summary_keeps_actual_times_isolated_per_user(): void
    {
        $manager = User::factory()->create(['is_manager' => true]);
        $otherUser = User::factory()->create(['name' => 'Earlier User']);
        $targetUser = User::factory()->create(['name' => 'Gen Magbanua']);

        $store = Store::create([
            'code' => 'STR-WORK-HOURS',
            'name' => 'Work Hours Store',
            'sector' => 1,
            'area' => 'Metro',
            'brand' => 'GHelpdesk',
            'class' => 'Regular',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'radius_meters' => 100,
            'is_active' => true,
        ]);

        $otherSchedule = $this->createScheduleWithStore($otherUser, $store, '2026-05-20 07:00:00', '2026-05-20 17:00:00');
        $targetSchedule = $this->createScheduleWithStore($targetUser, $store, '2026-05-20 07:00:00', '2026-05-20 17:00:00');

        AttendanceLog::create([
            'user_id' => $otherUser->id,
            'schedule_id' => $otherSchedule['schedule']->id,
            'schedule_store_id' => $otherSchedule['schedule_store']->id,
            'type' => 'time_in',
            'latitude' => $store->latitude,
            'longitude' => $store->longitude,
            'photo_path' => 'attendance/test/other.png',
            'log_time' => Carbon::parse('2026-05-20 06:56:00', 'Asia/Manila'),
        ]);

        AttendanceLog::create([
            'user_id' => $targetUser->id,
            'schedule_id' => $targetSchedule['schedule']->id,
            'schedule_store_id' => $targetSchedule['schedule_store']->id,
            'type' => 'time_in',
            'latitude' => $store->latitude,
            'longitude' => $store->longitude,
            'photo_path' => 'attendance/test/target-in.png',
            'log_time' => Carbon::parse('2026-05-20 07:36:00', 'Asia/Manila'),
        ]);

        AttendanceLog::create([
            'user_id' => $targetUser->id,
            'schedule_id' => $targetSchedule['schedule']->id,
            'schedule_store_id' => $targetSchedule['schedule_store']->id,
            'type' => 'time_out',
            'latitude' => $store->latitude,
            'longitude' => $store->longitude,
            'photo_path' => 'attendance/test/target-out.png',
            'log_time' => Carbon::parse('2026-05-20 09:20:00', 'Asia/Manila'),
        ]);

        $this->actingAs($manager);
        $request = Request::create('/attendance/logs', 'GET', [
            'date_from' => '2026-05-20',
            'date_to' => '2026-05-20',
        ]);
        $controller = app(AttendanceController::class);
        $method = new \ReflectionMethod($controller, 'buildWorkHoursSummary');
        $method->setAccessible(true);

        $summary = collect($method->invoke($controller, $request, '2026-05-20', '2026-05-20'))
            ->firstWhere('user_id', $targetUser->id);

        $this->assertSame('07:36', $summary['detail_dates'][0]['actual_time_in']);
        $this->assertSame('09:20', $summary['detail_dates'][0]['actual_time_out']);

        $searchRequest = Request::create('/attendance/logs', 'GET', [
            'date_from' => '2026-05-20',
            'date_to' => '2026-05-20',
            'search' => 'Gen',
        ]);
        $searchedSummary = collect($method->invoke($controller, $searchRequest, '2026-05-20', '2026-05-20'));

        $this->assertCount(1, $searchedSummary);
        $this->assertSame($targetUser->id, $searchedSummary->first()['user_id']);
    }

    private function createGeofencedAttendanceContext(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo('attendance.create');

        $store = Store::create([
            'code' => 'STR-NATIVE-001',
            'name' => 'Native Attendance Store',
            'sector' => 1,
            'area' => 'Metro',
            'brand' => 'GHelpdesk',
            'class' => 'Regular',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'radius_meters' => 100,
            'is_active' => true,
        ]);

        $now = Carbon::now('Asia/Manila');
        $schedule = Schedule::create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'status' => 'On-site',
            'start_time' => $now->copy()->subHour(),
            'end_time' => $now->copy()->addHour(),
        ]);

        $scheduleStore = ScheduleStore::create([
            'schedule_id' => $schedule->id,
            'store_id' => $store->id,
            'start_time' => $now->copy()->subHour(),
            'end_time' => $now->copy()->addHour(),
            'grace_period_minutes' => 30,
        ]);

        return [$user, $store, $schedule, $scheduleStore];
    }

    private function createScheduleWithStore(User $user, Store $store, string $start, string $end): array
    {
        $schedule = Schedule::create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'status' => 'On-site',
            'start_time' => Carbon::parse($start, 'Asia/Manila'),
            'end_time' => Carbon::parse($end, 'Asia/Manila'),
        ]);

        $scheduleStore = ScheduleStore::create([
            'schedule_id' => $schedule->id,
            'store_id' => $store->id,
            'start_time' => Carbon::parse($start, 'Asia/Manila'),
            'end_time' => Carbon::parse($end, 'Asia/Manila'),
            'grace_period_minutes' => 30,
        ]);

        return ['schedule' => $schedule, 'schedule_store' => $scheduleStore];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'latitude' => 14.5995,
            'longitude' => 120.9842,
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
