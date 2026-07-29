<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Date arithmetic for a project plan, in one of two counting modes:
 *
 *  - 'working'  — weekends and non-working PH holidays are skipped, so a 4-day
 *                 lead time starting Wed 29 Jul ends Tue 4 Aug.
 *  - 'calendar' — every day counts, so the same 4 days end Sun 2 Aug.
 *
 * Only the mode changes; the counting rule itself is identical in both. Lead
 * time days are counted *after* the start date and the next row begins the
 * following day, so consecutive bars never share a day. That rule is confirmed
 * behaviour — do not "fix" the off-by-one to close the visual gap.
 */
class ScheduleCalculator
{
    public const MODE_WORKING = 'working';

    public const MODE_CALENDAR = 'calendar';

    public function __construct(
        private HolidayCalendar $holidays,
        private string $mode = self::MODE_WORKING
    ) {
    }

    /** A calculator for the same holidays but a different counting mode. */
    public function withMode(?string $mode): self
    {
        return new self($this->holidays, self::normalizeMode($mode));
    }

    public static function normalizeMode(?string $mode): string
    {
        return $mode === self::MODE_CALENDAR ? self::MODE_CALENDAR : self::MODE_WORKING;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function countsEveryDay(): bool
    {
        return $this->mode === self::MODE_CALENDAR;
    }

    /**
     * A day nobody works: a weekend or a non-working Philippine holiday. In
     * calendar mode no day is ever skipped, so this is always false.
     */
    public function isNonWorkingDay(Carbon $date): bool
    {
        if ($this->countsEveryDay()) {
            return false;
        }

        return $date->isWeekend() || $this->holidays->isHoliday($date);
    }

    /** Advances $date off a weekend or holiday onto the next working day. */
    public function toWorkingDay(Carbon $date): Carbon
    {
        $result = $date->copy();

        while ($this->isNonWorkingDay($result)) {
            $result->addDay();
        }

        return $result;
    }

    /** Adds $days countable days to $date. */
    public function addDays(Carbon $date, int $days): Carbon
    {
        $result = $this->toWorkingDay($date->copy());

        for ($i = 0; $i < $days; $i++) {
            do {
                $result->addDay();
            } while ($this->isNonWorkingDay($result));
        }

        return $result;
    }

    /**
     * The end of a span beginning on $start and running $days days — counted
     * after the start date, so the next row starts the day after this ends.
     */
    public function endOfSpan(Carbon $start, int $days): Carbon
    {
        return $this->addDays($start, max(1, $days));
    }

    /** Where the next row in the chain begins — bars never share a day. */
    public function nextDay(Carbon $date): Carbon
    {
        return $this->toWorkingDay($date->copy()->addDay());
    }

    /**
     * Days in a span, counted the same way endOfSpan() lays one out — used to
     * turn a hand-edited Start/End range back into a lead time so the rest of
     * the chain can be re-derived from it.
     */
    public function daysBetween(Carbon $start, Carbon $end): int
    {
        $cursor = $this->toWorkingDay($start->copy())->startOfDay();
        $target = $end->copy()->startOfDay();
        $days = 0;

        while ($cursor->lt($target)) {
            $cursor->addDay();

            if (!$this->isNonWorkingDay($cursor)) {
                $days++;
            }
        }

        return max(1, $days);
    }
}
