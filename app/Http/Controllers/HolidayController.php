<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Services\HolidayCalendar;
use App\Support\PhilippineHolidays;
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
     * Fills in the whole default Philippine calendar for a year in one click —
     * the fixed regular/special holidays plus that year's movable ones. Safe to
     * press repeatedly: existing rows are counted as skipped, never duplicated
     * or overwritten.
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $year = (int) $validated['year'];
        ['created' => $created, 'skipped' => $skipped] = PhilippineHolidays::seed($year, $request->user()->id);

        if ($created === 0) {
            return redirect()->back()->with('info', "All {$year} default holidays are already on file.");
        }

        $note = $skipped ? " ({$skipped} already existed)" : '';

        return redirect()->back()->with(
            'success',
            "Added {$created} default holiday(s) for {$year}{$note}. Fixed-date holidays repeat automatically every year."
        );
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

    /** Starts at the current year and runs forward — planning is always ahead. */
    private function yearOptions(): array
    {
        $current = (int) date('Y');

        return collect(range($current, $current + 5))
            ->map(fn ($year) => ['label' => (string) $year, 'value' => $year])
            ->all();
    }
}
