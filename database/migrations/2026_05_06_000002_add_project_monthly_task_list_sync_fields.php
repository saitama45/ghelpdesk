<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'board_month')) {
                $table->unsignedTinyInteger('board_month')->nullable()->after('target_go_live');
            }

            if (!Schema::hasColumn('projects', 'board_year')) {
                $table->unsignedSmallInteger('board_year')->nullable()->after('board_month');
            }
        });

        Schema::table('project_team_members', function (Blueprint $table) {
            if (!Schema::hasColumn('project_team_members', 'department')) {
                $table->string('department')->nullable()->after('external_name');
            }

            if (!Schema::hasColumn('project_team_members', 'sub_unit')) {
                $table->string('sub_unit')->nullable()->after('department');
            }
        });

        Schema::table('task_cards', function (Blueprint $table) {
            if (!Schema::hasColumn('task_cards', 'project_id')) {
                $table->foreignId('project_id')->nullable()->after('task_board_id');
            }
        });

        $this->createForeignKey('task_cards', 'project_id', 'projects', 'task_cards_project_id_foreign', 'NO ACTION');
        $this->createProjectCardIndex();

        Schema::table('task_checklist_items', function (Blueprint $table) {
            if (!Schema::hasColumn('task_checklist_items', 'parent_item_id')) {
                $table->foreignId('parent_item_id')->nullable()->after('task_checklist_id');
            }

            if (!Schema::hasColumn('task_checklist_items', 'project_task_id')) {
                $table->foreignId('project_task_id')->nullable()->after('parent_item_id');
            }
        });

        $this->createForeignKey('task_checklist_items', 'parent_item_id', 'task_checklist_items', 'task_checklist_items_parent_item_id_foreign', 'NO ACTION');
        $this->createForeignKey('task_checklist_items', 'project_task_id', 'project_tasks', 'task_checklist_items_project_task_id_foreign', 'NO ACTION');
        $this->createIndex('task_checklist_items', ['parent_item_id', 'sort_order'], 'task_checklist_items_parent_sort_index');
        $this->createIndex('task_checklist_items', ['project_task_id'], 'task_checklist_items_project_task_id_index');
    }

    public function down(): void
    {
        $this->dropIndex('task_checklist_items', 'task_checklist_items_project_task_id_index');
        $this->dropIndex('task_checklist_items', 'task_checklist_items_parent_sort_index');
        $this->dropForeignKey('task_checklist_items', 'task_checklist_items_project_task_id_foreign');
        $this->dropForeignKey('task_checklist_items', 'task_checklist_items_parent_item_id_foreign');

        Schema::table('task_checklist_items', function (Blueprint $table) {
            if (Schema::hasColumn('task_checklist_items', 'project_task_id')) {
                $table->dropColumn('project_task_id');
            }

            if (Schema::hasColumn('task_checklist_items', 'parent_item_id')) {
                $table->dropColumn('parent_item_id');
            }
        });

        $this->dropProjectCardIndex();
        $this->dropForeignKey('task_cards', 'task_cards_project_id_foreign');

        Schema::table('task_cards', function (Blueprint $table) {
            if (Schema::hasColumn('task_cards', 'project_id')) {
                $table->dropColumn('project_id');
            }
        });

        Schema::table('project_team_members', function (Blueprint $table) {
            if (Schema::hasColumn('project_team_members', 'sub_unit')) {
                $table->dropColumn('sub_unit');
            }

            if (Schema::hasColumn('project_team_members', 'department')) {
                $table->dropColumn('department');
            }
        });

        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'board_year')) {
                $table->dropColumn('board_year');
            }

            if (Schema::hasColumn('projects', 'board_month')) {
                $table->dropColumn('board_month');
            }
        });
    }

    private function createProjectCardIndex(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("
                IF NOT EXISTS (SELECT name FROM sys.indexes WHERE name = 'task_cards_board_project_unique')
                CREATE UNIQUE INDEX task_cards_board_project_unique ON task_cards (task_board_id, project_id) WHERE project_id IS NOT NULL
            ");

            return;
        }

        Schema::table('task_cards', function (Blueprint $table) {
            $table->unique(['task_board_id', 'project_id'], 'task_cards_board_project_unique');
        });
    }

    private function dropProjectCardIndex(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("
                IF EXISTS (SELECT name FROM sys.indexes WHERE name = 'task_cards_board_project_unique')
                DROP INDEX task_cards_board_project_unique ON task_cards
            ");

            return;
        }

        Schema::table('task_cards', function (Blueprint $table) {
            $table->dropUnique('task_cards_board_project_unique');
        });
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

    private function dropForeignKey(string $table, string $constraint): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("
                IF EXISTS (SELECT name FROM sys.foreign_keys WHERE name = '{$constraint}')
                ALTER TABLE {$table} DROP CONSTRAINT {$constraint}
            ");

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($constraint) {
            $table->dropForeign($constraint);
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

    private function dropIndex(string $table, string $index): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("
                IF EXISTS (SELECT name FROM sys.indexes WHERE name = '{$index}')
                DROP INDEX {$index} ON {$table}
            ");

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($index) {
            $table->dropIndex($index);
        });
    }
};
