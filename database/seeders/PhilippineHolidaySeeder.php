<?php

namespace Database\Seeders;

use App\Support\PhilippineHolidays;
use Illuminate\Database\Seeder;

/**
 * Kept for manual `db:seed` runs. Production is populated by the
 * 2026_07_29_120001_seed_default_philippine_holidays migration instead, because
 * the Azure deploy runs migrations but never seeders.
 */
class PhilippineHolidaySeeder extends Seeder
{
    public function run(): void
    {
        $currentYear = (int) date('Y');

        foreach (range($currentYear, $currentYear + 2) as $year) {
            PhilippineHolidays::seed($year);
        }
    }
}
