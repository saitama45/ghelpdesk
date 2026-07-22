<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ScheduleReportDatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_count_drill_down_returns_department_code_and_each_schedule_date(): void
    {
        $department = Department::create([
            'name' => 'Information Technology',
            'code' => 'IT',
            'is_active' => true,
        ]);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::findOrCreate('schedules.view', 'web'));
        $employee = User::factory()->create([
            'employee_id_no' => 'EMP-2001',
            'department' => $department->name,
            'department_id' => $department->id,
            'org_path' => $department->name,
            'is_vacant' => false,
        ]);
        Schedule::create([
            'user_id' => $employee->id,
            'status' => 'On-site',
            'start_time' => '2026-07-10 08:00:00',
            'end_time' => '2026-07-11 17:00:00',
        ]);

        $this->actingAs($viewer)
            ->getJson(route('schedules.report-dates', [
                'user_id' => $employee->id,
                'year' => 2026,
                'status' => 'On-site',
            ]))
            ->assertOk()
            ->assertJsonPath('user.employee_id_no', 'EMP-2001')
            ->assertJsonPath('user.department_code', 'IT')
            ->assertJsonPath('entries.0.date', '2026-07-10')
            ->assertJsonPath('entries.1.date', '2026-07-11')
            ->assertJsonCount(2, 'entries');
    }
}
