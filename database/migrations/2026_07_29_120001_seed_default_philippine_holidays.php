<?php

use App\Support\PhilippineHolidays;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seeds the default Philippine holiday calendar.
     *
     * This lives in a migration rather than a seeder on purpose: the Azure
     * deploy runs `artisan migrate --force` but never `db:seed`, so a seeder
     * would leave production with an empty holiday table. PhilippineHolidays::seed()
     * is idempotent, so re-running is safe and never duplicates or overwrites.
     */
    public function up(): void
    {
        if (!Schema::hasTable('holidays')) {
            return;
        }

        $currentYear = (int) date('Y');

        // The current year plus the next two, so movable holidays (Holy Week,
        // National Heroes Day) are already in place for near-term planning.
        foreach (range($currentYear, $currentYear + 2) as $year) {
            PhilippineHolidays::seed($year);
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive: the holidays may since have been edited
        // or joined by custom declarations, so rolling back leaves them alone.
    }
};
