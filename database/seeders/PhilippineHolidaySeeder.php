<?php

namespace Database\Seeders;

use App\Models\Holiday;
use App\Services\HolidayCalendar;
use Illuminate\Database\Seeder;

class PhilippineHolidaySeeder extends Seeder
{
    /**
     * Seeds the Philippine holiday calendar. Fixed-date holidays are marked
     * recurring so they apply to every year automatically; movable ones (Holy
     * Week, Eid, National Heroes Day) are dated per proclamation and seeded for
     * the years we know — add later years from /holidays as they are announced.
     *
     * Idempotent: rows are matched on name + date, so re-running never
     * duplicates and never clobbers a user's edits to other rows.
     */
    public function run(): void
    {
        foreach ($this->recurring() as [$name, $type, $monthDay, $description]) {
            $this->upsert($name, $type, date('Y') . '-' . $monthDay, true, $description);
        }

        foreach ($this->movable() as [$name, $type, $date, $description]) {
            $this->upsert($name, $type, $date, false, $description);
        }

        HolidayCalendar::flush();
    }

    private function upsert(string $name, string $type, string $date, bool $recurring, ?string $description): void
    {
        Holiday::firstOrCreate(
            ['name' => $name, 'date' => $date],
            [
                'type' => $type,
                'is_recurring' => $recurring,
                'is_active' => true,
                'description' => $description,
            ]
        );
    }

    /** Fixed-date holidays — same month/day every year. */
    private function recurring(): array
    {
        return [
            ['New Year\'s Day', Holiday::TYPE_REGULAR, '01-01', 'Regular holiday (RA 9492)'],
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
     * Movable holidays — these shift every year with the lunar/liturgical
     * calendar or a proclamation, so each year is listed explicitly.
     */
    private function movable(): array
    {
        return [
            // 2026
            ['Chinese New Year', Holiday::TYPE_SPECIAL_NON_WORKING, '2026-02-17', 'Special non-working day'],
            ['Maundy Thursday', Holiday::TYPE_REGULAR, '2026-04-02', 'Holy Week'],
            ['Good Friday', Holiday::TYPE_REGULAR, '2026-04-03', 'Holy Week'],
            ['Black Saturday', Holiday::TYPE_SPECIAL_NON_WORKING, '2026-04-04', 'Holy Week'],
            ['Eid\'l Fitr', Holiday::TYPE_REGULAR, '2026-03-20', 'Feast of Ramadhan — date follows proclamation'],
            ['Eid\'l Adha', Holiday::TYPE_REGULAR, '2026-05-27', 'Feast of Sacrifice — date follows proclamation'],
            ['National Heroes Day', Holiday::TYPE_REGULAR, '2026-08-31', 'Last Monday of August'],

            // 2027
            ['Chinese New Year', Holiday::TYPE_SPECIAL_NON_WORKING, '2027-02-06', 'Special non-working day'],
            ['Maundy Thursday', Holiday::TYPE_REGULAR, '2027-03-25', 'Holy Week'],
            ['Good Friday', Holiday::TYPE_REGULAR, '2027-03-26', 'Holy Week'],
            ['Black Saturday', Holiday::TYPE_SPECIAL_NON_WORKING, '2027-03-27', 'Holy Week'],
            ['National Heroes Day', Holiday::TYPE_REGULAR, '2027-08-30', 'Last Monday of August'],
        ];
    }
}
