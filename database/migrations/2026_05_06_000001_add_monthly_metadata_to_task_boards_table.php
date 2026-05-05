<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_boards', function (Blueprint $table) {
            if (!Schema::hasColumn('task_boards', 'board_source')) {
                $table->string('board_source', 50)->nullable()->after('project_id');
            }

            if (!Schema::hasColumn('task_boards', 'department')) {
                $table->string('department')->nullable()->after('board_source');
            }

            if (!Schema::hasColumn('task_boards', 'sub_unit')) {
                $table->string('sub_unit')->nullable()->after('department');
            }

            if (!Schema::hasColumn('task_boards', 'board_month')) {
                $table->unsignedTinyInteger('board_month')->nullable()->after('sub_unit');
            }

            if (!Schema::hasColumn('task_boards', 'board_year')) {
                $table->unsignedSmallInteger('board_year')->nullable()->after('board_month');
            }

            if (!Schema::hasColumn('task_boards', 'monthly_key')) {
                $table->string('monthly_key', 120)->nullable()->after('board_year');
            }
        });

        $this->createNullableUniqueIndex('task_boards', 'monthly_key', 'task_boards_monthly_key_unique');
        $this->createIndex('task_boards', ['board_source', 'department', 'board_year', 'board_month'], 'task_boards_monthly_lookup_index');
    }

    public function down(): void
    {
        $this->dropIndex('task_boards', 'task_boards_monthly_lookup_index');
        $this->dropNullableUniqueIndex('task_boards', 'monthly_key', 'task_boards_monthly_key_unique');

        Schema::table('task_boards', function (Blueprint $table) {
            $columns = [
                'monthly_key',
                'board_year',
                'board_month',
                'sub_unit',
                'department',
                'board_source',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('task_boards', $column)) {
                    $table->dropColumn($column);
                }
            }
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
