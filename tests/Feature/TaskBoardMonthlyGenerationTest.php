<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentNode;
use App\Models\Role;
use App\Models\TaskBoard;
use App\Models\User;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaskBoardMonthlyGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            ValidateCsrfToken::class,
            Authorize::class,
        ]);
    }

    public function test_department_selection_generates_boards_for_root_nodes_and_direct_department_users(): void
    {
        $creator = User::factory()->create();
        [$department, $support, $backOffice, $ds, $field] = $this->hierarchy();

        $directDepartmentUser = $this->userAt('Direct TAS', $department);
        $supportUser = $this->userAt('Support User', $department, $support);
        $dsUser = $this->userAt('DS User', $department, $ds);
        $backOfficeUser = $this->userAt('Back Office User', $department, $backOffice);
        $this->userAt('Inactive Field User', $department, $field, false);

        $this->actingAs($creator)
            ->post(route('task-boards.monthly-generate'), [
                'department_id' => $department->id,
                'month' => 5,
                'year' => 2026,
            ])
            ->assertRedirect(route('task-boards.index'));

        $this->assertSame(3, TaskBoard::where('board_source', 'monthly')->count());

        foreach (['TAS', 'Support', 'Back Office'] as $subUnit) {
            $this->assertDatabaseHas('task_boards', [
                'board_source' => 'monthly',
                'department' => 'TAS',
                'sub_unit' => $subUnit,
                'board_month' => 5,
                'board_year' => 2026,
                'title' => "{$subUnit} May 2026",
            ]);
        }

        $supportBoard = TaskBoard::where('sub_unit', 'Support')->firstOrFail();
        $tasBoard = TaskBoard::where('sub_unit', 'TAS')->firstOrFail();
        $backOfficeBoard = TaskBoard::where('sub_unit', 'Back Office')->firstOrFail();

        $this->assertBoardHasMember($tasBoard, $directDepartmentUser);
        $this->assertBoardHasMember($supportBoard, $supportUser);
        $this->assertBoardHasMember($supportBoard, $dsUser);
        $this->assertBoardHasMember($backOfficeBoard, $backOfficeUser);
        $this->assertDatabaseMissing('task_boards', ['sub_unit' => 'DS']);
        $this->assertDatabaseMissing('task_boards', ['sub_unit' => 'Field']);
    }

    public function test_node_selection_generates_direct_selected_node_board_and_immediate_child_boards(): void
    {
        $creator = User::factory()->create();
        [$department, $support, $backOffice, $ds, $field] = $this->hierarchy();

        $supportUser = $this->userAt('Support User', $department, $support);
        $dsUser = $this->userAt('DS User', $department, $ds);
        $fieldUser = $this->userAt('Field User', $department, $field);

        $this->actingAs($creator)
            ->post(route('task-boards.monthly-generate'), [
                'department_node_id' => $support->id,
                'month' => 5,
                'year' => 2026,
            ])
            ->assertRedirect(route('task-boards.index'));

        $this->assertSame(2, TaskBoard::where('board_source', 'monthly')->count());
        $this->assertDatabaseHas('task_boards', ['sub_unit' => 'Support', 'title' => 'Support May 2026']);
        $this->assertDatabaseHas('task_boards', ['sub_unit' => 'DS', 'title' => 'DS May 2026']);
        $this->assertDatabaseMissing('task_boards', ['sub_unit' => 'Field']);

        $supportBoard = TaskBoard::where('sub_unit', 'Support')->firstOrFail();
        $dsBoard = TaskBoard::where('sub_unit', 'DS')->firstOrFail();

        $this->assertBoardHasMember($supportBoard, $supportUser);
        $this->assertBoardHasMember($dsBoard, $dsUser);
        $this->assertBoardHasMember($dsBoard, $fieldUser);
        $this->assertBoardDoesNotHaveMember($supportBoard, $dsUser);
    }

    public function test_leaf_selection_generates_one_board_when_active_users_exist(): void
    {
        $creator = User::factory()->create();
        [$department, $support, $backOffice, $ds, $field] = $this->hierarchy();

        $fieldUser = $this->userAt('Field User', $department, $field);

        $this->actingAs($creator)
            ->post(route('task-boards.monthly-generate'), [
                'department_node_id' => $field->id,
                'month' => 5,
                'year' => 2026,
            ])
            ->assertRedirect(route('task-boards.index'));

        $this->assertSame(1, TaskBoard::where('board_source', 'monthly')->count());
        $board = TaskBoard::where('sub_unit', 'Field')->firstOrFail();

        $this->assertSame('Field May 2026', $board->title);
        $this->assertBoardHasMember($board, $fieldUser);
        $this->assertBoardHasMember($board, $creator, 'admin');
    }

    public function test_index_node_filter_includes_selected_child_and_subchild_boards(): void
    {
        Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->assignRole('Admin');
        [$department, $support, $backOffice, $ds, $field] = $this->hierarchy();

        $supportUser = $this->userAt('Support User', $department, $support);
        $dsUser = $this->userAt('DS User', $department, $ds);
        $fieldUser = $this->userAt('Field User', $department, $field);
        $backOfficeUser = $this->userAt('Back Office User', $department, $backOffice);

        $supportBoard = $this->monthlyBoard('Support May 2026', $department->name, 'Support');
        $supportBoard->members()->attach($supportUser->id, ['role' => 'member']);

        $dsBoard = $this->monthlyBoard('DS May 2026', $department->name, 'DS');
        $dsBoard->members()->attach($dsUser->id, ['role' => 'member']);
        $dsBoard->members()->attach($fieldUser->id, ['role' => 'member']);

        $backOfficeBoard = $this->monthlyBoard('Back Office May 2026', $department->name, 'Back Office');
        $backOfficeBoard->members()->attach($backOfficeUser->id, ['role' => 'member']);

        $this->actingAs($viewer)
            ->get(route('task-boards.index', ['department_node_id' => $support->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('TaskBoards/Index')
                ->where('boards', fn ($boards) => collect($boards)->pluck('title')->sort()->values()->all() === [
                    'DS May 2026',
                    'Support May 2026',
                ])
            );
    }

    public function test_generation_skips_existing_monthly_boards_including_closed_boards(): void
    {
        $creator = User::factory()->create();
        [$department, $support] = $this->hierarchy();
        $this->userAt('Support User', $department, $support);

        $payload = [
            'department_node_id' => $support->id,
            'month' => 5,
            'year' => 2026,
        ];

        $this->actingAs($creator)->post(route('task-boards.monthly-generate'), $payload);

        $closedBoard = TaskBoard::where('title', 'Support May 2026')->firstOrFail();
        $closedBoard->update(['closed_at' => now()]);

        $this->actingAs($creator)
            ->post(route('task-boards.monthly-generate'), $payload)
            ->assertRedirect(route('task-boards.index'));

        $this->assertSame(1, TaskBoard::where('board_source', 'monthly')->count());
        $this->assertNotNull($closedBoard->fresh()->closed_at);
    }

    public function test_empty_targets_and_inactive_users_do_not_generate_boards(): void
    {
        $creator = User::factory()->create();
        [$department, $support, $backOffice, $ds, $field] = $this->hierarchy();
        $this->userAt('Inactive Field User', $department, $field, false);

        $this->actingAs($creator)
            ->post(route('task-boards.monthly-generate'), [
                'department_node_id' => $field->id,
                'month' => 5,
                'year' => 2026,
            ])
            ->assertSessionHasErrors('department');

        $this->assertSame(0, TaskBoard::where('board_source', 'monthly')->count());
    }

    private function hierarchy(): array
    {
        $department = Department::create([
            'name' => 'TAS',
            'code' => 'TAS',
            'is_active' => true,
        ]);

        $support = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Support',
            'code' => 'SUP',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $backOffice = DepartmentNode::create([
            'department_id' => $department->id,
            'name' => 'Back Office',
            'code' => 'BO',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $ds = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $support->id,
            'name' => 'DS',
            'code' => 'DS',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $field = DepartmentNode::create([
            'department_id' => $department->id,
            'parent_id' => $ds->id,
            'name' => 'Field',
            'code' => 'FIELD',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$department, $support, $backOffice, $ds, $field];
    }

    private function userAt(string $name, Department $department, ?DepartmentNode $node = null, bool $active = true): User
    {
        return User::factory()->create([
            'name' => $name,
            'department' => $department->name,
            'department_id' => $department->id,
            'department_node_id' => $node?->id,
            'org_path' => $node ? $this->nodePath($node) : null,
            'is_active' => $active,
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

    private function monthlyBoard(string $title, string $department, string $subUnit): TaskBoard
    {
        return TaskBoard::create([
            'board_source' => 'monthly',
            'department' => $department,
            'sub_unit' => $subUnit,
            'board_month' => 5,
            'board_year' => 2026,
            'monthly_key' => "{$department}|{$subUnit}|2026|5",
            'title' => $title,
            'background_type' => 'color',
            'background_value' => '#0f766e',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function assertBoardHasMember(TaskBoard $board, User $user, string $role = 'member'): void
    {
        $this->assertDatabaseHas('task_board_members', [
            'task_board_id' => $board->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }

    private function assertBoardDoesNotHaveMember(TaskBoard $board, User $user): void
    {
        $this->assertDatabaseMissing('task_board_members', [
            'task_board_id' => $board->id,
            'user_id' => $user->id,
        ]);
    }
}
