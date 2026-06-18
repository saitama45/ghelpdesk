<?php

use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tag every existing untagged module record with the default entity (TGI),
     * so that filtering by the active entity has a sensible baseline. Records
     * that already carry a company_id (e.g. tickets) are left untouched.
     */
    public function up(): void
    {
        $tgi = Company::where('code', 'TGI')->first();
        $defaultId = $tgi?->id ?? 6;

        foreach (CompanyContext::MODULE_TABLES as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            DB::table($tableName)
                ->whereNull('company_id')
                ->update(['company_id' => $defaultId]);
        }
    }

    /**
     * No reliable down: we cannot tell which rows were NULL before the backfill.
     */
    public function down(): void
    {
        // Intentionally irreversible.
    }
};
