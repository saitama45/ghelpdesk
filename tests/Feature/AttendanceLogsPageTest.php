<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\ScheduleStore;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendanceLogsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('attendance.logs');
    }

    public function test_logs_page_pairs_sessions_and_summarizes_active_office_locations(): void
    {
        $manager = User::factory()->create(['is_manager' => true]);
        $manager->givePermissionTo('attendance.logs');

        $employee = User::factory()->create(['name' => 'Overnight Employee']);
        $openEmployee = User::factory()->create(['name' => 'Open Session Employee']);
        $regularEmployee = User::factory()->create(['name' => 'Regular Store Employee']);

        $office = $this->createStore('OFF-001', 'Main Corporate Office', 'Office');
        $emptyOffice = $this->createStore('OFF-002', 'Zero Activity Office', 'Office');
        $this->createStore('OFF-003', 'Inactive Office', 'Office', false);
        $regularStore = $this->createStore('REG-001', 'Regular Branch', 'Regular');

        [$schedule, $scheduleStore] = $this->createSchedule(
            $employee,
            $office,
            '2026-07-10 22:00:00',
            '2026-07-11 02:00:00'
        );
        $this->createLog($employee, $schedule, $scheduleStore, 'time_in', '2026-07-10 21:55:00');
        $this->createLog($employee, $schedule, $scheduleStore, 'time_out', '2026-07-11 02:05:00');

        [$openSchedule, $openScheduleStore] = $this->createSchedule(
            $openEmployee,
            $office,
            '2026-07-10 08:00:00',
            '2026-07-10 17:00:00'
        );
        $this->createLog($openEmployee, $openSchedule, $openScheduleStore, 'time_in', '2026-07-10 07:55:00');

        [$regularSchedule, $regularScheduleStore] = $this->createSchedule(
            $regularEmployee,
            $regularStore,
            '2026-07-10 08:00:00',
            '2026-07-10 17:00:00'
        );
        $this->createLog($regularEmployee, $regularSchedule, $regularScheduleStore, 'time_in', '2026-07-10 08:00:00');
        $this->createLog($regularEmployee, $regularSchedule, $regularScheduleStore, 'time_out', '2026-07-10 17:00:00');

        $this->actingAs($manager)
            ->get(route('attendance.logs', [
                'date_from' => '2026-07-10',
                'date_to' => '2026-07-10',
                'perPage' => 10,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Logs')
                ->has('sessions.data', 3)
                ->where('sessions.data', function ($sessions) use ($employee) {
                    $overnight = collect($sessions)->first(
                        fn ($session) => (int) data_get($session, 'user.id') === $employee->id
                    );

                    return data_get($overnight, 'date') === '2026-07-10'
                        && data_get($overnight, 'time_in.log_time') !== null
                        && str_starts_with(data_get($overnight, 'time_out.log_time'), '2026-07-11');
                })
                ->where('officeAttendanceSummary', function ($summary) use ($office, $emptyOffice) {
                    $rows = collect($summary);
                    $activeOffice = $rows->firstWhere('id', $office->id);
                    $zeroOffice = $rows->firstWhere('id', $emptyOffice->id);

                    return $rows->count() === 2
                        && data_get($activeOffice, 'time_in_count') === 2
                        && data_get($activeOffice, 'time_out_count') === 1
                        && data_get($activeOffice, 'open_count') === 1
                        && data_get($zeroOffice, 'time_in_count') === 0
                        && data_get($zeroOffice, 'time_out_count') === 0
                        && data_get($zeroOffice, 'open_count') === 0;
                })
            );
    }

    public function test_store_filter_drills_into_sessions_without_hiding_other_office_counts(): void
    {
        $manager = User::factory()->create(['is_manager' => true]);
        $manager->givePermissionTo('attendance.logs');
        $employee = User::factory()->create();
        $office = $this->createStore('OFF-010', 'Filtered Office', 'Office');
        $regularStore = $this->createStore('REG-010', 'Filtered Regular Store', 'Regular');

        foreach ([$office, $regularStore] as $store) {
            [$schedule, $scheduleStore] = $this->createSchedule(
                $employee,
                $store,
                '2026-07-10 08:00:00',
                '2026-07-10 17:00:00'
            );
            $this->createLog($employee, $schedule, $scheduleStore, 'time_in', '2026-07-10 08:00:00');
        }

        $this->actingAs($manager)
            ->get(route('attendance.logs', [
                'date_from' => '2026-07-10',
                'date_to' => '2026-07-10',
                'store_id' => $regularStore->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sessions.data', 1)
                ->where('sessions.data.0.store.id', $regularStore->id)
                ->where('officeAttendanceSummary.0.id', $office->id)
                ->where('officeAttendanceSummary.0.time_in_count', 1)
            );
    }

    public function test_regular_employee_cannot_see_other_users_sessions_or_office_counts(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('attendance.logs');
        $otherEmployee = User::factory()->create();
        $office = $this->createStore('OFF-020', 'Private Office', 'Office');

        foreach ([$viewer, $otherEmployee] as $employee) {
            [$schedule, $scheduleStore] = $this->createSchedule(
                $employee,
                $office,
                '2026-07-10 08:00:00',
                '2026-07-10 17:00:00'
            );
            $this->createLog($employee, $schedule, $scheduleStore, 'time_in', '2026-07-10 08:00:00');
        }

        $this->actingAs($viewer)
            ->get(route('attendance.logs', [
                'date_from' => '2026-07-10',
                'date_to' => '2026-07-10',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sessions.data', 1)
                ->where('sessions.data.0.user.id', $viewer->id)
                ->where('officeAttendanceSummary.0.time_in_count', 1)
                ->where('officeAttendanceSummary.0.open_count', 1)
            );
    }

    public function test_logs_page_uses_voided_adjustment_photo_for_existing_manual_log(): void
    {
        $manager = User::factory()->create(['is_manager' => true]);
        $manager->givePermissionTo('attendance.logs');
        $employee = User::factory()->create();
        $store = $this->createStore('REG-030', 'Adjusted Store', 'Regular');
        [$schedule, $scheduleStore] = $this->createSchedule(
            $employee,
            $store,
            '2026-07-10 08:00:00',
            '2026-07-10 17:00:00'
        );

        AttendanceLog::create([
            'user_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'schedule_store_id' => $scheduleStore->id,
            'type' => 'time_in',
            'photo_path' => 'attendance/test/original-time-in.png',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'log_time' => Carbon::parse('2026-07-10 07:58:00', 'Asia/Manila'),
            'voided_at' => Carbon::parse('2026-07-10 09:00:00', 'Asia/Manila'),
            'voided_by' => $manager->id,
            'void_reason' => 'Schedule actual time adjustment',
        ]);

        AttendanceLog::create([
            'user_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'schedule_store_id' => $scheduleStore->id,
            'type' => 'time_in',
            'log_time' => Carbon::parse('2026-07-10 08:00:00', 'Asia/Manila'),
            'device_info' => 'Manual schedule actual-time adjustment',
        ]);

        $this->actingAs($manager)
            ->get(route('attendance.logs', [
                'date_from' => '2026-07-10',
                'date_to' => '2026-07-10',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sessions.data', 1)
                ->where('sessions.data.0.time_in.photo_path', null)
                ->where('sessions.data.0.time_in.original_photo_path', 'attendance/test/original-time-in.png')
                ->where('sessions.data.0.time_in.latitude', 14.5995)
                ->where('sessions.data.0.time_in.longitude', 120.9842)
            );
    }

    private function createStore(string $code, string $name, string $class, bool $isActive = true): Store
    {
        return Store::create([
            'code' => $code,
            'name' => $name,
            'sector' => 1,
            'area' => 'Metro Manila',
            'brand' => 'GHelpdesk',
            'class' => $class,
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'radius_meters' => 100,
            'is_active' => $isActive,
        ]);
    }

    private function createSchedule(User $user, Store $store, string $start, string $end): array
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

        return [$schedule, $scheduleStore];
    }

    private function createLog(
        User $user,
        Schedule $schedule,
        ScheduleStore $scheduleStore,
        string $type,
        string $logTime
    ): AttendanceLog {
        return AttendanceLog::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'schedule_store_id' => $scheduleStore->id,
            'type' => $type,
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'photo_path' => 'attendance/test/'.$type.'.png',
            'log_time' => Carbon::parse($logTime, 'Asia/Manila'),
        ]);
    }
}
