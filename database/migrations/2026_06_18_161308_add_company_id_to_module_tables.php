<?php

use App\Support\CompanyContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that already carried company_id before the entity-switcher work
     * and must therefore keep it when this migration is rolled back.
     */
    private array $preExisting = ['tickets', 'pos_requests', 'sap_requests', 'npc_statuses'];

    /**
     * Add a nullable, indexed company_id to every module table so the active
     * entity can be stamped onto new records across the app.
     *
     * No FK constraint is added: SQL Server rejects multiple cascade paths to
     * a single table, and the column is purely an informational tag here.
     */
    public function up(): void
    {
        foreach (CompanyContext::MODULE_TABLES as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (CompanyContext::MODULE_TABLES as $tableName) {
            if (in_array($tableName, $this->preExisting, true)) {
                continue;
            }
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['company_id']);
                $table->dropColumn('company_id');
            });
        }
    }
};
