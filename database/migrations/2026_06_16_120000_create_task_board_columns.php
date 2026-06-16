<?php

use App\Models\TaskBoardColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_board_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('#64748b');
            $table->string('role')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['task_board_id', 'sort_order']);
        });

        // Plain indexed column (no DB-level FK): SQL Server rejects a SET NULL/CASCADE
        // foreign key here because task_cards already cascades from task_boards, which
        // would create multiple cascade paths. Integrity is enforced at the app layer,
        // and the legacy `status` string remains a valid fallback.
        Schema::table('task_cards', function (Blueprint $table) {
            $table->unsignedBigInteger('task_board_column_id')->nullable()->after('task_board_id');

            $table->index(['task_board_id', 'task_board_column_id', 'archived_at', 'sort_order'], 'task_cards_board_column_idx');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('task_cards', function (Blueprint $table) {
            $table->dropIndex('task_cards_board_column_idx');
            $table->dropColumn('task_board_column_id');
        });

        Schema::dropIfExists('task_board_columns');
    }

    /**
     * Seed the four default columns for every existing board and point each card
     * at the column whose name matches the card's legacy status string.
     */
    private function backfill(): void
    {
        DB::transaction(function () {
            $defaults = TaskBoardColumn::DEFAULTS;

            DB::table('task_boards')->orderBy('id')->pluck('id')->each(function ($boardId) use ($defaults) {
                $nameToColumnId = [];

                foreach ($defaults as $index => $default) {
                    $columnId = DB::table('task_board_columns')->insertGetId([
                        'task_board_id' => $boardId,
                        'name' => $default['name'],
                        'color' => $default['color'],
                        'role' => $default['role'],
                        'sort_order' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $nameToColumnId[$default['name']] = $columnId;
                }

                $backlogColumnId = $nameToColumnId[$defaults[0]['name']] ?? null;

                foreach ($nameToColumnId as $statusName => $columnId) {
                    DB::table('task_cards')
                        ->where('task_board_id', $boardId)
                        ->where('status', $statusName)
                        ->update(['task_board_column_id' => $columnId]);
                }

                // Any card whose legacy status does not match a default column falls back to backlog.
                if ($backlogColumnId) {
                    DB::table('task_cards')
                        ->where('task_board_id', $boardId)
                        ->whereNull('task_board_column_id')
                        ->update(['task_board_column_id' => $backlogColumnId]);
                }
            });
        });
    }
};
