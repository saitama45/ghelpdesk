<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\DepartmentNode;
use App\Models\Schedule;
use App\Models\ScheduleStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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
        Permission::firstOrCreate(['name' => 'schedules.view']);
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

    public function test_top_level_department_manager_can_edit_schedule_when_intermediate_manager_is_inactive(): void
    {
        [$department, $businessSolutions, $processExcellence] = $this->scheduleDepartmentHierarchy();
        $yssa = $this->departmentUser('Yssa Dysangco', 'yssa.dysangco@tablegroup.com.ph', $department, null, true, true);
        $lea = $this->departmentUser('Lea Dizon', 'lea.dizon@tablegroup.com.ph', $department, $businessSolutions, false, false);
        $patrick = $this->departmentUser('Patrick Lopez', 'patrick.lopez@tablegroup.com.ph', $department, $processExcellence, true, true);

        $yssa->givePermissionTo('schedules.view');
        $lea->managers()->attach($yssa->id);
        $patrick->managers()->attach($lea->id);

        $schedule = $this->createSchedule($patrick, 'WFH', 'patrick schedule');
        $store = Store::create([
            'code' => 'STR-003',
            'name' => 'Schedule Store',
            'sector' => 1,
            'area' => 'Metro',
            'brand' => 'Brand',
            'cluster' => 'Cluster',
            'is_active' => true,
        ]);

        $this->actingAs($yssa)
            ->get(route('schedules.index', [
                'department_id' => $department->id,
                'start' => '2026-05-01',
                'end' => '2026-05-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Schedules/Index')
                ->where('schedules.0.id', $schedule->id)
                ->where('schedules.0.user_id', $patrick->id)
                ->where('schedules.0.can_edit', true)
                ->where('editableUserIds', fn ($ids) => collect($ids)->map(fn ($id) => (int) $id)->contains($patrick->id))
            );

        $response = $this->actingAs($yssa)->put(route('schedules.update', $schedule), [
            'user_id' => $patrick->id,
            'status' => 'On-site',
            'stores' => [[
                'store_id' => $store->id,
                'ticket_id' => null,
                'start_time' => '2026-05-10T08:00',
                'end_time' => '2026-05-10T17:00',
                'grace_period_minutes' => 30,
                'remarks' => 'updated by top-level manager',
            ]],
            'pickup_start' => null,
            'pickup_end' => null,
            'backlogs_start' => null,
            'backlogs_end' => null,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame('On-site', $schedule->fresh()->status);
    }

    public function test_user_outside_department_cannot_edit_another_departments_schedule(): void
    {
        [$department, , $processExcellence] = $this->scheduleDepartmentHierarchy();
        $otherDepartment = Department::create([
            'name' => 'Operations',
            'code' => 'OPS',
            'is_active' => true,
        ]);
        $outsider = $this->departmentUser('Outside Manager', 'outside@example.test', $otherDepartment, null, true, true);
        $patrick = $this->departmentUser('Patrick Lopez', 'patrick.lopez@tablegroup.com.ph', $department, $processExcellence, true, true);
        $schedule = $this->createSchedule($patrick, 'WFH', 'patrick schedule');

        $this->actingAs($outsider)
            ->put(route('schedules.update', $schedule), [
                'user_id' => $patrick->id,
                'status' => 'Restday',
                'stores' => [[
                    'store_id' => null,
                    'ticket_id' => null,
                    'start_time' => '2026-05-10T08:00',
                    'end_time' => '2026-05-10T17:00',
                    'grace_period_minutes' => 30,
                    'remarks' => 'should fail',
                ]],
                'pickup_start' => null,
                'pickup_end' => null,
                'backlogs_start' => null,
                'backlogs_end' => null,
            ])
            ->assertForbidden();
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

    private function scheduleDepartmentHierarchy(): array
    {
        $department = Department::create([
            'name' => 'Technology And Solutions',
            'code' => 'TAS',
            'is_active' => true,
        ]);

        $businessSolutions = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Business Solutions',
            'code' => 'BS',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $processExcellence = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $businessSolutions->id,
            'name' => 'Process Excellence',
            'code' => 'PE',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$department, $businessSolutions, $processExcellence];
    }

    private function departmentUser(
        string $name,
        string $email,
        Department $department,
        ?DepartmentNode $node = null,
        bool $active = true,
        bool $manager = false
    ): User {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'department' => $department->name,
            'department_id' => $department->id,
            'department_node_id' => $node?->id,
            'org_path' => $node ? $this->nodePath($node) : null,
            'is_active' => $active,
            'is_manager' => $manager,
        ]);
    }

    private function nodePath(DepartmentNode $node): string
    {
        $parts = [];
        $current = $node;

        while ($current) {
            array_unshift($parts, $current->name);
            $current = $current->parent_id ? DepartmentNode::find($current->parent_id) : null;
        }

        return implode(' > ', $parts);
    }
}
