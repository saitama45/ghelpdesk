<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\ScheduleStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ScheduleDuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'schedules.delete']);
    }

    public function test_duplicate_scan_detects_location_rows_even_when_metadata_differs(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('schedules.delete');
        $scheduledUser = User::factory()->create(['name' => 'Field User']);
        $store = Store::create([
            'code' => 'STR-001',
            'name' => 'Main Store',
            'sector' => 1,
            'area' => 'Metro',
            'brand' => 'Brand',
            'cluster' => 'Cluster',
            'is_active' => true,
        ]);

        $firstSchedule = $this->createSchedule($scheduledUser, 'On-site', 'first copy');
        $secondSchedule = $this->createSchedule($scheduledUser, 'On-site', 'second copy');

        ScheduleStore::create([
            'schedule_id' => $firstSchedule->id,
            'store_id' => $store->id,
            'start_time' => '2026-05-10 07:00:00',
            'end_time' => '2026-05-10 17:00:00',
            'grace_period_minutes' => 30,
            'remarks' => 'original visit note',
        ]);

        ScheduleStore::create([
            'schedule_id' => $secondSchedule->id,
            'store_id' => $store->id,
            'start_time' => '2026-05-10 07:00:00',
            'end_time' => '2026-05-10 17:00:00',
            'grace_period_minutes' => 45,
            'remarks' => 'copied visit note',
        ]);

        $response = $this->actingAs($admin)->getJson('/schedules/duplicates?start=2026-05-01&end=2026-05-31');

        $response->assertOk()
            ->assertJsonPath('group_count', 1)
            ->assertJsonPath('duplicate_count', 1);
    }

    public function test_duplicate_scan_and_cleanup_handles_schedules_without_location_rows(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('schedules.delete');
        $scheduledUser = User::factory()->create(['name' => 'Remote User']);

        $this->createSchedule($scheduledUser, 'WFH', 'first WFH');
        $this->createSchedule($scheduledUser, 'WFH', 'duplicate WFH');

        $scanResponse = $this->actingAs($admin)->getJson('/schedules/duplicates?start=2026-05-01&end=2026-05-31');

        $scanResponse->assertOk()
            ->assertJsonPath('group_count', 1)
            ->assertJsonPath('duplicate_count', 1);

        $cleanupResponse = $this->actingAs($admin)->deleteJson('/schedules/duplicates', [
            'start' => '2026-05-01',
            'end' => '2026-05-31',
        ]);

        $cleanupResponse->assertOk()
            ->assertJsonPath('deleted_schedules', 1);

        $this->assertSame(1, Schedule::where('user_id', $scheduledUser->id)->count());
    }

    public function test_location_can_be_added_to_attended_schedule_without_changing_time_window(): void
    {
        $scheduledUser = User::factory()->create();
        $store = Store::create([
            'code' => 'STR-002',
            'name' => 'Assigned Store',
            'sector' => 1,
            'area' => 'Metro',
            'brand' => 'Brand',
            'cluster' => 'Cluster',
            'is_active' => true,
        ]);
        $schedule = $this->createSchedule($scheduledUser, 'On-site', 'needs location');

        AttendanceLog::create([
            'user_id' => $scheduledUser->id,
            'schedule_id' => $schedule->id,
            'schedule_store_id' => null,
            'type' => 'time_in',
            'log_time' => '2026-05-10 08:00:00',
        ]);

        AttendanceLog::create([
            'user_id' => $scheduledUser->id,
            'schedule_id' => $schedule->id,
            'schedule_store_id' => null,
            'type' => 'time_out',
            'log_time' => '2026-05-10 18:00:00',
        ]);

        $response = $this->actingAs($scheduledUser)->put("/schedules/{$schedule->id}", [
            'user_id' => $scheduledUser->id,
            'status' => 'On-site',
            'stores' => [[
                'store_id' => $store->id,
                'ticket_id' => null,
                'start_time' => '2026-05-10T07:00',
                'end_time' => '2026-05-10T17:00',
                'grace_period_minutes' => 30,
                'remarks' => 'needs location',
            ]],
            'pickup_start' => null,
            'pickup_end' => null,
            'backlogs_start' => null,
            'backlogs_end' => null,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('schedule_stores', [
            'schedule_id' => $schedule->id,
            'store_id' => $store->id,
        ]);
    }

    private function createSchedule(User $scheduledUser, string $status, string $remarks): Schedule
    {
        return Schedule::create([
            'user_id' => $scheduledUser->id,
            'created_by' => $scheduledUser->id,
            'updated_by' => $scheduledUser->id,
            'status' => $status,
            'start_time' => '2026-05-10 07:00:00',
            'end_time' => '2026-05-10 17:00:00',
            'remarks' => $remarks,
        ]);
    }
}
