<?php

namespace App\Support;

use App\Models\Holiday;
use App\Services\HolidayCalendar;
use Carbon\Carbon;

/**
 * The canonical Philippine holiday defaults — the single source used by the
 * seeding migration, the database seeder, and the "Generate {year}" button, so
 * all three always produce the same calendar.
 */
class PhilippineHolidays
{
    /**
     * Fixed-date holidays. Stored once with is_recurring, so they apply to every
     * year automatically — 2030 included.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}> [name, type, MM-DD, description]
     */
    public static function recurring(): array
    {
        return [
            ['New Year\'s Day', Holiday::TYPE_REGULAR, '01-01', 'Regular holiday'],
            ['Araw ng Kagitingan', Holiday::TYPE_REGULAR, '04-09', 'Day of Valor'],
            ['Labor Day', Holiday::TYPE_REGULAR, '05-01', 'Regular holiday'],
            ['Independence Day', Holiday::TYPE_REGULAR, '06-12', 'Araw ng Kalayaan'],
            ['Bonifacio Day', Holiday::TYPE_REGULAR, '11-30', 'Birth of Andres Bonifacio'],
            ['Christmas Day', Holiday::TYPE_REGULAR, '12-25', 'Regular holiday'],
            ['Rizal Day', Holiday::TYPE_REGULAR, '12-30', 'Regular holiday'],

            ['Ninoy Aquino Day', Holiday::TYPE_SPECIAL_NON_WORKING, '08-21', 'Special non-working day'],
            ['All Saints\' Day', Holiday::TYPE_SPECIAL_NON_WORKING, '11-01', 'Special non-working day'],
            ['All Souls\' Day', Holiday::TYPE_SPECIAL_NON_WORKING, '11-02', 'Usually declared special non-working'],
            ['Feast of the Immaculate Conception', Holiday::TYPE_SPECIAL_NON_WORKING, '12-08', 'Special non-working day'],
            ['Christmas Eve', Holiday::TYPE_SPECIAL_NON_WORKING, '12-24', 'Usually declared special non-working'],
            ['Last Day of the Year', Holiday::TYPE_SPECIAL_NON_WORKING, '12-31', 'Special non-working day'],
        ];
    }

    /**
     * Holidays that move every year. Holy Week and National Heroes Day are
     * computed; Eid and Chinese New Year follow the lunar calendar and a
     * proclamation, so only the years we know are listed.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}> [name, type, Y-m-d, description]
     */
    public static function movableFor(int $year): array
    {
        $easter = self::easterSunday($year);

        $rows = [
            ['Maundy Thursday', Holiday::TYPE_REGULAR, $easter->copy()->subDays(3)->toDateString(), 'Holy Week'],
            ['Good Friday', Holiday::TYPE_REGULAR, $easter->copy()->subDays(2)->toDateString(), 'Holy Week'],
            ['Black Saturday', Holiday::TYPE_SPECIAL_NON_WORKING, $easter->copy()->subDay()->toDateString(), 'Holy Week'],
            ['National Heroes Day', Holiday::TYPE_REGULAR, self::lastMondayOfAugust($year)->toDateString(), 'Last Monday of August'],
        ];

        foreach (self::proclaimed()[$year] ?? [] as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Lunar-calendar holidays as proclaimed. Extend this as Malacañang releases
     * future years — anything missing can still be added by hand on /holidays.
     */
    private static function proclaimed(): array
    {
        return [
            2026 => [
                ['Chinese New Year', Holiday::TYPE_SPECIAL_NON_WORKING, '2026-02-17', 'Special non-working day'],
                ['Eid\'l Fitr', Holiday::TYPE_REGULAR, '2026-03-20', 'Feast of Ramadhan'],
                ['Eid\'l Adha', Holiday::TYPE_REGULAR, '2026-05-27', 'Feast of Sacrifice'],
            ],
            2027 => [
                ['Chinese New Year', Holiday::TYPE_SPECIAL_NON_WORKING, '2027-02-06', 'Special non-working day'],
            ],
        ];
    }

    /**
     * Creates every missing default holiday for $year — the fixed recurring set
     * plus that year's movable ones. Idempotent: existing rows are left exactly
     * as they are, so a user's edits and deletions of other rows survive.
     *
     * @return array{created: int, skipped: int}
     */
    public static function seed(int $year, ?int $userId = null): array
    {
        $created = 0;
        $skipped = 0;

        foreach (self::recurring() as [$name, $type, $monthDay, $description]) {
            // Recurring rows are year-agnostic — match on the name alone so a
            // second run never adds a duplicate under a different year.
            $exists = Holiday::where('name', $name)->where('is_recurring', true)->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            Holiday::create([
                'name' => $name,
                'type' => $type,
                'date' => $year . '-' . $monthDay,
                'is_recurring' => true,
                'is_active' => true,
                'description' => $description,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $created++;
        }

        foreach (self::movableFor($year) as [$name, $type, $date, $description]) {
            $exists = Holiday::where('name', $name)->whereYear('date', $year)->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            Holiday::create([
                'name' => $name,
                'type' => $type,
                'date' => $date,
                'is_recurring' => false,
                'is_active' => true,
                'description' => $description,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $created++;
        }

        HolidayCalendar::flush();

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** Anonymous Gregorian (Meeus/Jones/Butcher) algorithm — no ext-calendar needed. */
    public static function easterSunday(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day)->startOfDay();
    }

    public static function lastMondayOfAugust(int $year): Carbon
    {
        $date = Carbon::create($year, 8, 31)->startOfDay();

        while (!$date->isMonday()) {
            $date->subDay();
        }

        return $date;
    }
}
