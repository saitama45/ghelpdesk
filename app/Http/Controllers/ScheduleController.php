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

class ScheduleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:schedules.view', only: ['index']),
            new Middleware('can:schedules.create', only: ['store', 'import']),
            // schedules.edit is checked inside update() to also allow the schedule owner
        ];
    }

    public function index(Request $request)
    {
        $query = Schedule::with(['user', 'store', 'ticket.item', 'scheduleStores.store']);

        if ($request->filled('start') && $request->filled('end')) {
            $query->whereBetween('start_time', [$request->start, $request->end]);
        }

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
            $query->where(function ($q) use ($request) {
                $q->where('store_id', $request->store_id)
                  ->orWhereHas('scheduleStores', fn ($sq) => $sq->where('store_id', $request->store_id));
            });
        }

        $rawSchedules = $query->get();

        // Batch-load attendance logs (avoids N+1)
        $scheduleIds = $rawSchedules->pluck('id')->toArray();
        $attendanceLogs = \App\Models\AttendanceLog::whereIn('schedule_id', $scheduleIds)
            ->orderBy('log_time')
            ->get(['schedule_id', 'schedule_store_id', 'type', 'log_time']);
        
        $logsBySchedule = $attendanceLogs->groupBy('schedule_id');
        $logsBySegment  = $attendanceLogs->whereNotNull('schedule_store_id')->groupBy('schedule_store_id');

        $schedules = $rawSchedules->map(function($schedule) use ($logsBySchedule, $logsBySegment) {
            $schedLogs     = $logsBySchedule->get($schedule->id, collect());
            $actualTimeIn  = $schedLogs->firstWhere('type', 'time_in')?->log_time?->toIso8601String();
            $actualTimeOut = $schedLogs->filter(fn($l) => $l->type === 'time_out')->last()?->log_time?->toIso8601String();

            return [
                'id'              => $schedule->id,
                'user_id'         => $schedule->user_id,
                'store_id'        => $schedule->store_id,
                'ticket_id'       => $schedule->ticket_id,
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
                'user'            => $schedule->user,
                'store'           => $schedule->store,
                'schedule_stores' => $schedule->scheduleStores->map(function ($ss) use ($logsBySegment) {
                    $segLogs = $logsBySegment->get($ss->id, collect());
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
                    ];
                }),
                'ticket' => $schedule->ticket ? [
                    'id'         => $schedule->ticket->id,
                    'ticket_key' => $schedule->ticket->ticket_key,
                    'title'      => $schedule->ticket->title,
                    'priority'   => $schedule->ticket->item ? $schedule->ticket->item->priority : $schedule->ticket->priority,
                    'status'     => $schedule->ticket->status,
                ] : null,
            ];
        });
        
        $users = User::active()->with('managers:id')->orderBy('name')->get();
        $stores = Store::where('is_active', true)->orderBy('name')->get();

        // -- Generate Pivot Report Data --
        // Get all unique years available in the database
        $dbYears = Schedule::selectRaw('YEAR(start_time) as year')
            ->distinct()
            ->pluck('year')
            ->map(fn($y) => (int)$y)
            ->toArray();

        // Always include current year and its neighbors for the UI filter
        $currentYear = (int)date('Y');
        $defaultRange = [$currentYear - 1, $currentYear, $currentYear + 1];
        
        // Merge DB years with default range, ensure unique integers, and sort descending
        $availableYears = collect($dbYears)
            ->merge($defaultRange)
            ->map(fn($y) => (int)$y)
            ->unique()
            ->sortDesc()
            ->values()
            ->toArray();

        // Determine which years to display in the table
        $selectedYearsInput = $request->input('report_years');
        if (!$selectedYearsInput) {
            // Default to the standard 3-year view: 2024, 2025, 2026
            $selectedYears = [2024, 2025, 2026];
        } else {
            $selectedYears = collect((array)$selectedYearsInput)
                ->map(fn($y) => (int)$y)
                ->unique()
                ->sort()
                ->values()
                ->toArray();
        }

        $pivotUsersQuery = User::with(['schedules' => function ($q) use ($selectedYears, $request) {
            $q->whereIn(\DB::raw('YEAR(start_time)'), $selectedYears);
            if ($request->filled('store_id')) {
                $q->where(function ($sq) use ($request) {
                    $sq->where('store_id', $request->store_id)
                       ->orWhereHas('scheduleStores', fn ($ssq) => $ssq->where('store_id', $request->store_id));
                });
            }
        }])->whereNotNull('sub_unit')->orderBy('sub_unit')->orderBy('name');

        if ($request->filled('sub_unit')) {
            $pivotUsersQuery->where('sub_unit', $request->sub_unit);
        }

        $pivotUsers = $pivotUsersQuery->get();

        $pivotStatuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'];
        $pivotData = [];

        foreach ($pivotUsers as $u) {
            $rowData = [
                'unit' => $u->sub_unit,
                'name' => $u->name,
                'years' => []
            ];

            foreach ($selectedYears as $y) {
                $yearCounts = [];
                $yearSchedules = $u->schedules->filter(fn($sched) => $sched->start_time->year === $y);

                foreach ($pivotStatuses as $s) {
                    $yearCounts[$s] = $yearSchedules
                        ->where('status', $s)
                        ->flatMap(function ($sched) {
                            $days = [];
                            $currentDay = $sched->start_time->copy()->startOfDay();
                            $lastDay = $sched->end_time->copy()->startOfDay();

                            while ($currentDay->lte($lastDay)) {
                                $days[] = $currentDay->toDateString();
                                $currentDay->addDay();
                            }

                            return $days;
                        })
                        ->unique()
                        ->count();
                }
                $rowData['years'][$y] = $yearCounts;
            }
            $pivotData[] = $rowData;
        }

        return Inertia::render('Schedules/Index', [
            'schedules' => $schedules,
            'users' => $users,
            'stores' => $stores,
            'pivotData' => $pivotData,
            'pivotYears' => $selectedYears,
            'availableYears' => $availableYears,
            'pivotStatuses' => $pivotStatuses,
            'filters' => $request->only(['user_id', 'report_years', 'sub_unit', 'store_id']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id'                          => 'required|exists:users,id',
            'status'                           => 'required|string|in:On-site,Off-site,WFH,SL,VL,Restday,Offset,Holiday',
            'stores'                           => 'required|array|min:1',
            'stores.*.store_id'                => 'nullable|exists:stores,id',
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
            'store_id'     => null,
            'pickup_start' => $request->pickup_start,
            'pickup_end'   => $request->pickup_end,
            'backlogs_start' => $request->backlogs_start,
            'backlogs_end'   => $request->backlogs_end,
        ]);

        foreach ($storeEntries as $entry) {
            $schedule->scheduleStores()->create([
                'store_id'             => $entry['store_id'] ?? null,
                'start_time'           => Carbon::parse($entry['start_time']),
                'end_time'             => Carbon::parse($entry['end_time']),
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
            'store_id'       => null,
            'pickup_start'   => $request->pickup_start,
            'pickup_end'     => $request->pickup_end,
            'backlogs_start' => $request->backlogs_start,
            'backlogs_end'   => $request->backlogs_end,
        ]);

        // Rebuild store entries
        $schedule->scheduleStores()->delete();
        foreach ($storeEntries as $entry) {
            $schedule->scheduleStores()->create([
                'store_id'             => $entry['store_id'] ?? null,
                'start_time'           => Carbon::parse($entry['start_time']),
                'end_time'             => Carbon::parse($entry['end_time']),
                'grace_period_minutes' => $entry['grace_period_minutes'] ?? 30,
                'remarks'              => $entry['remarks'] ?? null,
            ]);
        }

        return redirect()->back()->with('success', 'Schedule updated successfully');
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

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

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

                $startTime = Carbon::createFromFormat('Y-m-d', $dateStr)->setTime(7, 0, 0);
                $endTime   = Carbon::createFromFormat('Y-m-d', $dateStr)->setTime(17, 0, 0);

                // Skip if an overlapping schedule already exists for this user on this date
                $overlap = Schedule::where('user_id', $userId)
                    ->where(function ($q) use ($startTime, $endTime) {
                        $q->whereBetween('start_time', [$startTime, $endTime])
                          ->orWhereBetween('end_time', [$startTime, $endTime])
                          ->orWhere(function ($q2) use ($startTime, $endTime) {
                              $q2->where('start_time', '<=', $startTime)
                                 ->where('end_time', '>=', $endTime);
                          });
                    })->exists();

                if ($overlap) {
                    $errors[] = "Row {$rowNum}, {$dateStr}: user ID {$userId} already has a schedule for this date, skipped.";
                    continue;
                }

                $schedule = Schedule::create([
                    'user_id'    => $userId,
                    'store_id'   => null,
                    'status'     => $rawValue,
                    'start_time' => $startTime,
                    'end_time'   => $endTime,
                    'remarks'    => $rawRemarks ?: null,
                ]);

                if (in_array($rawValue, ['On-site', 'Off-site'], true)) {
                    $schedule->scheduleStores()->create([
                        'store_id'             => null,
                        'start_time'           => $startTime,
                        'end_time'             => $endTime,
                        'grace_period_minutes' => 30,
                        'remarks'              => $rawRemarks ?: null,
                    ]);
                }

                $imported++;
            }
        }

        return response()->json(['imported' => $imported, 'errors' => $errors]);
    }
}
