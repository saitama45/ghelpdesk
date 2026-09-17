<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Department NAME is unique per entity (ENTECH may have its own "Business
 * Development" alongside TGI's); CODE stays unique across ALL entities.
 * Index-only: no rows change.
 *
 * - name: the old global unique index guarantees existing data already fits
 *   (company_id, name).
 * - code: never had an index. It is nullable, and SQL Server treats NULLs as
 *   equal for uniqueness, so it must be a FILTERED index or a second department
 *   without a code would be rejected. If live data already repeats a code, this
 *   aborts with the offending codes (startup.sh keeps booting; the migration
 *   stays pending until the data is fixed and it re-runs).
 */
return new class extends Migration
{
    private const NAME_INDEX = 'departments_company_id_name_unique';
    private const CODE_INDEX = 'departments_code_unique';

    public function up(): void
    {
        if ($this->hasIndex('departments_name_unique')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropUnique('departments_name_unique');
            });
        }

        if (!$this->hasIndex(self::NAME_INDEX)) {
            Schema::table('departments', function (Blueprint $table) {
                $table->unique(['company_id', 'name'], self::NAME_INDEX);
            });
        }

        if ($this->hasIndex(self::CODE_INDEX)) {
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
            DB::statement('CREATE UNIQUE INDEX ' . self::CODE_INDEX . ' ON departments (code) WHERE code IS NOT NULL');
        } else {
            Schema::table('departments', function (Blueprint $table) {
                $table->unique('code', self::CODE_INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex(self::CODE_INDEX)) {
            if (Schema::getConnection()->getDriverName() === 'sqlsrv') {
                DB::statement('DROP INDEX ' . self::CODE_INDEX . ' ON departments');
            } else {
                Schema::table('departments', function (Blueprint $table) {
                    $table->dropUnique(self::CODE_INDEX);
                });
            }
        }

        if ($this->hasIndex(self::NAME_INDEX)) {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropUnique(self::NAME_INDEX);
            });
        }

        if (!$this->hasIndex('departments_name_unique')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->unique('name', 'departments_name_unique');
            });
        }
    }

    /** Keeps up() re-runnable after a partial or aborted pass. */
    private function hasIndex(string $name): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlsrv') {
            return (int) $connection->scalar('SELECT COUNT(*) FROM sys.indexes WHERE name = ?', [$name]) > 0;
        }

        return (int) $connection->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]) > 0;
    }
};
