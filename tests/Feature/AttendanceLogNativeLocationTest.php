<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\ScheduleStore;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
