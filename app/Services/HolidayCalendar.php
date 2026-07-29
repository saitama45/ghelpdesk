<?php

namespace App\Services;

use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves which calendar dates are non-working because of a Philippine
 * holiday. Recurring holidays (Jan 1, Dec 25, …) are expanded across every year
 * that gets asked for; movable ones (Holy Week, Eid, National Heroes Day) and
 * custom announcements are honoured on their own stored date only.
 */
class HolidayCalendar
{
    private const CACHE_KEY = 'holiday_calendar_rows';
    private const CACHE_TTL = 3600;

    /** @var array<string, true>|null */
    private ?array $dates = null;

    private ?int $minYear = null;

    private ?int $maxYear = null;

    /** Drop the cached rows — called whenever a holiday is written. */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function isHoliday(Carbon $date): bool
    {
        $this->ensureCovers((int) $date->year);

        return isset($this->dates[$date->toDateString()]);
    }

    /**
     * Every non-working date inside the given range, as Y-m-d strings — used to
     * shade the Gantt timeline on the frontend.
     *
     * @return array<int, array{date: string, name: string, type: string}>
     */
    public function between(Carbon $from, Carbon $to): array
    {
        $rows = $this->rows();
        $found = [];

        foreach ($rows as $row) {
            $stored = Carbon::parse($row['date']);

            if ($row['is_recurring']) {
                for ($year = (int) $from->year; $year <= (int) $to->year; $year++) {
                    $candidate = $this->recurringDateFor($stored, $year);

                    if ($candidate && $candidate->betweenIncluded($from, $to)) {
                        $found[$candidate->toDateString()] = [
                            'date' => $candidate->toDateString(),
                            'name' => $row['name'],
                            'type' => $row['type'],
                        ];
                    }
                }

                continue;
            }

            if ($stored->betweenIncluded($from, $to)) {
                $found[$stored->toDateString()] = [
                    'date' => $stored->toDateString(),
                    'name' => $row['name'],
                    'type' => $row['type'],
                ];
            }
        }

        ksort($found);

        return array_values($found);
    }

    /** Expands the stored rows into a date lookup covering $year. */
    private function ensureCovers(int $year): void
    {
        if ($this->dates !== null && $this->minYear <= $year && $year <= $this->maxYear) {
            return;
        }

        $from = min($year, $this->minYear ?? $year);
        $to = max($year, $this->maxYear ?? $year);

        $this->dates = [];
        $this->minYear = $from;
        $this->maxYear = $to;

        foreach ($this->rows() as $row) {
            $stored = Carbon::parse($row['date']);

            if ($row['is_recurring']) {
                for ($y = $from; $y <= $to; $y++) {
                    $candidate = $this->recurringDateFor($stored, $y);

                    if ($candidate) {
                        $this->dates[$candidate->toDateString()] = true;
                    }
                }

                continue;
            }

            $this->dates[$stored->toDateString()] = true;
        }
    }

    /**
     * Feb 29 only exists on leap years — a recurring holiday stored on it is
     * simply skipped in other years rather than silently sliding to Mar 1.
     */
    private function recurringDateFor(Carbon $stored, int $year): ?Carbon
    {
        if (!checkdate((int) $stored->month, (int) $stored->day, $year)) {
            return null;
        }

        return Carbon::create($year, (int) $stored->month, (int) $stored->day)->startOfDay();
    }

    /** @return array<int, array{date: string, name: string, type: string, is_recurring: bool}> */
    private function rows(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Holiday::query()
                ->active()
                ->nonWorking()
                ->get(['name', 'type', 'date', 'is_recurring'])
                ->map(fn ($holiday) => [
                    'name' => $holiday->name,
                    'type' => $holiday->type,
                    'date' => $holiday->date->toDateString(),
                    'is_recurring' => (bool) $holiday->is_recurring,
                ])
                ->all();
        });
    }
}
