<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How this project counts a lead time into a span:
     *  - 'working'  → skip weekends and non-working PH holidays (what the tracker
     *                 has always done, so it stays the default and no existing
     *                 project shifts a single date);
     *  - 'calendar' → count every day straight through, weekends and holidays
     *                 included.
     *
     * The mode applies to every milestone, activity and sub-task in the project.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'schedule_day_mode')) {
                $table->string('schedule_day_mode', 20)->default('working')->after('day1_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'schedule_day_mode')) {
                $table->dropColumn('schedule_day_mode');
            }
        });
    }
};
