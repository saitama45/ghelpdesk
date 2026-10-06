<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * The dashboard's shared period filter: either a Year / Month pick or an explicit
 * From–To date range.
 *
 * Every tab, drill-down and export narrows tickets through this one object, so a
 * number on a card and the list behind it always describe the same period. Before
 * it existed each tab read `year` / `month` on its own and several never did, which
 * is how the Month filter came to do nothing on Live Brand Health.
 *
 * A date range wins: as soon as either bound is supplied, year and month are
 * ignored — the filter bar hides them in that mode.
 */
final class DashboardPeriod
{
    /** Validation rules for endpoints that take the period as query parameters. */
    public const RULES = [
        'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        'month' => ['nullable', 'integer', 'between:1,12'],
        'date_from' => ['nullable', 'date_format:Y-m-d'],
        'date_to' => ['nullable', 'date_format:Y-m-d'],
    ];

    private function __construct(
        public readonly ?int $year,
        public readonly ?int $month,
        public readonly ?string $from,
        public readonly ?string $to,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::fromArray($request->only(['year', 'month', 'date_from', 'date_to']));
    }

    /** @param  array{year?:mixed, month?:mixed, date_from?:mixed, date_to?:mixed}  $input */
    public static function fromArray(array $input): self
    {
        $from = self::date($input['date_from'] ?? null);
        $to = self::date($input['date_to'] ?? null);

        if ($from || $to) {
            // A reversed range is read as the range the user meant, never as "nothing".
            if ($from && $to && $from > $to) {
                [$from, $to] = [$to, $from];
            }

            return new self(null, null, $from, $to);
        }

        $year = (int) ($input['year'] ?? 0);
        $month = (int) ($input['month'] ?? 0);

        return new self($year ?: null, $month >= 1 && $month <= 12 ? $month : null, null, null);
    }

    /** No period at all — every ticket, whenever it was created. */
    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    public function isRange(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    public function isEmpty(): bool
    {
        return ! $this->isRange() && $this->year === null && $this->month === null;
    }

    /**
     * The same period, with a missing year or month filled in from today. For the
     * widgets that always describe one month (points leaderboard, trophies) rather
     * than "all time" when nothing is picked. A date range is left exactly as given.
     */
    public function orCurrentMonth(): self
    {
        if ($this->isRange()) {
            return $this;
        }

        $now = Carbon::now();

        return new self($this->year ?? $now->year, $this->month ?? $now->month, null, null);
    }

    /**
     * Narrow a query to the period on the given date column. Works on Eloquent and
     * base query builders alike.
     */
    public function apply($query, string $column = 'created_at')
    {
        if ($this->isRange()) {
            if ($this->from) {
                $query->whereDate($column, '>=', $this->from);
            }
            if ($this->to) {
                $query->whereDate($column, '<=', $this->to);
            }

            return $query;
        }

        if ($this->year) {
            $query->whereYear($column, $this->year);
        }
        if ($this->month) {
            $query->whereMonth($column, $this->month);
        }

        return $query;
    }

    /** Human label for the period, or null when nothing is filtered. */
    public function label(): ?string
    {
        if ($this->isRange()) {
            $format = fn (string $date) => Carbon::createFromFormat('Y-m-d', $date)->format('M j, Y');

            if ($this->from && $this->to) {
                return $this->from === $this->to
                    ? $format($this->from)
                    : $format($this->from) . ' – ' . $format($this->to);
            }

            return $this->from ? 'From ' . $format($this->from) : 'Up to ' . $format($this->to);
        }

        if ($this->year && $this->month) {
            return Carbon::create($this->year, $this->month, 1)->format('F Y');
        }

        if ($this->month) {
            // A month with no year spans that month of every year.
            return Carbon::create(2000, $this->month, 1)->format('F') . ', all years';
        }

        return $this->year ? (string) $this->year : null;
    }

    /** The period as the filter bar echoes it back. */
    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'date_from' => $this->from,
            'date_to' => $this->to,
        ];
    }

    /** A strict Y-m-d calendar date, or null for anything else. */
    private static function date($value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }
}
