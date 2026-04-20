<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\ScheduleStore;
use App\Models\User;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class ScheduleController extends Controller implements HasMiddleware
{
    private function buildActualTimesByDate($logs): array
    {
        return collect($logs)
            ->groupBy(fn ($log) => $log->log_time?->copy()->timezone('Asia/Manila')->toDateString())
            ->map(function ($dailyLogs) {
                return [
                    'actual_time_in' => $dailyLogs->firstWhere('type', 'time_in')?->log_time?->toIso8601String(),
                    'actual_time_out' => $dailyLogs->filter(fn ($log) => $log->type === 'time_out')->last()?->log_time?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    private function resolveSegmentLogs($scheduleLogs, ScheduleStore $scheduleStore)
    {
        $graceMinutes = (int) ($scheduleStore->grace_period_minutes ?? 30);
        $windowStart = $scheduleStore->start_time->copy()->subMinutes($graceMinutes);
        $windowEnd = $scheduleStore->end_time->copy();

        return collect($scheduleLogs)->filter(function ($log) use ($scheduleStore, $windowStart, $windowEnd) {
            if ((int) $log->schedule_store_id === (int) $scheduleStore->id) {
                return true;
            }

            return $log->log_time
                && $log->log_time->betweenIncluded($windowStart, $windowEnd);
        })->values();
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:schedules.view', only: ['index', 'reportData', 'missingSchedules']),
            new Middleware('can:schedules.create', only: ['store', 'import']),
            // schedules.edit is checked inside update() to also allow the schedule owner
        ];
    }

    public function index(Request $request)
    {
        $rangeStart = $request->filled('start')
            ? Carbon::parse($request->start, 'Asia/Manila')->startOfDay()
            : now('Asia/Manila')->startOfMonth();
        $rangeEnd = $request->filled('end')
            ? Carbon::parse($request->end, 'Asia/Manila')->endOfDay()
            : now('Asia/Manila')->endOfMonth();

        $query = Schedule::with(['user', 'scheduleStores.store', 'scheduleStores.ticket.item']);

        $query->where('start_time', '<=', $rangeEnd)
            ->where('end_time', '>=', $rangeStart);

        if ($request->filled('user_id')) {
            if ($request->user_id === 'my') {
                $query->where('user_id', auth()->id());
            } else {
                $query->where('user_id', $request->user_id);
            }
        }

        if ($request->filled('sub_unit')) {
            $query->whereHas('user', fn($q) => $q->where('sub_unit', $request->sub_unit));
        }

        if ($request->filled('store_id')) {
            $query->whereHas('scheduleStores', fn ($sq) => $sq->where('store_id', $request->store_id));
        }

        if ($request->filled('status')) {
            $statuses = is_array($request->status) ? $request->status : [$request->status];
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('priority')) {
            $priorities = is_array($request->priority) ? $request->priority : [$request->priority];
            $query->whereHas('scheduleStores.ticket', function($q) use ($priorities) {
                $q->where(function($sub) use ($priorities) {
                    $sub->whereIn('priority', $priorities)
                        ->orWhereHas('item', function($iq) use ($priorities) {
                            $iq->whereIn('priority', $priorities);
                        });
                });
            });
        }

        $rawSchedules = $query->get();

        // Batch-load attendance logs (avoids N+1)
        $scheduleIds = $rawSchedules->pluck('id')->filter()->values();
        $attendanceLogs = collect();

        foreach ($scheduleIds->chunk(1000) as $scheduleIdChunk) {
            $attendanceLogs = $attendanceLogs->concat(
                \App\Models\AttendanceLog::whereIn('schedule_id', $scheduleIdChunk->all())
                    ->orderBy('log_time')
                    ->get(['schedule_id', 'schedule_store_id', 'type', 'log_time'])
            );
        }
        
        $logsBySchedule = $attendanceLogs->groupBy('schedule_id');

        $schedules = $rawSchedules->map(function($schedule) use ($logsBySchedule) {
            $schedLogs     = $logsBySchedule->get($schedule->id, collect());
            $actualTimeIn  = $schedLogs->firstWhere('type', 'time_in')?->log_time?->toIso8601String();
            $actualTimeOut = $schedLogs->filter(fn($l) => $l->type === 'time_out')->last()?->log_time?->toIso8601String();
            $actualTimesByDate = $this->buildActualTimesByDate($schedLogs);

            return [
                'id'              => $schedule->id,
                'user_id'         => $schedule->user_id,
                'status'          => $schedule->status,
                'start_time'      => $schedule->start_time->toIso8601String(),
                'end_time'        => $schedule->end_time->toIso8601String(),
                'pickup_start'    => $schedule->pickup_start ? substr($schedule->pickup_start, 0, 5) : null,
                'pickup_end'      => $schedule->pickup_end   ? substr($schedule->pickup_end,   0, 5) : null,
                'backlogs_start'  => $schedule->backlogs_start ? substr($schedule->backlogs_start, 0, 5) : null,
                'backlogs_end'    => $schedule->backlogs_end   ? substr($schedule->backlogs_end,   0, 5) : null,
                'remarks'         => $schedule->remarks,
                'actual_time_in'  => $actualTimeIn,
                'actual_time_out' => $actualTimeOut,
                'actual_times_by_date' => $actualTimesByDate,
                'user'            => $schedule->user,
                'schedule_stores' => $schedule->scheduleStores->map(function ($ss) use ($schedLogs) {
                    $segLogs = $this->resolveSegmentLogs($schedLogs, $ss);
                    $segmentActualTimesByDate = $this->buildActualTimesByDate($segLogs);
                    return [
                        'id'                   => $ss->id,
                        'store_id'             => $ss->store_id,
                        'start_time'           => $ss->start_time->toIso8601String(),
                        'end_time'             => $ss->end_time->toIso8601String(),
                        'grace_period_minutes' => $ss->grace_period_minutes ?? 30,
                        'remarks'              => $ss->remarks,
                        'store'                => $ss->store ? ['id' => $ss->store->id, 'name' => $ss->store->name] : null,
                        'actual_time_in'       => $segLogs->firstWhere('type', 'time_in')?->log_time?->toIso8601String(),
                        'actual_time_out'      => $segLogs->filter(fn($l) => $l->type === 'time_out')->last()?->log_time?->toIso8601String(),
                        'actual_times_by_date' => $segmentActualTimesByDate,
                        'ticket'               => $ss->ticket ? [
                            'id'         => $ss->ticket->id,
                            'ticket_key' => $ss->ticket->ticket_key,
                            'title'      => $ss->ticket->title,
                            'priority'   => $ss->ticket->item ? $ss->ticket->item->priority : $ss->ticket->priority,
                            'status'     => $ss->ticket->status,
                        ] : null,
                    ];
                }),
                'ticket' => $schedule->scheduleStores->whereNotNull('ticket_id')->first()?->ticket ? [
                    'id'         => $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->id,
                    'ticket_key' => $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->ticket_key,
                    'title'      => $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->title,
                    'priority'   => $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->item 
                                    ? $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->item->priority 
                                    : $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->priority,
                    'status'     => $schedule->scheduleStores->whereNotNull('ticket_id')->first()->ticket->status,
                ] : null,
            ];
        });
        
        $users = User::active()->with('managers:id')->orderBy('name')->get();
        $stores = Store::where('is_active', true)->orderBy('name')->get();

        // Pivot report metadata (cheap — just year lists, no schedule data)
        $currentYear = (int)date('Y');
        $dbYears = Schedule::selectRaw('YEAR(start_time) as year')
            ->distinct()
            ->pluck('year')
            ->map(fn($y) => (int)$y)
            ->toArray();

        $availableYears = collect($dbYears)
            ->merge([$currentYear - 1, $currentYear, $currentYear + 1])
            ->map(fn($y) => (int)$y)
            ->unique()
            ->sortDesc()
            ->values()
            ->toArray();

        $selectedYearsInput = $request->input('report_years');
        $selectedYears = $selectedYearsInput
            ? collect((array)$selectedYearsInput)->map(fn($y) => (int)$y)->unique()->sort()->values()->toArray()
            : [2024, 2025, 2026];

        $pivotStatuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'];

        return Inertia::render('Schedules/Index', [
            'schedules'      => $schedules,
            'users'          => $users,
            'stores'         => $stores,
            'pivotYears'     => $selectedYears,
            'availableYears' => $availableYears,
            'pivotStatuses'  => $pivotStatuses,
            'filters'        => array_merge(
                $request->only(['user_id', 'report_years', 'sub_unit', 'store_id', 'status', 'priority']),
                [
                    'start' => $rangeStart->toDateString(),
                    'end' => $rangeEnd->toDateString(),
                ]
            ),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id'                          => 'required|exists:users,id',
            'status'                           => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday',
            'stores'                           => 'required|array|min:1',
            'stores.*.store_id'                => 'nullable|exists:stores,id',
            'stores.*.ticket_id'               => 'nullable|exists:tickets,id',
            'stores.*.start_time'              => 'required|date',
            'stores.*.end_time'                => 'required|date',
            'stores.*.grace_period_minutes'    => 'nullable|integer|min:0|max:480',
            'stores.*.remarks'                 => 'nullable|string|max:1000',
            'pickup_start'                     => 'nullable|string',
            'pickup_end'                       => 'nullable|string',
            'backlogs_start'                   => 'nullable|string',
            'backlogs_end'                     => 'nullable|string',
        ]);

        $storeEntries = $request->input('stores');
        $startTime = Carbon::parse(collect($storeEntries)->min('start_time'));
        $endTime   = Carbon::parse(collect($storeEntries)->max('end_time'));

        // Check for overlaps using overall shift window
        $overlap = Schedule::where('user_id', $request->user_id)
            ->where(function($query) use ($startTime, $endTime) {
                $query->whereBetween('start_time', [$startTime, $endTime])
                      ->orWhereBetween('end_time', [$startTime, $endTime])
                      ->orWhere(function($q) use ($startTime, $endTime) {
                          $q->where('start_time', '<=', $startTime)
                            ->where('end_time', '>=', $endTime);
                      });
            })->exists();

        if ($overlap) {
            return redirect()->back()->withErrors(['stores' => 'This user already has a schedule that overlaps with the selected time range.']);
        }

        $schedule = Schedule::create([
            'user_id'      => $request->user_id,
            'status'       => $request->status,
            'start_time'   => $startTime,
            'end_time'     => $endTime,
            'pickup_start' => $request->pickup_start,
            'pickup_end'   => $request->pickup_end,
            'backlogs_start' => $request->backlogs_start,
            'backlogs_end'   => $request->backlogs_end,
        ]);

        foreach ($this->expandStoreEntries($storeEntries) as $entry) {
            $schedule->scheduleStores()->create([
                'store_id'             => $entry['store_id'] ?? null,
                'ticket_id'            => $entry['ticket_id'] ?? null,
                'start_time'           => $entry['start_time'],
                'end_time'             => $entry['end_time'],
                'grace_period_minutes' => $entry['grace_period_minutes'] ?? 30,
                'remarks'              => $entry['remarks'] ?? null,
            ]);
        }

        return redirect()->back()->with('success', 'Schedule created successfully');
    }

    public function update(Request $request, Schedule $schedule)
    {
        $user = auth()->user();
        
        // Authorization Logic
        $isOwner = (int) $schedule->user_id === (int) $user->id;
        $isAdmin = $user->hasRole('Admin');
        
        // Check if the current user is a manager of the user who owns the schedule
        $isDirectManager = $schedule->user->managers()->where('manager_id', $user->id)->exists();
        
        if (!$isOwner && !$isAdmin && !$isDirectManager) {
            abort(403, 'You are not authorized to edit this schedule. Only the owner or their assigned manager can edit.');
        }

        $request->validate([
            'user_id'                          => 'required|exists:users,id',
            'status'                           => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday',
            'stores'                           => 'required|array|min:1',
            'stores.*.store_id'                => 'nullable|exists:stores,id',
            'stores.*.ticket_id'               => 'nullable|exists:tickets,id',
            'stores.*.start_time'              => 'required|date',
            'stores.*.end_time'                => 'required|date',
            'stores.*.grace_period_minutes'    => 'nullable|integer|min:0|max:480',
            'stores.*.remarks'                 => 'nullable|string|max:1000',
            'pickup_start'                     => 'nullable|string',
            'pickup_end'                       => 'nullable|string',
            'backlogs_start'                   => 'nullable|string',
            'backlogs_end'                     => 'nullable|string',
        ]);

        $storeEntries = $request->input('stores');
        $startTime = Carbon::parse(collect($storeEntries)->min('start_time'));
        $endTime   = Carbon::parse(collect($storeEntries)->max('end_time'));

        // Check for overlaps excluding current schedule
        $overlap = Schedule::where('user_id', $request->user_id)
            ->where('id', '!=', $schedule->id)
            ->where(function($query) use ($startTime, $endTime) {
                $query->whereBetween('start_time', [$startTime, $endTime])
                      ->orWhereBetween('end_time', [$startTime, $endTime])
                      ->orWhere(function($q) use ($startTime, $endTime) {
                          $q->where('start_time', '<=', $startTime)
                            ->where('end_time', '>=', $endTime);
                      });
            })->exists();

        if ($overlap) {
            return redirect()->back()->withErrors(['stores' => 'This user already has a schedule that overlaps with the selected time range.']);
        }

        $schedule->update([
            'user_id'        => $request->user_id,
            'status'         => $request->status,
            'start_time'     => $startTime,
            'end_time'       => $endTime,
            'pickup_start'   => $request->pickup_start,
            'pickup_end'     => $request->pickup_end,
            'backlogs_start' => $request->backlogs_start,
            'backlogs_end'   => $request->backlogs_end,
        ]);

        // Rebuild store entries
        $schedule->scheduleStores()->delete();
        foreach ($this->expandStoreEntries($storeEntries) as $entry) {
            $schedule->scheduleStores()->create([
                'store_id'             => $entry['store_id'] ?? null,
                'ticket_id'            => $entry['ticket_id'] ?? null,
                'start_time'           => $entry['start_time'],
                'end_time'             => $entry['end_time'],
                'grace_period_minutes' => $entry['grace_period_minutes'] ?? 30,
                'remarks'              => $entry['remarks'] ?? null,
            ]);
        }

        return redirect()->back()->with('success', 'Schedule updated successfully');
    }

    /**
     * Expand each store entry into one record per calendar day.
     *
     * A single entry with start=2026-04-15 08:00 / end=2026-04-20 17:00
     * becomes six rows, each covering one day at the same start/end times.
     */
    private function expandStoreEntries(array $storeEntries): array
    {
        $expanded = [];

        foreach ($storeEntries as $entry) {
            $start     = Carbon::parse($entry['start_time']);
            $end       = Carbon::parse($entry['end_time']);
            $startDate = $start->copy()->startOfDay();
            $endDate   = $end->copy()->startOfDay();

            // Single-day entry — keep as-is
            if ($startDate->eq($endDate)) {
                $expanded[] = [
                    'store_id'             => $entry['store_id'] ?? null,
                    'ticket_id'            => $entry['ticket_id'] ?? null,
                    'start_time'           => $start,
                    'end_time'             => $end,
                    'grace_period_minutes' => $entry['grace_period_minutes'] ?? 30,
                    'remarks'              => $entry['remarks'] ?? null,
                ];
                continue;
            }

            // Multi-day — one row per day using the same time-of-day
            $startTimeStr = $start->format('H:i:s');
            $endTimeStr   = $end->format('H:i:s');
            $current      = $startDate->copy();

            while ($current->lte($endDate)) {
                $expanded[] = [
                    'store_id'             => $entry['store_id'] ?? null,
                    'ticket_id'            => $entry['ticket_id'] ?? null,
                    'start_time'           => $current->copy()->setTimeFromTimeString($startTimeStr),
                    'end_time'             => $current->copy()->setTimeFromTimeString($endTimeStr),
                    'grace_period_minutes' => $entry['grace_period_minutes'] ?? 30,
                    'remarks'              => $entry['remarks'] ?? null,
                ];
                $current->addDay();
            }
        }

        return $expanded;
    }

    public function reportData(Request $request)
    {
        $pivotStatuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'];

        $selectedYearsInput = $request->input('report_years');
        $selectedYears = $selectedYearsInput
            ? collect((array)$selectedYearsInput)->map(fn($y) => (int)$y)->unique()->sort()->values()->toArray()
            : [2024, 2025, 2026];

        $pivotUsersQuery = User::whereNotNull('sub_unit')->orderBy('sub_unit')->orderBy('name');
        if ($request->filled('sub_unit')) {
            $pivotUsersQuery->where('sub_unit', $request->sub_unit);
        }
        $pivotUsers   = $pivotUsersQuery->get(['id', 'name', 'sub_unit']);
        $pivotUserIds = $pivotUsers->pluck('id')->toArray();

        if (empty($pivotUserIds)) {
            return response()->json([]);
        }

        // Single query: count days per user / year / status using DATEDIFF.
        // SQL Server syntax: DATEDIFF(day, start, end) + 1
        //   single-day schedule (07:00–17:00 same day) → DATEDIFF = 0 → 1 day
        //   two-day schedule                           → DATEDIFF = 1 → 2 days
        // CAST(... AS DATE) strips the time component (SQL Server equivalent of MySQL DATE()).
        $rawQuery = DB::table('schedules')
            ->select([
                'user_id',
                DB::raw('YEAR(start_time) as year'),
                'status',
                DB::raw('SUM(DATEDIFF(day, CAST(start_time AS DATE), CAST(end_time AS DATE)) + 1) as day_count'),
            ])
            ->whereIn(DB::raw('YEAR(start_time)'), $selectedYears)
            ->whereIn('user_id', $pivotUserIds)
            ->whereIn('status', $pivotStatuses);

        if ($request->filled('store_id')) {
            $storeId = $request->store_id;
            $rawQuery->whereExists(function ($sub) use ($storeId) {
                $sub->from('schedule_stores')
                    ->whereColumn('schedule_stores.schedule_id', 'schedules.id')
                    ->where('schedule_stores.store_id', $storeId);
            });
        }

        if ($request->filled('status')) {
            $rawQuery->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $priority = $request->priority;
            $rawQuery->whereExists(function ($sub) use ($priority) {
                $sub->from('schedule_stores')
                    ->join('tickets', 'schedule_stores.ticket_id', '=', 'tickets.id')
                    ->leftJoin('items', 'tickets.item_id', '=', 'items.id')
                    ->whereColumn('schedule_stores.schedule_id', 'schedules.id')
                    ->where(function($q) use ($priority) {
                        $q->where('tickets.priority', $priority)
                          ->orWhere('items.priority', $priority);
                    });
            });
        }

        $grouped = $rawQuery
            ->groupBy('user_id', DB::raw('YEAR(start_time)'), 'status')
            ->get()
            ->groupBy('user_id');

        $pivotData = [];
        foreach ($pivotUsers as $u) {
            $byYear = $grouped->get($u->id, collect())->groupBy('year');
            $rowData = ['unit' => $u->sub_unit, 'name' => $u->name, 'years' => []];

            foreach ($selectedYears as $y) {
                $yearRows   = $byYear->get((string)$y, collect());
                $yearCounts = [];
                foreach ($pivotStatuses as $s) {
                    $yearCounts[$s] = (int)($yearRows->firstWhere('status', $s)?->day_count ?? 0);
                }
                $rowData['years'][$y] = $yearCounts;
            }
            $pivotData[] = $rowData;
        }

        return response()->json($pivotData);
    }

    public function template(Request $request)
    {
        $year = (int) $request->input('year', now()->year);
        $year = max(2020, min(2100, $year));

        // Build full-year date list
        $dates = [];
        $startDate = Carbon::create($year, 1, 1);
        $endDate   = Carbon::create($year, 12, 31);
        for ($d = $startDate->copy(); $d->lte($endDate); $d->addDay()) {
            $dates[] = $d->format('Y-m-d');
        }

        $users    = User::active()->orderBy('name')->get(['id', 'name']);
        $statuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'];

        $spreadsheet = new Spreadsheet();

        // -- Hidden Lists sheet ------------------------------------------
        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $listsSheet->setCellValue('A1', 'Status');
        foreach ($statuses as $i => $s) {
            $listsSheet->setCellValue('A' . ($i + 2), $s);
        }
        $listsSheet->setCellValue('A' . (count($statuses) + 2), 'NA');

        // -- Import Template sheet ---------------------------------------
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('Import Template');

        // Layout: A=user_id, B=user_name, then per date: [YYYY-MM-DD | YYYY-MM-DD_remarks] pairs
        $sheet->setCellValue('A1', 'user_id');
        $sheet->setCellValue('B1', 'user_name');

        foreach ($dates as $i => $date) {
            // Each date occupies 2 columns: status then remarks
            $statusColIdx  = ($i * 2) + 3;                // col C, E, G, ...
            $remarksColIdx = ($i * 2) + 4;                // col D, F, H, ...
            $statusCol  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statusColIdx);
            $remarksCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($remarksColIdx);
            $sheet->setCellValue("{$statusCol}1",  $date);
            $sheet->setCellValue("{$remarksCol}1", "{$date}_remarks");
        }

        // Fill user rows
        $lastUserRow = count($users) + 1;
        foreach ($users as $rowIdx => $user) {
            $row = $rowIdx + 2;
            $sheet->setCellValue("A{$row}", $user->id);
            $sheet->setCellValue("B{$row}", $user->name);
        }

        // Style user_name column (col B) — grey, reference-only
        $sheet->getStyle("B2:B{$lastUserRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF0F0F0');
        $sheet->getStyle("B2:B{$lastUserRow}")->getFont()
            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF9CA3AF'));

        // Header styling across all columns
        $totalCols     = 2 + (count($dates) * 2);
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalCols);
        $sheet->getStyle("A1:{$lastColLetter}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColLetter}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');

        // Status dropdown for every status column; remarks columns stay free-text
        $statusFormula   = 'Lists!$A$2:$A$' . (count($statuses) + 2);
        $dropdownLastRow = max($lastUserRow, 2);

        foreach ($dates as $i => $date) {
            $statusColIdx = ($i * 2) + 3;
            $col   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statusColIdx);
            $sqref = "{$col}2:{$col}{$dropdownLastRow}";
            $v = $sheet->getCell("{$col}2")->getDataValidation();
            $v->setType(DataValidation::TYPE_LIST)
              ->setErrorStyle(DataValidation::STYLE_INFORMATION)
              ->setAllowBlank(true)
              ->setShowDropDown(false)
              ->setFormula1($statusFormula)
              ->setSqref($sqref);
        }

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setAutoSize(true);
        foreach ($dates as $i => $date) {
            $sCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(($i * 2) + 3);
            $rCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(($i * 2) + 4);
            $sheet->getColumnDimension($sCol)->setWidth(12);
            $sheet->getColumnDimension($rCol)->setWidth(18);
        }

        // Freeze panes at C2 — user_id + user_name stay pinned while scrolling
        $sheet->freezePane('C2');

        $spreadsheet->setActiveSheetIndex(0);

        $writer   = new Xlsx($spreadsheet);
        $filename = "schedules-import-{$year}.xlsx";
        $httpHeaders = [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ];

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, $httpHeaders);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,csv|max:5120']);

        $filePath = $request->file('file')->getRealPath();
        $reader = IOFactory::createReaderForFile($filePath);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $spreadsheet = $reader->load($filePath);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if (empty($rows)) {
            return response()->json(['imported' => 0, 'errors' => ['File is empty.']]);
        }

        // Row 0 = header: user_id | user_name | YYYY-MM-DD | YYYY-MM-DD | ...
        $header = array_map(fn($v) => trim((string) $v), array_shift($rows));

        // Build lookup: user_id (int) → exists
        $validUserIds = User::pluck('id')->flip()->toArray(); // [id => 0]

        $statuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'];

        // Build date-column map from header:
        //   dateStr => [ 'statusIdx' => int, 'remarksIdx' => int|null ]
        // Header format: col 0 = user_id, col 1 = user_name,
        //   then pairs: YYYY-MM-DD  |  YYYY-MM-DD_remarks  |  ...
        $dateCols = [];
        foreach ($header as $idx => $h) {
            if ($idx < 2) continue;
            if (preg_match('/^(\d{4}-\d{2}-\d{2})_remarks$/', $h, $m)) {
                // Remarks column — attach to the already-registered date entry
                if (isset($dateCols[$m[1]])) {
                    $dateCols[$m[1]]['remarksIdx'] = $idx;
                }
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $h)) {
                // Status column for this date
                $dateCols[$h] = ['statusIdx' => $idx, 'remarksIdx' => null];
            }
        }

        if (empty($dateCols)) {
            return response()->json(['imported' => 0, 'errors' => ['No valid date columns found in the header row.']]);
        }

        $imported = 0;
        $errors   = [];
        $rowNum   = 1;
        $candidates = [];
        $candidateUserIds = [];
        $candidateDates = [];
        $seenImportDates = [];

        foreach ($rows as $line) {
            $rowNum++;

            if (empty(array_filter($line, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            // Resolve user_id from column 0
            $rawId  = isset($line[0]) ? trim((string) $line[0]) : '';
            $userId = (int) $rawId;

            if (!$userId || !isset($validUserIds[$userId])) {
                $errors[] = "Row {$rowNum}: user_id '{$rawId}' not found, row skipped.";
                continue;
            }

            // Process each date pair
            foreach ($dateCols as $dateStr => $cols) {
                $rawValue   = isset($line[$cols['statusIdx']]) ? trim((string) $line[$cols['statusIdx']]) : '';
                $rawRemarks = ($cols['remarksIdx'] !== null && isset($line[$cols['remarksIdx']]))
                    ? trim((string) $line[$cols['remarksIdx']])
                    : '';

                // Empty or NA → no schedule for this date
                if ($rawValue === '' || strtoupper($rawValue) === 'NA') {
                    continue;
                }

                if (!in_array($rawValue, $statuses, true)) {
                    $errors[] = "Row {$rowNum}, {$dateStr}: invalid status '{$rawValue}', skipped.";
                    continue;
                }

                $importKey = $userId . '|' . $dateStr;
                if (isset($seenImportDates[$importKey])) {
                    $errors[] = "Row {$rowNum}, {$dateStr}: duplicate import entry for user ID {$userId}, skipped.";
                    continue;
                }

                $seenImportDates[$importKey] = true;
                $candidates[] = [
                    'user_id' => $userId,
                    'date' => $dateStr,
                    'status' => $rawValue,
                    'remarks' => $rawRemarks ?: null,
                    'row_num' => $rowNum,
                ];
                $candidateUserIds[$userId] = true;
                $candidateDates[$dateStr] = true;
            }
        }

        if (empty($candidates)) {
            return response()->json(['imported' => 0, 'errors' => $errors]);
        }

        $candidateDateKeys = array_keys($candidateDates);
        sort($candidateDateKeys);

        $rangeStart = Carbon::createFromFormat('Y-m-d', $candidateDateKeys[0])->startOfDay();
        $rangeEnd = Carbon::createFromFormat('Y-m-d', end($candidateDateKeys))->endOfDay();

        $existingDateMap = [];
        $existingSchedules = Schedule::query()
            ->select(['user_id', 'start_time', 'end_time'])
            ->whereIn('user_id', array_keys($candidateUserIds))
            ->where('start_time', '<=', $rangeEnd)
            ->where('end_time', '>=', $rangeStart)
            ->get();

        foreach ($existingSchedules as $existingSchedule) {
            $day = $existingSchedule->start_time->copy()->startOfDay();
            $lastDay = $existingSchedule->end_time->copy()->startOfDay();

            while ($day->lte($lastDay)) {
                $existingDateMap[$existingSchedule->user_id . '|' . $day->toDateString()] = true;
                $day->addDay();
            }
        }

        DB::transaction(function () use ($candidates, &$errors, &$imported, &$existingDateMap) {
            $timestamp = now();
            $maxSqlServerParameters = 2000;
            $chunkRowsForInsert = function (array $rows) use ($maxSqlServerParameters): array {
                if (empty($rows)) {
                    return [];
                }

                $columnCount = max(1, count($rows[0]));
                $chunkSize = max(1, (int) floor($maxSqlServerParameters / $columnCount));

                return array_chunk($rows, $chunkSize);
            };

            // Phase 1 — filter out duplicates and build the rows to insert
            $toInsert       = [];  // rows for schedules table
            $onSiteDateKeys = [];  // user_id|date keys that need a schedule_store

            foreach ($candidates as $candidate) {
                $importKey = $candidate['user_id'] . '|' . $candidate['date'];
                if (isset($existingDateMap[$importKey])) {
                    $errors[] = "Row {$candidate['row_num']}, {$candidate['date']}: user ID {$candidate['user_id']} already has a schedule for this date, skipped.";
                    continue;
                }

                $startTime = Carbon::createFromFormat('Y-m-d', $candidate['date'])->setTime(7, 0, 0);
                $endTime   = Carbon::createFromFormat('Y-m-d', $candidate['date'])->setTime(17, 0, 0);

                $toInsert[] = [
                    'user_id'    => $candidate['user_id'],
                    'status'     => $candidate['status'],
                    'start_time' => $startTime->toDateTimeString(),
                    'end_time'   => $endTime->toDateTimeString(),
                    'remarks'    => $candidate['remarks'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                if (in_array($candidate['status'], ['On-site', 'Off-site'], true)) {
                    $onSiteDateKeys[$candidate['user_id'] . '|' . $candidate['date']] = [
                        'start_time' => $startTime->toDateTimeString(),
                        'end_time'   => $endTime->toDateTimeString(),
                        'remarks'    => $candidate['remarks'],
                    ];
                }

                $existingDateMap[$importKey] = true;
            }

            if (empty($toInsert)) {
                return;
            }

            // Phase 2 — bulk insert schedules in chunks of 500
            foreach ($chunkRowsForInsert($toInsert) as $chunk) {
                DB::table('schedules')->insert($chunk);
            }
            $imported = count($toInsert);

            // Phase 3 — bulk insert schedule_stores for On-site / Off-site rows
            if (!empty($onSiteDateKeys)) {
                $insertedUserIds = array_unique(array_map(
                    fn($k) => (int) explode('|', $k)[0],
                    array_keys($onSiteDateKeys)
                ));

                $insertedDates = array_unique(array_map(
                    fn($k) => explode('|', $k)[1],
                    array_keys($onSiteDateKeys)
                ));
                sort($insertedDates);

                // Re-query just the IDs we need (safe inside the transaction)
                $insertedSchedules = DB::table('schedules')
                    ->select(['id', 'user_id', 'start_time'])
                    ->whereIn('user_id', $insertedUserIds)
                    ->where('start_time', '>=', $insertedDates[0] . ' 07:00:00')
                    ->where('start_time', '<=', end($insertedDates) . ' 07:00:00')
                    ->get()
                    ->keyBy(fn($s) => $s->user_id . '|' . substr($s->start_time, 0, 10));

                $storeRows = [];
                foreach ($onSiteDateKeys as $key => $times) {
                    $sched = $insertedSchedules->get($key);
                    if (!$sched) continue;

                    $storeRows[] = [
                        'schedule_id'          => $sched->id,
                        'store_id'             => null,
                        'start_time'           => $times['start_time'],
                        'end_time'             => $times['end_time'],
                        'grace_period_minutes' => 30,
                        'remarks'              => $times['remarks'],
                        'created_at'           => $timestamp,
                        'updated_at'           => $timestamp,
                    ];
                }

                foreach ($chunkRowsForInsert($storeRows) as $chunk) {
                    DB::table('schedule_stores')->insert($chunk);
                }
            }
        });

        return response()->json(['imported' => $imported, 'errors' => $errors]);
    }

    public function missingSchedules(Request $request)
    {
        $rangeStart = $request->filled('start')
            ? Carbon::parse($request->start, 'Asia/Manila')->startOfDay()
            : now('Asia/Manila')->startOfMonth();
        $rangeEnd = $request->filled('end')
            ? Carbon::parse($request->end, 'Asia/Manila')->endOfDay()
            : now('Asia/Manila')->endOfMonth();

        // Generate all dates in range
        $allDates = [];
        $tempDate = $rangeStart->copy();
        while ($tempDate <= $rangeEnd) {
            $allDates[] = $tempDate->toDateString();
            $tempDate->addDay();
        }

        $query = User::active();

        if ($request->filled('sub_unit')) {
            $query->where('sub_unit', $request->sub_unit);
        }

        if ($request->filled('user_id')) {
            if ($request->user_id === 'my') {
                $query->where('id', auth()->id());
            } else {
                $query->where('id', $request->user_id);
            }
        }

        $users = $query->orderByRaw("CASE WHEN sub_unit IS NULL OR sub_unit = '' THEN 1 ELSE 0 END")
            ->orderBy('sub_unit')
            ->orderBy('name')
            ->get(['id', 'name', 'sub_unit', 'email']);
        $userIds = $users->pluck('id');

        // Fetch all schedules for these users in range
        $schedules = Schedule::whereIn('user_id', $userIds)
            ->where('start_time', '<=', $rangeEnd)
            ->where('end_time', '>=', $rangeStart)
            ->get(['user_id', 'start_time', 'end_time']);

        $userScheduledDates = [];
        foreach ($schedules as $s) {
            $sStart = $s->start_time->copy()->timezone('Asia/Manila');
            $sEnd = $s->end_time->copy()->timezone('Asia/Manila');
            
            $curr = $sStart->copy();
            while ($curr->toDateString() <= $sEnd->toDateString()) {
                $dateStr = $curr->toDateString();
                if ($dateStr >= $rangeStart->toDateString() && $dateStr <= $rangeEnd->toDateString()) {
                    $userScheduledDates[$s->user_id][$dateStr] = true;
                }
                $curr->addDay();
            }
        }

        $results = $users->map(function ($user) use ($allDates, $userScheduledDates) {
            $missing = [];
            foreach ($allDates as $date) {
                if (!isset($userScheduledDates[$user->id][$date])) {
                    $missing[] = Carbon::parse($date)->format('M j');
                }
            }
            
            if (empty($missing)) return null;

            $user->missing_days = $missing;
            $user->missing_days_count = count($missing);
            return $user;
        })->filter()->values();

        // Manual Pagination
        $perPage = (int) $request->input('per_page', 10);
        $page = (int) $request->input('page', 1);
        $total = $results->count();

        $paginatedResults = new LengthAwarePaginator(
            $results->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginatedResults);
    }
}
