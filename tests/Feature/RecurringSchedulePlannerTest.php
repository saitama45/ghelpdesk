<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\ScheduleChangeRequest;
use App\Models\ScheduleStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RecurringSchedulePlannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'schedules.create']);
        Permission::firstOrCreate(['name' => 'schedules.edit']);
        Permission::firstOrCreate(['name' => 'schedules.approve']);
    }

    public function test_preview_expands_multiple_rules_for_multiple_employees(): void
    {
        [$manager, $employees] = $this->managerWithEmployees(2);
        $store = $this->store();

        $response = $this->actingAs($manager)->postJson(route('schedules.recurring.preview'), [
            'month' => '2026-02',
            'user_ids' => $employees->pluck('id')->all(),
            'rules' => [
                $this->rule([6, 7], 'Restday'),
                $this->rule([1, 4], 'On-site', $store->id),
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('counts.total', 32)
            ->assertJsonPath('counts.create', 32)
            ->assertJsonCount(32, 'entries');

        $restDay = collect($response->json('entries'))->firstWhere('status', 'Restday');
        $this->assertNull($restDay['store_id']);
        $this->assertStringEndsWith('T00:00', $restDay['start_time']);
        $this->assertStringEndsWith('T23:59', $restDay['end_time']);
    }

    public function test_preview_can_plan_the_entire_year(): void
    {
        [$manager, $employees] = $this->managerWithEmployees(1);

        $response = $this->actingAs($manager)->postJson(route('schedules.recurring.preview'), [
            'period_type' => 'year',
            'year' => 2026,
            'user_ids' => [$employees->first()->id],
            'rules' => [$this->rule([6, 7], 'Restday')],
        ]);

        $response->assertOk()
            ->assertJsonPath('period_type', 'year')
            ->assertJsonPath('period', '2026')
            ->assertJsonPath('period_start', '2026-01-01')
            ->assertJsonPath('period_end', '2026-12-31')
            ->assertJsonPath('counts.total', 104)
            ->assertJsonCount(104, 'entries');
    }

    public function test_preview_distinguishes_manager_approval_and_protected_dates(): void
    {
        [$manager, $employees] = $this->managerWithEmployees(1);
        $employee = $employees->first();
        $store = $this->store();
        $replaceable = $this->schedule($employee, $store, '2026-06-01');
        $attended = $this->schedule($employee, $store, '2026-06-08');

        AttendanceLog::create([
            'user_id' => $employee->id,
            'schedule_id' => $attended->id,
            'schedule_store_id' => $attended->scheduleStores()->value('id'),
            'type' => 'time_in',
            'log_time' => '2026-06-08 07:05:00',
        ]);

        $response = $this->actingAs($manager)->postJson(route('schedules.recurring.preview'), [
            'month' => '2026-06',
            'user_ids' => [$employee->id],
            'rules' => [$this->rule([1], 'On-site', $store->id)],
        ]);

        $response->assertOk()
            ->assertJsonPath('counts.approval', 1)
            ->assertJsonPath('counts.protected', 1)
            ->assertJsonPath('counts.create', 3);

        $entries = collect($response->json('entries'))->keyBy('date');
        $this->assertSame('approval', $entries['2026-06-01']['action']);
        $this->assertSame('protected', $entries['2026-06-08']['action']);
        $this->assertStringContainsString('Attendance', $entries['2026-06-08']['protected_reason']);
        $this->assertSame($replaceable->id, $entries['2026-06-01']['existing_schedule_ids'][0]);
    }

    public function test_save_keeps_existing_schedule_until_manager_approves_replacement(): void
    {
        [$manager, $employees] = $this->managerWithEmployees(1);
        $employee = $employees->first();
        $store = $this->store();
        $replaceable = $this->schedule($employee, $store, '2026-06-01', 'WFH');
        $attended = $this->schedule($employee, $store, '2026-06-08', 'WFH');

        AttendanceLog::create([
            'user_id' => $employee->id,
            'schedule_id' => $attended->id,
            'schedule_store_id' => $attended->scheduleStores()->value('id'),
            'type' => 'time_in',
            'log_time' => '2026-06-08 07:05:00',
        ]);

        $response = $this->actingAs($manager)->postJson(route('schedules.recurring.store'), [
            'month' => '2026-06',
            'user_ids' => [$employee->id],
            'rules' => [$this->rule([1], 'On-site', $store->id)],
            'excluded_keys' => [$employee->id.'|2026-06-15'],
        ]);

        $response->assertOk()
            ->assertJsonPath('counts.pending_approval', 1)
            ->assertJsonPath('counts.protected', 1)
            ->assertJsonPath('counts.excluded', 1)
            ->assertJsonPath('counts.created', 2);

        $this->assertDatabaseHas('schedules', ['id' => $replaceable->id, 'status' => 'WFH']);
        $this->assertDatabaseMissing('schedules', ['user_id' => $employee->id, 'status' => 'On-site', 'start_time' => '2026-06-01 07:00:00']);
        $this->assertDatabaseHas('schedules', ['id' => $attended->id, 'status' => 'WFH']);
        $this->assertDatabaseMissing('schedules', ['user_id' => $employee->id, 'start_time' => '2026-06-15 07:00:00']);

        $changeRequest = ScheduleChangeRequest::where('request_type', 'recurring_plan_replacement')->sole();
        $this->assertSame('pending', $changeRequest->status);
        $this->assertContains($manager->id, $changeRequest->assigned_approver_ids);

        $employee->givePermissionTo('schedules.approve');
        $changeRequest->update(['assigned_approver_ids' => [$manager->id, $employee->id]]);

        $this->actingAs($employee)
            ->post(route('schedule-change-requests.approve', $changeRequest))
            ->assertForbidden();

        $this->actingAs($manager)
            ->post(route('schedule-change-requests.approve', $changeRequest), ['remarks' => 'Approved planned replacement.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('schedules', ['id' => $replaceable->id]);
        $this->assertDatabaseHas('schedules', ['user_id' => $employee->id, 'status' => 'On-site', 'start_time' => '2026-06-01 07:00:00']);
        $this->assertSame('approved', $changeRequest->fresh()->status);
        $this->assertSame($manager->id, $changeRequest->fresh()->approved_by);
    }

    public function test_manager_cannot_plan_for_employee_outside_their_org_tree(): void
    {
        [$manager] = $this->managerWithEmployees(0);
        $unrelatedEmployee = User::factory()->create();

        $this->actingAs($manager)->postJson(route('schedules.recurring.preview'), [
            'month' => '2026-06',
            'user_ids' => [$unrelatedEmployee->id],
            'rules' => [$this->rule([6, 7], 'Restday')],
        ])->assertForbidden();
    }

    private function managerWithEmployees(int $count): array
    {
        $manager = User::factory()->create(['is_manager' => true]);
        $manager->givePermissionTo(['schedules.create', 'schedules.edit', 'schedules.approve']);
        $employees = User::factory()->count($count)->create();
        $employees->each(fn (User $employee) => $employee->managers()->attach($manager->id));

        return [$manager, $employees];
    }

    private function store(): Store
    {
        return Store::create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'sector' => 1,
            'area' => 'Metro',
            'brand' => 'Brand',
            'cluster' => 'Cluster',
            'is_active' => true,
        ]);
    }

    private function rule(array $weekdays, string $status, ?int $storeId = null): array
    {
        return [
            'weekdays' => $weekdays,
            'status' => $status,
            'store_id' => $storeId,
            'start_time' => $status === 'Restday' ? null : '07:00',
            'end_time' => $status === 'Restday' ? null : '17:00',
            'grace_period_minutes' => 30,
            'remarks' => 'Monthly plan',
        ];
    }

    private function schedule(User $user, Store $store, string $date, string $status = 'On-site'): Schedule
    {
        $schedule = Schedule::create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'status' => $status,
            'start_time' => $date.' 07:00:00',
            'end_time' => $date.' 17:00:00',
        ]);

        ScheduleStore::create([
            'schedule_id' => $schedule->id,
            'store_id' => $store->id,
            'start_time' => $date.' 07:00:00',
            'end_time' => $date.' 17:00:00',
            'grace_period_minutes' => 30,
        ]);

        return $schedule;
    }
}
