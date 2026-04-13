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
            ->get(['schedule_id', 'type', 'log_time'])
            ->groupBy('schedule_id');

        $schedules = $rawSchedules->map(function($schedule) use ($attendanceLogs) {
            $logs          = $attendanceLogs->get($schedule->id, collect());
            $actualTimeIn  = $logs->firstWhere('type', 'time_in')?->log_time?->toIso8601String();
            $actualTimeOut = $logs->filter(fn($l) => $l->type === 'time_out')->last()?->log_time?->toIso8601String();

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
                'schedule_stores' => $schedule->scheduleStores->map(fn ($ss) => [
                    'id'                   => $ss->id,
                    'store_id'             => $ss->store_id,
                    'start_time'           => $ss->start_time->toIso8601String(),
                    'end_time'             => $ss->end_time->toIso8601String(),
                    'grace_period_minutes' => $ss->grace_period_minutes ?? 30,
                    'remarks'              => $ss->remarks,
                    'store'                => $ss->store ? ['id' => $ss->store->id, 'name' => $ss->store->name] : null,
                ]),
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

        // ── Generate Pivot Report Data ──
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
                $q->where('store_id', $request->store_id);
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
                    $yearCounts[$s] = $yearSchedules->where('status', $s)->count();
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

    public function template()
    {
        $users  = User::active()->orderBy('name')->get(['id', 'name', 'email']);
        $stores = Store::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']);

        $statuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'];

        $spreadsheet = new Spreadsheet();

        // ── Hidden Lists sheet ──────────────────────────────────────────
        $listsSheet = $spreadsheet->createSheet(1);
        $listsSheet->setTitle('Lists');
        $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $listsSheet->setCellValue('A1', 'Status');
        foreach ($statuses as $i => $s) {
            $listsSheet->setCellValue('A' . ($i + 2), $s);
        }

        $listsSheet->setCellValue('B1', 'User Email');
        foreach ($users as $i => $u) {
            $listsSheet->setCellValue('B' . ($i + 2), $u->email);
        }

        $listsSheet->setCellValue('C1', 'Store Code');
        foreach ($stores as $i => $st) {
            $listsSheet->setCellValue('C' . ($i + 2), $st->code);
        }

        // ── Import Template sheet ───────────────────────────────────────
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle('Import Template');

        $headers = [
            'user_email', 'store_code', 'status',
            'start_time', 'end_time',
            'pickup_start', 'pickup_end',
            'backlogs_start', 'backlogs_end',
            'remarks',
        ];

        foreach ($headers as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $h);
        }

        // Example row
        $exEmail = $users->get(0)?->email ?? 'user@example.com';
        $exStore = $stores->get(0)?->code ?? 'STR-001';
        $sheet->setCellValue('A2', $exEmail);
        $sheet->setCellValue('B2', $exStore);
        $sheet->setCellValue('C2', 'On-site');
        $sheet->setCellValue('D2', date('Y-m-d') . ' 08:00');
        $sheet->setCellValue('E2', date('Y-m-d') . ' 17:00');
        $sheet->setCellValue('F2', '07:30');
        $sheet->setCellValue('G2', '08:00');
        $sheet->setCellValue('H2', '17:00');
        $sheet->setCellValue('I2', '18:00');
        $sheet->setCellValue('J2', 'On-site visit remarks');

        // Header styling
        $sheet->getStyle('A1:J1')->getFont()->setBold(true);
        $sheet->getStyle('A1:J1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD9E1F2');

        // Auto-size
        foreach (range(1, 10) as $ci) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Status dropdown C2:C1001
        $statusValidation = $sheet->getCell('C2')->getDataValidation();
        $statusValidation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_INFORMATION)
            ->setAllowBlank(false)
            ->setShowDropDown(false)
            ->setFormula1('Lists!$A$2:$A$' . (count($statuses) + 1))
            ->setSqref('C2:C1001');

        $spreadsheet->setActiveSheetIndex(0);

        $writer   = new Xlsx($spreadsheet);
        $filename = 'schedules-import-template.xlsx';
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

        $header = array_map('trim', array_shift($rows));
        $userMap  = User::pluck('id', 'email')->toArray();
        $storeMap = Store::pluck('id', 'code')->toArray();

        $statuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'];

        $imported = 0;
        $errors   = [];
        $rowNum   = 1;

        foreach ($rows as $line) {
            $rowNum++;

            if (empty(array_filter($line, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            if (count($line) !== count($header)) {
                $errors[] = "Row {$rowNum}: column count mismatch, skipped.";
                continue;
            }

            $data = array_combine($header, array_map(fn($v) => trim((string) $v), $line));

            // Resolve user
            $userEmail = $data['user_email'] ?? '';
            if (!isset($userMap[$userEmail])) {
                $errors[] = "Row {$rowNum}: user email '{$userEmail}' not found, skipped.";
                continue;
            }
            $userId = $userMap[$userEmail];

            // Resolve store (optional)
            $storeId = null;
            if (!empty($data['store_code'])) {
                if (!isset($storeMap[$data['store_code']])) {
                    $errors[] = "Row {$rowNum}: store code '{$data['store_code']}' not found — row imported without store.";
                } else {
                    $storeId = $storeMap[$data['store_code']];
                }
            }

            $validator = \Validator::make([
                'status'     => $data['status'] ?? null,
                'start_time' => $data['start_time'] ?? null,
                'end_time'   => $data['end_time'] ?? null,
            ], [
                'status'     => 'required|in:' . implode(',', $statuses),
                'start_time' => 'required|date',
                'end_time'   => 'required|date|after_or_equal:start_time',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNum}: " . implode(', ', $validator->errors()->all());
                continue;
            }

            $startTime = \Illuminate\Support\Carbon::parse($data['start_time']);
            $endTime   = \Illuminate\Support\Carbon::parse($data['end_time']);

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
                $errors[] = "Row {$rowNum}: user '{$userEmail}' already has an overlapping schedule for this time range, skipped.";
                continue;
            }

            Schedule::create([
                'user_id'        => $userId,
                'store_id'       => $storeId,
                'status'         => $data['status'],
                'start_time'     => $startTime,
                'end_time'       => $endTime,
                'pickup_start'   => $data['pickup_start'] ?: null,
                'pickup_end'     => $data['pickup_end'] ?: null,
                'backlogs_start' => $data['backlogs_start'] ?: null,
                'backlogs_end'   => $data['backlogs_end'] ?: null,
                'remarks'        => $data['remarks'] ?: null,
            ]);

            $imported++;
        }

        return response()->json(['imported' => $imported, 'errors' => $errors]);
    }
}
