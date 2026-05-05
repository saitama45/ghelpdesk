<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('task_boards', 'project_id')) {
            Schema::table('task_boards', function (Blueprint $table) {
                $table->foreignId('project_id')
                    ->nullable()
                    ->after('id');
            });
        }

        if (!Schema::hasColumn('task_cards', 'project_task_id')) {
            Schema::table('task_cards', function (Blueprint $table) {
                $table->foreignId('project_task_id')
                    ->nullable()
                    ->after('task_board_id');
            });
        }

        $this->createForeignKey('task_boards', 'project_id', 'projects', 'task_boards_project_id_foreign', 'CASCADE');
        $this->createForeignKey('task_cards', 'project_task_id', 'project_tasks', 'task_cards_project_task_id_foreign', 'NO ACTION');
        $this->createNullableUniqueIndex('task_boards', 'project_id', 'task_boards_project_id_unique');
        $this->createNullableUniqueIndex('task_cards', 'project_task_id', 'task_cards_project_task_id_unique');
    }

    public function down(): void
    {
        $this->dropNullableUniqueIndex('task_cards', 'project_task_id', 'task_cards_project_task_id_unique');
        $this->dropNullableUniqueIndex('task_boards', 'project_id', 'task_boards_project_id_unique');

        if (Schema::hasColumn('task_cards', 'project_task_id')) {
            Schema::table('task_cards', function (Blueprint $table) {
                $table->dropForeign('task_cards_project_task_id_foreign');
                $table->dropColumn('project_task_id');
            });
        }

        if (Schema::hasColumn('task_boards', 'project_id')) {
            Schema::table('task_boards', function (Blueprint $table) {
                $table->dropForeign('task_boards_project_id_foreign');
                $table->dropColumn('project_id');
            });
        }
    }

    private function createForeignKey(string $table, string $column, string $references, string $constraint, string $onDelete): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("
                IF NOT EXISTS (SELECT name FROM sys.foreign_keys WHERE name = '{$constraint}')
                ALTER TABLE {$table} ADD CONSTRAINT {$constraint}
                FOREIGN KEY ({$column}) REFERENCES {$references} (id) ON DELETE {$onDelete}
            ");

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column, $references, $constraint, $onDelete) {
            $table->foreign($column, $constraint)
                ->references('id')
                ->on($references)
                ->onDelete(strtolower($onDelete));
        });
    }

    private function createNullableUniqueIndex(string $table, string $column, string $index): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("
                IF NOT EXISTS (SELECT name FROM sys.indexes WHERE name = '{$index}')
                CREATE UNIQUE INDEX {$index} ON {$table} ({$column}) WHERE {$column} IS NOT NULL
            ");

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column, $index) {
            $table->unique($column, $index);
        });
    }

    private function dropNullableUniqueIndex(string $table, string $column, string $index): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("
                IF EXISTS (SELECT name FROM sys.indexes WHERE name = '{$index}')
                DROP INDEX {$index} ON {$table}
            ");

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column, $index) {
            $table->dropUnique($index ?: [$column]);
        });
    }
};
