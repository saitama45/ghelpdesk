<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Services\HolidayCalendar;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HolidayController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:holidays.view', only: ['index']),
            new Middleware('can:holidays.create', only: ['store', 'generate']),
            new Middleware('can:holidays.edit', only: ['update']),
            new Middleware('can:holidays.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = Holiday::query()->with(['creator:id,name']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // The page opens on the current year; 'all' is the explicit opt-out.
        // Recurring rows have no meaningful year, so they always show; dated
        // rows are filtered to the year being viewed.
        $year = $request->input('year', (string) date('Y'));

        if ($year !== 'all') {
            $year = (int) $year;
            $query->where(function ($q) use ($year) {
                $q->where('is_recurring', true)
                    ->orWhereYear('date', $year);
            });
        }

        $holidays = $query
            ->orderByRaw('MONTH(date), DAY(date)')
            ->paginate($request->get('per_page', 15))
            ->withQueryString();

        return Inertia::render('Holidays/Index', [
            'holidays' => $holidays,
            'filters' => [
                'search' => $request->input('search'),
                'type' => $request->input('type'),
                'year' => (string) $year,
            ],
            'holidayTypes' => collect(Holiday::types())->map(fn ($label, $value) => [
                'label' => $label,
                'value' => $value,
            ])->values(),
            'years' => $this->yearOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        Holiday::create($validated);
        HolidayCalendar::flush();

        return redirect()->back()->with('success', 'Holiday created successfully.');
    }

    public function update(Request $request, Holiday $holiday)
    {
        $validated = $request->validate($this->rules($holiday->id));

        $validated['updated_by'] = $request->user()->id;

        $holiday->update($validated);
        HolidayCalendar::flush();

        return redirect()->back()->with('success', 'Holiday updated successfully.');
    }

    /**
     * Fills in a year's movable holidays in one click.
     *
     * Fixed-date holidays never need this — they are stored once with
     * is_recurring and already apply to every year, 2030 included. What does
     * change yearly is Holy Week (tied to Easter) and National Heroes Day (last
     * Monday of August); both are computable, so they are generated here.
     * Eid'l Fitr / Eid'l Adha / Chinese New Year follow the lunar calendar and
     * a Malacañang proclamation, so they still have to be added by hand.
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $year = (int) $validated['year'];
        $created = 0;
        $skipped = 0;

        foreach ($this->derivedHolidaysFor($year) as [$name, $type, $date, $description]) {
            $exists = Holiday::where('name', $name)
                ->whereYear('date', $year)
                ->exists();

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
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $created++;
        }

        HolidayCalendar::flush();

        $message = $created > 0
            ? "Generated {$created} holiday(s) for {$year}" . ($skipped ? " ({$skipped} already existed)." : '.')
            : "All computable {$year} holidays already exist.";

        return redirect()->back()->with(
            $created > 0 ? 'success' : 'info',
            $message . ' Fixed-date holidays already repeat automatically; add Eid\'l Fitr, Eid\'l Adha and Chinese New Year once proclaimed.'
        );
    }

    /** @return array<int, array{0: string, 1: string, 2: string, 3: string}> */
    private function derivedHolidaysFor(int $year): array
    {
        $easter = $this->easterSunday($year);

        return [
            ['Maundy Thursday', Holiday::TYPE_REGULAR, $easter->copy()->subDays(3)->toDateString(), 'Holy Week (generated)'],
            ['Good Friday', Holiday::TYPE_REGULAR, $easter->copy()->subDays(2)->toDateString(), 'Holy Week (generated)'],
            ['Black Saturday', Holiday::TYPE_SPECIAL_NON_WORKING, $easter->copy()->subDay()->toDateString(), 'Holy Week (generated)'],
            ['National Heroes Day', Holiday::TYPE_REGULAR, $this->lastMondayOfAugust($year)->toDateString(), 'Last Monday of August (generated)'],
        ];
    }

    /** Anonymous Gregorian (Meeus/Jones/Butcher) algorithm — no ext-calendar needed. */
    private function easterSunday(int $year): \Carbon\Carbon
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

        return \Carbon\Carbon::create($year, $month, $day)->startOfDay();
    }

    private function lastMondayOfAugust(int $year): \Carbon\Carbon
    {
        $date = \Carbon\Carbon::create($year, 8, 31)->startOfDay();

        while (!$date->isMonday()) {
            $date->subDay();
        }

        return $date;
    }

    public function destroy(Holiday $holiday)
    {
        $holiday->delete();
        HolidayCalendar::flush();

        return redirect()->back()->with('success', 'Holiday deleted successfully.');
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('holidays', 'name')
                    ->where(fn ($q) => $q->where('date', request('date')))
                    ->ignore($ignoreId),
            ],
            'type' => ['required', Rule::in(array_keys(Holiday::types()))],
            'date' => 'required|date',
            'is_recurring' => 'boolean',
            'is_active' => 'boolean',
            'description' => 'nullable|string|max:255',
        ];
    }

    /** A window around today, so past and upcoming proclamations are reachable. */
    private function yearOptions(): array
    {
        $current = (int) date('Y');

        return collect(range($current - 2, $current + 5))
            ->map(fn ($year) => ['label' => (string) $year, 'value' => $year])
            ->all();
    }
}
