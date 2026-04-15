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

class ScheduleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:schedules.view', only: ['index', 'reportData']),
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
            'filters'        => $request->only(['user_id', 'report_years', 'sub_unit', 'store_id']),
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
            $rawQuery->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)
                  ->orWhereExists(function ($sub) use ($storeId) {
                      $sub->from('schedule_stores')
                          ->whereColumn('schedule_stores.schedule_id', 'schedules.id')
                          ->where('schedule_stores.store_id', $storeId);
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
                    'store_id'   => null,
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
            foreach (array_chunk($toInsert, 500) as $chunk) {
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

                foreach (array_chunk($storeRows, 500) as $chunk) {
                    DB::table('schedule_stores')->insert($chunk);
                }
            }
        });

        return response()->json(['imported' => $imported, 'errors' => $errors]);
    }
}
