<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Schedule;
use App\Models\ScheduleStore;
use App\Models\Store;
use App\Models\User;
use App\Support\AttendanceVisibility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Who may see whose attendance on /attendance/logs.
 *
 * The rule is the reporting line drawn on /departments, plus a per-account
 * department-wide override — never a role. See App\Support\AttendanceVisibility.
 */
class AttendanceLogVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('attendance.logs');
        Permission::findOrCreate(AttendanceVisibility::DEPARTMENT_WIDE_PERMISSION);
    }

    public function test_manager_sees_their_whole_reporting_subtree_but_not_outsiders(): void
    {
        $manager = $this->viewer(['is_manager' => true]);
        $directReport = User::factory()->create(['name' => 'Direct Report']);
        $indirectReport = User::factory()->create(['name' => 'Indirect Report']);
        $outsider = User::factory()->create(['name' => 'Someone Elses Report']);

        $manager->subordinates()->attach($directReport->id);
        $directReport->subordinates()->attach($indirectReport->id);

        foreach ([$manager, $directReport, $indirectReport, $outsider] as $person) {
            $this->logAnEightHourDay($person);
        }

        $this->assertVisibleUsersAre($manager, [$manager, $directReport, $indirectReport], [$outsider]);
    }

    public function test_admin_dev_and_solutions_admin_roles_no_longer_grant_company_wide_sight(): void
    {
        $colleague = User::factory()->create(['name' => 'Unrelated Colleague']);
        $this->logAnEightHourDay($colleague);

        foreach (['Admin', 'Dev', 'Solutions Admin'] as $roleName) {
            Role::findOrCreate($roleName, 'web');

            // is_manager deliberately false: the role is the only thing this
            // account has, and on its own it must reveal nobody else.
            $viewer = $this->viewer(['is_manager' => false]);
            $viewer->assignRole($roleName);
            $this->logAnEightHourDay($viewer);

            $this->assertVisibleUsersAre($viewer, [$viewer], [$colleague], $roleName);
        }
    }

    public function test_department_permission_reveals_the_whole_department_but_no_other_department(): void
    {
        $ownDepartment = Department::create(['name' => 'Technology and Solutions', 'code' => 'TAS', 'is_active' => true]);
        $otherDepartment = Department::create(['name' => 'Operations', 'code' => 'OPS', 'is_active' => true]);

        // Not a manager, and with nobody reporting to them: the permission is the
        // only thing widening this account's view.
        $administrator = $this->viewer(['is_manager' => false, 'department_id' => $ownDepartment->id]);
        $administrator->givePermissionTo(AttendanceVisibility::DEPARTMENT_WIDE_PERMISSION);

        $sameDepartment = User::factory()->create(['name' => 'Same Department', 'department_id' => $ownDepartment->id]);
        $otherDepartmentUser = User::factory()->create(['name' => 'Other Department', 'department_id' => $otherDepartment->id]);
        $noDepartment = User::factory()->create(['name' => 'No Department', 'department_id' => null]);

        foreach ([$administrator, $sameDepartment, $otherDepartmentUser, $noDepartment] as $person) {
            $this->logAnEightHourDay($person);
        }

        $this->assertVisibleUsersAre(
            $administrator,
            [$administrator, $sameDepartment],
            [$otherDepartmentUser, $noDepartment]
        );
    }

    public function test_department_permission_and_reporting_line_are_additive(): void
    {
        $department = Department::create(['name' => 'Technology and Solutions', 'code' => 'TAS', 'is_active' => true]);

        $administrator = $this->viewer(['is_manager' => true, 'department_id' => $department->id]);
        $administrator->givePermissionTo(AttendanceVisibility::DEPARTMENT_WIDE_PERMISSION);

        $sameDepartment = User::factory()->create(['name' => 'Same Department', 'department_id' => $department->id]);
        // Reports to them, but is booked under no department at all.
        $reportElsewhere = User::factory()->create(['name' => 'Report Elsewhere', 'department_id' => null]);
        $administrator->subordinates()->attach($reportElsewhere->id);
        $stranger = User::factory()->create(['name' => 'Stranger', 'department_id' => null]);

        foreach ([$administrator, $sameDepartment, $reportElsewhere, $stranger] as $person) {
            $this->logAnEightHourDay($person);
        }

        $this->assertVisibleUsersAre(
            $administrator,
            [$administrator, $sameDepartment, $reportElsewhere],
            [$stranger]
        );
    }

    public function test_employee_without_reports_sees_only_their_own_attendance(): void
    {
        $employee = $this->viewer(['is_manager' => false]);
        $colleague = User::factory()->create(['name' => 'Colleague']);

        $this->logAnEightHourDay($employee);
        $this->logAnEightHourDay($colleague);

        $this->assertVisibleUsersAre($employee, [$employee], [$colleague]);
    }

    public function test_permission_is_not_inherited_from_the_admin_gate_bypass(): void
    {
        // Gate::before passes every can() check for the Admin role. The rule is
        // read with hasPermissionTo() precisely so that bypass cannot silently
        // hand the department-wide view back to a role.
        Role::findOrCreate('Admin', 'web');
        $department = Department::create(['name' => 'Technology and Solutions', 'code' => 'TAS', 'is_active' => true]);

        $admin = $this->viewer(['is_manager' => false, 'department_id' => $department->id]);
        $admin->assignRole('Admin');
        $colleague = User::factory()->create(['department_id' => $department->id]);

        $this->assertTrue($admin->can(AttendanceVisibility::DEPARTMENT_WIDE_PERMISSION));
        $this->assertFalse(AttendanceVisibility::seesWholeDepartment($admin));
        $this->assertEqualsCanonicalizing([$admin->id], AttendanceVisibility::visibleUserIds($admin));
        $this->assertNotContains($colleague->id, AttendanceVisibility::visibleUserIds($admin));
    }

    public function test_people_filter_offers_only_visible_users(): void
    {
        $manager = $this->viewer(['is_manager' => true]);
        $report = User::factory()->create(['name' => 'Reports To Me']);
        $outsider = User::factory()->create(['name' => 'Not Mine']);
        $manager->subordinates()->attach($report->id);

        $this->actingAs($manager)
            ->get(route('attendance.logs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users', function ($users) use ($manager, $report, $outsider) {
                    $ids = collect($users)->pluck('id')->map(fn ($id) => (int) $id);

                    return $ids->contains($manager->id)
                        && $ids->contains($report->id)
                        && ! $ids->contains($outsider->id);
                })
            );
    }

    public function test_work_hours_summary_is_scoped_to_the_same_people(): void
    {
        $manager = $this->viewer(['is_manager' => true]);
        $report = User::factory()->create(['name' => 'Reports To Me']);
        $outsider = User::factory()->create(['name' => 'Not Mine']);
        $manager->subordinates()->attach($report->id);

        foreach ([$manager, $report, $outsider] as $person) {
            $this->logAnEightHourDay($person);
        }

        $this->actingAs($manager)
            ->get(route('attendance.logs', ['date_from' => '2026-07-10', 'date_to' => '2026-07-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workHoursSummary', function ($summary) use ($manager, $report, $outsider) {
                    $ids = collect(data_get($summary, 'rows', $summary))
                        ->pluck('user_id')
                        ->filter()
                        ->map(fn ($id) => (int) $id);

                    return $ids->contains($manager->id)
                        && $ids->contains($report->id)
                        && ! $ids->contains($outsider->id);
                })
            );
    }

    /**
     * Drive the real page and assert on the sessions it renders, so the rule is
     * proven where it is enforced rather than only in the support class.
     *
     * @param  array<int, User>  $expected
     * @param  array<int, User>  $hidden
     */
    private function assertVisibleUsersAre(User $viewer, array $expected, array $hidden, string $context = ''): void
    {
        $label = $context ? " ({$context})" : '';

        $this->assertEqualsCanonicalizing(
            collect($expected)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            AttendanceVisibility::visibleUserIds($viewer),
            'AttendanceVisibility::visibleUserIds did not match'.$label
        );

        $this->actingAs($viewer)
            ->get(route('attendance.logs', ['date_from' => '2026-07-10', 'date_to' => '2026-07-10']))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($expected, $hidden, $label) {
                $page->where('sessions.data', function ($sessions) use ($expected, $hidden, $label) {
                    $ids = collect($sessions)->pluck('user.id')->map(fn ($id) => (int) $id)->all();

                    foreach ($expected as $person) {
                        $this->assertContains((int) $person->id, $ids, "{$person->name} should be listed".$label);
                    }

                    foreach ($hidden as $person) {
                        $this->assertNotContains((int) $person->id, $ids, "{$person->name} must not be listed".$label);
                    }

                    return true;
                });
            });
    }

    private function viewer(array $attributes = []): User
    {
        $viewer = User::factory()->create($attributes + ['name' => 'Viewer '.Str::random(6)]);
        $viewer->givePermissionTo('attendance.logs');

        return $viewer;
    }

    private function logAnEightHourDay(User $user): void
    {
        $store = Store::firstOrCreate(
            ['code' => 'REG-VIS'],
            [
                'name' => 'Visibility Test Store',
                'sector' => 1,
                'area' => 'Metro Manila',
                'brand' => 'GHelpdesk',
                'class' => 'Regular',
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'radius_meters' => 100,
                'is_active' => true,
            ]
        );

        $schedule = Schedule::create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'status' => 'On-site',
            'start_time' => Carbon::parse('2026-07-10 08:00:00', 'Asia/Manila'),
            'end_time' => Carbon::parse('2026-07-10 17:00:00', 'Asia/Manila'),
        ]);

        $scheduleStore = ScheduleStore::create([
            'schedule_id' => $schedule->id,
            'store_id' => $store->id,
            'start_time' => Carbon::parse('2026-07-10 08:00:00', 'Asia/Manila'),
            'end_time' => Carbon::parse('2026-07-10 17:00:00', 'Asia/Manila'),
            'grace_period_minutes' => 30,
        ]);

        foreach (['time_in' => '2026-07-10 08:00:00', 'time_out' => '2026-07-10 17:00:00'] as $type => $at) {
            AttendanceLog::create([
                'user_id' => $user->id,
                'schedule_id' => $schedule->id,
                'schedule_store_id' => $scheduleStore->id,
                'type' => $type,
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'photo_path' => 'attendance/test/'.$type.'.png',
                'log_time' => Carbon::parse($at, 'Asia/Manila'),
            ]);
        }
    }
}
