<?php

namespace Tests\Feature;

use App\Models\TaskBoard;
use App\Models\User;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_generates_one_monthly_board_per_active_department_sub_unit(): void
    {
        $creator = User::factory()->create();
        User::factory()->create(['department' => 'TAS', 'sub_unit' => 'DS']);
        User::factory()->create(['department' => 'TAS', 'sub_unit' => 'BS']);
        User::factory()->create(['department' => 'TAS', 'sub_unit' => 'SD']);
        User::factory()->create(['department' => 'OPS', 'sub_unit' => 'DS']);

        $this->actingAs($creator)
            ->post(route('task-lists.monthly-generate'), [
                'department' => 'TAS',
                'month' => 5,
                'year' => 2026,
            ])
            ->assertRedirect(route('task-lists.index'));

        $this->assertSame(3, TaskBoard::where('board_source', 'monthly')->count());

        foreach (['DS', 'BS', 'SD'] as $subUnit) {
            $this->assertDatabaseHas('task_boards', [
                'board_source' => 'monthly',
                'department' => 'TAS',
                'sub_unit' => $subUnit,
                'board_month' => 5,
                'board_year' => 2026,
                'title' => "{$subUnit} May 2026",
            ]);
        }
    }

    public function test_generated_boards_include_matching_active_users_and_creator(): void
    {
        $creator = User::factory()->create();
        $dsUser = User::factory()->create(['department' => 'TAS', 'sub_unit' => 'DS']);
        $inactiveDsUser = User::factory()->create(['department' => 'TAS', 'sub_unit' => 'DS', 'is_active' => false]);
        $bsUser = User::factory()->create(['department' => 'TAS', 'sub_unit' => 'BS']);
        $otherDepartmentUser = User::factory()->create(['department' => 'OPS', 'sub_unit' => 'DS']);

        $this->actingAs($creator)
            ->post(route('task-lists.monthly-generate'), [
                'department' => 'TAS',
                'month' => 5,
                'year' => 2026,
            ]);

        $board = TaskBoard::where('title', 'DS May 2026')->firstOrFail();

        $this->assertDatabaseHas('task_board_members', [
            'task_board_id' => $board->id,
            'user_id' => $creator->id,
            'role' => 'admin',
        ]);
        $this->assertDatabaseHas('task_board_members', [
            'task_board_id' => $board->id,
            'user_id' => $dsUser->id,
            'role' => 'member',
        ]);
        $this->assertDatabaseMissing('task_board_members', [
            'task_board_id' => $board->id,
            'user_id' => $inactiveDsUser->id,
        ]);
        $this->assertDatabaseMissing('task_board_members', [
            'task_board_id' => $board->id,
            'user_id' => $bsUser->id,
        ]);
        $this->assertDatabaseMissing('task_board_members', [
            'task_board_id' => $board->id,
            'user_id' => $otherDepartmentUser->id,
        ]);
    }

    public function test_generation_skips_existing_monthly_boards_including_closed_boards(): void
    {
        $creator = User::factory()->create();
        User::factory()->create(['department' => 'TAS', 'sub_unit' => 'DS']);
        User::factory()->create(['department' => 'TAS', 'sub_unit' => 'BS']);

        $payload = [
            'department' => 'TAS',
            'month' => 5,
            'year' => 2026,
        ];

        $this->actingAs($creator)->post(route('task-lists.monthly-generate'), $payload);

        $closedBoard = TaskBoard::where('title', 'DS May 2026')->firstOrFail();
        $closedBoard->update(['closed_at' => now()]);

        $this->actingAs($creator)
            ->post(route('task-lists.monthly-generate'), $payload)
            ->assertRedirect(route('task-lists.index'));

        $this->assertSame(2, TaskBoard::where('board_source', 'monthly')->count());
        $this->assertNotNull($closedBoard->fresh()->closed_at);
    }

    public function test_blank_sub_units_and_inactive_users_do_not_generate_boards(): void
    {
        $creator = User::factory()->create();
        User::factory()->create(['department' => 'TAS', 'sub_unit' => '']);
        User::factory()->create(['department' => 'TAS', 'sub_unit' => 'DS', 'is_active' => false]);

        $this->actingAs($creator)
            ->post(route('task-lists.monthly-generate'), [
                'department' => 'TAS',
                'month' => 5,
                'year' => 2026,
            ])
            ->assertSessionHasErrors('department');

        $this->assertSame(0, TaskBoard::where('board_source', 'monthly')->count());
    }
}
