<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\QatCycle;
use App\Models\QatParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class QatParticipantVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_setup_tester_can_list_and_open_a_cycle_owned_by_another_department(): void
    {
        $testerDepartment = Department::create([
            'name' => 'Tester Department',
            'code' => 'TESTER',
            'is_active' => true,
        ]);
        $ownerDepartment = Department::create([
            'name' => 'Owner Department',
            'code' => 'OWNER',
            'is_active' => true,
        ]);

        $tester = User::factory()->create(['department_id' => $testerDepartment->id]);
        $tester->givePermissionTo(Permission::findOrCreate('qat.view', 'web'));
        $owner = User::factory()->create(['department_id' => $ownerDepartment->id]);

        $assignedCycle = QatCycle::create([
            'code' => 'QAT-2026-0001',
            'title' => 'Assigned Cycle',
            'department_id' => $ownerDepartment->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
        $hiddenCycle = QatCycle::create([
            'code' => 'QAT-2026-0002',
            'title' => 'Unassigned Cycle',
            'department_id' => $ownerDepartment->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        QatParticipant::create([
            'qat_cycle_id' => $assignedCycle->id,
            'kind' => QatParticipant::KIND_USER,
            'label' => $tester->name,
            'user_id' => $tester->id,
            'role' => QatParticipant::ROLE_TESTER,
            'is_active' => true,
        ]);

        $visibleIds = QatCycle::query()->visibleTo($tester)->pluck('id');
        $this->assertTrue($visibleIds->contains($assignedCycle->id));
        $this->assertFalse($visibleIds->contains($hiddenCycle->id));
        $this->assertTrue($assignedCycle->isVisibleTo($tester));
        $this->assertFalse($hiddenCycle->isVisibleTo($tester));

        $indexResponse = $this->actingAs($tester)->get(route('qat.index'));
        $indexResponse->assertOk();
        $listedIds = collect($indexResponse->viewData('page')['props']['cycles']['data'])->pluck('id');
        $this->assertEqualsCanonicalizing([$assignedCycle->id], $listedIds->all());

        $this->actingAs($tester)
            ->get(route('qat.show', $assignedCycle))
            ->assertOk();

        $this->actingAs($tester)
            ->get(route('qat.show', $hiddenCycle))
            ->assertForbidden();
    }

    public function test_inactive_setup_assignment_does_not_grant_visibility(): void
    {
        $testerDepartment = Department::create(['name' => 'Tester Department', 'code' => 'TESTER', 'is_active' => true]);
        $ownerDepartment = Department::create(['name' => 'Owner Department', 'code' => 'OWNER', 'is_active' => true]);
        $tester = User::factory()->create(['department_id' => $testerDepartment->id]);
        $owner = User::factory()->create(['department_id' => $ownerDepartment->id]);
        $cycle = QatCycle::create([
            'code' => 'QAT-2026-0003',
            'title' => 'Inactive Assignment',
            'department_id' => $ownerDepartment->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        QatParticipant::create([
            'qat_cycle_id' => $cycle->id,
            'kind' => QatParticipant::KIND_USER,
            'label' => $tester->name,
            'user_id' => $tester->id,
            'role' => QatParticipant::ROLE_TESTER,
            'is_active' => false,
        ]);

        $this->assertFalse(QatCycle::query()->visibleTo($tester)->whereKey($cycle)->exists());
        $this->assertFalse($cycle->isVisibleTo($tester));
    }
}
