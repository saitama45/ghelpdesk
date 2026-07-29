<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task dates are re-chained from the project's Day 1 Date on every edit, so a
     * hand-picked Start Date needs somewhere to live or the next re-chain wipes
     * it. A row with an anchor starts there; everything after it chains on.
     */
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('project_tasks', 'start_anchor_date')) {
                $table->date('start_anchor_date')->nullable()->after('end_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('project_tasks', 'start_anchor_date')) {
                $table->dropColumn('start_anchor_date');
            }
        });
    }
};
