<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An early build of 2026_09_17_000001 made department codes unique only per
 * entity. Codes must be unique across ALL entities, so databases that ran that
 * build swap its index for the global one. Where 000001 already created the
 * global index this is a no-op. Index-only: no rows change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasIndex('departments_company_id_code_unique')) {
            $this->dropIndex('departments_company_id_code_unique');
        }

        if ($this->hasIndex('departments_code_unique')) {
            return;
        }

        $duplicates = DB::table('departments')
            ->whereNotNull('code')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('code');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot make department codes unique — duplicate codes exist (' . $duplicates->implode(', ') . '). Rename them on /departments, then re-run migrate.');
        }

        if (Schema::getConnection()->getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX departments_code_unique ON departments (code) WHERE code IS NOT NULL');
        } else {
            DB::statement('CREATE UNIQUE INDEX departments_code_unique ON departments (code)');
        }
    }

    public function down(): void
    {
        // The global code index belongs to 000001; nothing to undo here.
    }

    private function dropIndex(string $name): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlsrv') {
            DB::statement("DROP INDEX {$name} ON departments");
        } else {
            DB::statement("DROP INDEX {$name}");
        }
    }

    private function hasIndex(string $name): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlsrv') {
            return (int) $connection->scalar('SELECT COUNT(*) FROM sys.indexes WHERE name = ?', [$name]) > 0;
        }

        return (int) $connection->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]) > 0;
    }
};
