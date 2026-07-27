<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of project task progress, so the Projects → Progress Chart
 * tab can plot a real week-over-week comparison.
 *
 * Nothing in the app recorded progress over time before this: project_tasks holds
 * only the CURRENT progress int, and task card activity never logged checkbox
 * toggles. Every write goes through Eloquent (ProjectTaskObserver), so a row is
 * appended whenever a task's % actually changes.
 *
 * No foreign keys: SQL Server rejects multiple cascade paths, and the chart
 * service always joins back through the live project_tasks rows anyway, so a
 * deleted task simply drops out of the average rather than needing cascade cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_progress_logs')) {
            Schema::create('project_progress_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('project_task_id');
                $table->unsignedTinyInteger('progress')->default(0);
                $table->dateTime('recorded_at');
                $table->timestamps();
            });
        }

        $this->createIndex('project_progress_logs', ['project_id', 'recorded_at'], 'project_progress_logs_project_recorded_index');
        $this->createIndex('project_progress_logs', ['project_task_id', 'recorded_at'], 'project_progress_logs_task_recorded_index');

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('project_progress_logs');
    }

    /**
     * Seed a baseline so the chart is not blank on day one.
     *
     * Two points per existing task, which is everything the schema can honestly
     * support: 0% when the task was created, and its current % at the moment it
     * was last touched. updated_at also moves on non-progress edits, so pre-launch
     * weeks are an approximation — the UI says so. Everything after this migration
     * is exact.
     */
    private function backfill(): void
    {
        if (DB::table('project_progress_logs')->exists()) {
            return;
        }

        $now = now();

        DB::table('project_tasks')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->select(['id', 'project_id', 'progress', 'created_at', 'updated_at'])
            ->chunk(500, function ($tasks) use ($now) {
                $rows = [];

                foreach ($tasks as $task) {
                    if (! $task->project_id) {
                        continue;
                    }

                    $created = $task->created_at ?: $task->updated_at ?: $now;
                    $updated = $task->updated_at ?: $created;
                    $progress = (int) ($task->progress ?? 0);

                    $rows[] = [
                        'project_id'      => $task->project_id,
                        'project_task_id' => $task->id,
                        'progress'        => 0,
                        'recorded_at'     => $created,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ];

                    // Only a second point when the task actually moved off zero.
                    if ($progress > 0) {
                        $rows[] = [
                            'project_id'      => $task->project_id,
                            'project_task_id' => $task->id,
                            'progress'        => min(100, $progress),
                            'recorded_at'     => $updated,
                            'created_at'      => $now,
                            'updated_at'      => $now,
                        ];
                    }
                }

                if ($rows) {
                    foreach (array_chunk($rows, 200) as $batch) {
                        DB::table('project_progress_logs')->insert($batch);
                    }
                }
            });
    }

    private function createIndex(string $table, array $columns, string $index): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            $columnSql = implode(', ', $columns);
            DB::statement("
                IF NOT EXISTS (SELECT name FROM sys.indexes WHERE name = '{$index}')
                CREATE INDEX {$index} ON {$table} ({$columnSql})
            ");

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $index) {
            $table->index($columns, $index);
        });
    }
};
