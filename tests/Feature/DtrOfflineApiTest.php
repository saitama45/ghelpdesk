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
use Tests\TestCase;

class DtrOfflineApiTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PHOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WnSUswAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_offline_bootstrap_returns_only_authenticated_users_dtr_schedule_segments(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $store = $this->createStore(['radius_meters' => 0]);
        $otherStore = $this->createStore(['code' => 'STR-OTHER', 'name' => 'Other Store']);

        $schedule = $this->createSchedule($user, 'On-site', '2026-05-17 08:00:00', '2026-05-17 17:00:00');
        $scheduleStore = ScheduleStore::create([
            'schedule_id' => $schedule->id,
            'store_id' => $store->id,
            'start_time' => Carbon::parse('2026-05-17 08:00:00', 'Asia/Manila'),
            'end_time' => Carbon::parse('2026-05-17 12:00:00', 'Asia/Manila'),
            'grace_period_minutes' => 30,
        ]);

        $this->createSchedule($user, 'Restday', '2026-05-18 08:00:00', '2026-05-18 17:00:00');
        $this->createSchedule($user, 'On-site', '2026-05-24 08:00:00', '2026-05-24 17:00:00');
        $otherSchedule = $this->createSchedule($otherUser, 'On-site', '2026-05-17 08:00:00', '2026-05-17 17:00:00');
        ScheduleStore::create([
            'schedule_id' => $otherSchedule->id,
            'store_id' => $otherStore->id,
            'start_time' => Carbon::parse('2026-05-17 08:00:00', 'Asia/Manila'),
            'end_time' => Carbon::parse('2026-05-17 17:00:00', 'Asia/Manila'),
            'grace_period_minutes' => 30,
        ]);

        $response = $this->withHeaders($this->bearerHeaders($user))
            ->getJson('/api/dtr/offline-bootstrap?from=2026-05-17&days=7');

        $response->assertOk()
            ->assertJsonCount(1, 'data.schedules')
            ->assertJsonPath('data.schedules.0.id', (string) $schedule->id)
            ->assertJsonPath('data.schedules.0.schedule_store_id', (string) $scheduleStore->id)
            ->assertJsonPath('data.schedules.0.user_id', (string) $user->id)
            ->assertJsonPath('data.schedules.0.status', 'On-site')
            ->assertJsonPath('data.schedules.0.start_time', '2026-05-17T00:00:00+00:00')
            ->assertJsonPath('data.schedules.0.end_time', '2026-05-17T04:00:00+00:00')
            ->assertJsonPath('data.schedules.0.store.id', (string) $store->id)
            ->assertJsonPath('data.schedules.0.store.code', $store->code)
            ->assertJsonPath('data.schedules.0.store.latitude', 14.5995)
            ->assertJsonPath('data.schedules.0.store.longitude', 120.9842)
            ->assertJsonPath('data.schedules.0.store.radius_meters', 100);
    }

    public function test_offline_bootstrap_requires_authentication(): void
    {
        $this->getJson('/api/dtr/offline-bootstrap?from=2026-05-17&days=7')
            ->assertUnauthorized();
    }

    public function test_offline_bootstrap_returns_empty_array_when_no_schedules_match(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->bearerHeaders($user))
            ->getJson('/api/dtr/offline-bootstrap?from=2026-05-17&days=7')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'schedules' => [],
                ],
            ]);
    }

    public function test_dtr_log_stores_client_request_id_and_retries_without_duplicate_insert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-17 08:05:00', 'Asia/Manila'));

        [$user, $store] = $this->createActiveAttendanceContext();
        $clientRequestId = '550e8400-e29b-41d4-a716-446655440000';
        $payload = $this->logPayload($store, [
            'client_request_id' => $clientRequestId,
            'location_provider' => 'android',
        ]);

        $firstResponse = $this->withHeaders($this->bearerHeaders($user))->postJson('/api/dtr/log', $payload);
        $retryResponse = $this->withHeaders($this->bearerHeaders($user))->postJson('/api/dtr/log', $payload);

        $firstResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('log.client_request_id', $clientRequestId)
            ->assertJsonPath('log.type', 'time_in');

        $retryResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('log.client_request_id', $clientRequestId)
            ->assertJsonPath('log.type', 'time_in');

        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $user->id,
            'client_request_id' => $clientRequestId,
            'type' => 'time_in',
            'location_provider' => 'android',
        ]);
    }

    public function test_same_client_request_id_is_allowed_for_different_users(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-17 08:05:00', 'Asia/Manila'));

        [$firstUser, $firstStore] = $this->createActiveAttendanceContext();
        [$secondUser, $secondStore] = $this->createActiveAttendanceContext([
            'code' => 'STR-SECOND',
            'name' => 'Second Store',
            'latitude' => 14.6000,
            'longitude' => 120.9850,
        ]);

        $clientRequestId = '550e8400-e29b-41d4-a716-446655440000';

        $this->withHeaders($this->bearerHeaders($firstUser))
            ->postJson('/api/dtr/log', $this->logPayload($firstStore, ['client_request_id' => $clientRequestId]))
            ->assertOk();

        auth()->forgetGuards();

        $this->withHeaders($this->bearerHeaders($secondUser))
            ->postJson('/api/dtr/log', $this->logPayload($secondStore, [
                'client_request_id' => $clientRequestId,
                'location_provider' => 'ios',
            ]))
            ->assertOk()
            ->assertJsonPath('log.location_provider', 'ios');

        $this->assertDatabaseCount('attendance_logs', 2);
        $this->assertSame(2, AttendanceLog::where('client_request_id', $clientRequestId)->count());
    }

    public function test_dtr_log_without_client_request_id_keeps_existing_behavior(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-17 08:05:00', 'Asia/Manila'));

        [$user, $store] = $this->createActiveAttendanceContext();

        $this->withHeaders($this->bearerHeaders($user))
            ->postJson('/api/dtr/log', $this->logPayload($store))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('log.client_request_id', null);

        $this->assertDatabaseHas('attendance_logs', [
            'user_id' => $user->id,
            'client_request_id' => null,
            'type' => 'time_in',
        ]);
    }

    private function bearerHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer ' . $user->createToken('test-device')->plainTextToken,
        ];
    }

    private function createActiveAttendanceContext(array $storeOverrides = []): array
    {
        $user = User::factory()->create();
        $store = $this->createStore($storeOverrides);
        $schedule = $this->createSchedule($user, 'On-site', '2026-05-17 08:00:00', '2026-05-17 17:00:00');

        ScheduleStore::create([
            'schedule_id' => $schedule->id,
            'store_id' => $store->id,
            'start_time' => Carbon::parse('2026-05-17 08:00:00', 'Asia/Manila'),
            'end_time' => Carbon::parse('2026-05-17 17:00:00', 'Asia/Manila'),
            'grace_period_minutes' => 30,
        ]);

        return [$user, $store, $schedule];
    }

    private function createSchedule(User $user, string $status, string $start, string $end): Schedule
    {
        return Schedule::create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'status' => $status,
            'start_time' => Carbon::parse($start, 'Asia/Manila'),
            'end_time' => Carbon::parse($end, 'Asia/Manila'),
        ]);
    }

    private function createStore(array $overrides = []): Store
    {
        return Store::create(array_merge([
            'code' => 'STR-OFFLINE',
            'name' => 'Offline DTR Store',
            'sector' => 1,
            'area' => 'Metro',
            'brand' => 'GHelpdesk',
            'class' => 'Regular',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'radius_meters' => 100,
            'is_active' => true,
        ], $overrides));
    }

    private function logPayload(Store $store, array $overrides = []): array
    {
        return array_merge([
            'latitude' => $store->latitude,
            'longitude' => $store->longitude,
            'location_accuracy' => 10,
            'location_captured_at' => Carbon::now('UTC')->toIso8601String(),
            'location_received_at' => Carbon::now('UTC')->toIso8601String(),
            'location_client' => 'native',
            'location_provider' => 'capacitor',
            'photo' => self::TEST_PHOTO,
            'device_info' => 'Offline DTR Test Device',
            'public_ip' => '127.0.0.1',
        ], $overrides);
    }
}
