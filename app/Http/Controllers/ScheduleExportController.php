<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ScheduleExportController extends Controller
{
    private function buildActualTimesByDate($logs): array
    {
        return collect($logs)
            ->groupBy(fn ($log) => $log->log_time?->copy()->timezone('Asia/Manila')->toDateString())
            ->map(function ($dailyLogs) {
                return [
                    'actual_time_in' => $dailyLogs->firstWhere('type', 'time_in')?->log_time,
                    'actual_time_out' => $dailyLogs->filter(fn ($log) => $log->type === 'time_out')->last()?->log_time,
                ];
            })
            ->toArray();
    }

    private function resolveSegmentLogs($scheduleLogs, $scheduleStore)
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

    public function pdf(Request $request)
    {
        if ($request->input('view') === 'report') {
            return $this->reportPdf($request);
        }

        $query = Schedule::with(['user', 'scheduleStores.store'])
            ->orderBy('start_time', 'asc');

        // Date range
        if ($request->filled('start') && $request->filled('end')) {
            $query->whereBetween('start_time', [
                Carbon::parse($request->start)->startOfDay(),
                Carbon::parse($request->end)->endOfDay()
            ]);
        }

        // User filter
        if ($request->filled('user_id')) {
            if ($request->user_id === 'my') {
                $query->where('user_id', auth()->id());
            } else {
                $query->where('user_id', $request->user_id);
            }
        }

        // Sub-unit filter
        if ($request->filled('sub_unit')) {
            $query->whereHas('user', fn($q) => $q->where('sub_unit', $request->sub_unit));
        }

        // Store filter
        if ($request->filled('store_id')) {
            $query->whereHas('scheduleStores', fn($sq) => $sq->where('store_id', $request->store_id));
        }

        $schedules = $query->get();

        // Batch-load attendance logs for all matched schedules
        $attendanceLogs = AttendanceLog::whereIn('schedule_id', $schedules->pluck('id'))
            ->orderBy('log_time')
            ->get();
        
        $logsBySchedule = $attendanceLogs->groupBy('schedule_id');

        // Flatten to one row per store visit
        $rows = [];
        foreach ($schedules as $schedule) {
            $scheduleLogs = $logsBySchedule->get($schedule->id, collect());
            if ($schedule->scheduleStores->isNotEmpty()) {
                foreach ($schedule->scheduleStores as $ss) {
                    $segLogs = $this->resolveSegmentLogs($scheduleLogs, $ss);
                    $actualTimesByDate = $this->buildActualTimesByDate($segLogs);
                    $rowDate = $ss->start_time->copy()->timezone('Asia/Manila')->toDateString();
                    $dateActuals = $actualTimesByDate[$rowDate] ?? [
                        'actual_time_in' => $segLogs->firstWhere('type', 'time_in')?->log_time,
                        'actual_time_out' => $segLogs->filter(fn($l) => $l->type === 'time_out')->last()?->log_time,
                    ];

                    $rows[] = [
                        'user'           => $schedule->user->name,
                        'status'         => $schedule->status,
                        'pickup_start'   => $schedule->pickup_start,
                        'pickup_end'     => $schedule->pickup_end,
                        'backlogs_start' => $schedule->backlogs_start,
                        'backlogs_end'   => $schedule->backlogs_end,
                        'remarks'        => $ss->remarks,
                        'actual_time_in' => $dateActuals['actual_time_in'],
                        'actual_time_out'=> $dateActuals['actual_time_out'],
                        'date'           => $rowDate,
                        'store'          => $ss->store->name ?? '-',
                        'start_time'     => $ss->start_time,
                        'end_time'       => $ss->end_time,
                    ];
                }
            } else {
                // Legacy fallback: schedule has no schedule_stores entries
                $logs    = $scheduleLogs;
                $actualTimesByDate = $this->buildActualTimesByDate($logs);
                $rowDate = $schedule->start_time->copy()->timezone('Asia/Manila')->toDateString();
                $dateActuals = $actualTimesByDate[$rowDate] ?? [
                    'actual_time_in' => $logs->firstWhere('type', 'time_in')?->log_time,
                    'actual_time_out' => $logs->filter(fn($l) => $l->type === 'time_out')->last()?->log_time,
                ];

                $rows[] = [
                    'user'           => $schedule->user->name,
                    'status'         => $schedule->status,
                    'pickup_start'   => $schedule->pickup_start,
                    'pickup_end'     => $schedule->pickup_end,
                    'backlogs_start' => $schedule->backlogs_start,
                    'backlogs_end'   => $schedule->backlogs_end,
                    'remarks'        => $schedule->remarks,
                    'actual_time_in' => $dateActuals['actual_time_in'],
                    'actual_time_out'=> $dateActuals['actual_time_out'],
                    'date'           => $rowDate,
                    'store'          => '-',
                    'start_time'     => $schedule->start_time,
                    'end_time'       => $schedule->end_time,
                ];
            }
        }

        $groupedRows = collect($rows)->groupBy('date')->sortKeys();

        $pdf = Pdf::loadView('pdf.schedules', [
            'groupedRows' => $groupedRows
        ]);

        return $pdf->setPaper('a4', 'landscape')->stream('scheduling-report.pdf');
    }

    private function reportPdf(Request $request)
    {
        $pivotStatuses = ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'];
        $selectedYearsInput = $request->input('report_years');
        $selectedYears = $selectedYearsInput
            ? collect((array) $selectedYearsInput)->map(fn ($y) => (int) $y)->unique()->sort()->values()->toArray()
            : [now()->year - 1, now()->year, now()->year + 1];

        $pivotUsersQuery = User::whereNotNull('sub_unit')->orderBy('sub_unit')->orderBy('name');
        if ($request->filled('sub_unit')) {
            $pivotUsersQuery->where('sub_unit', $request->sub_unit);
        }
        $pivotUsers = $pivotUsersQuery->get(['id', 'name', 'sub_unit']);
        $pivotUserIds = $pivotUsers->pluck('id')->toArray();

        if (empty($pivotUserIds) || empty($selectedYears)) {
            $pdf = Pdf::loadView('pdf.schedules-report', [
                'pivotYears' => $selectedYears,
                'pivotStatuses' => $pivotStatuses,
                'pivotData' => [],
                'filters' => [
                    'sub_unit' => $request->input('sub_unit'),
                    'store_id' => $request->input('store_id'),
                ],
            ]);

            return $pdf->setPaper('a4', 'landscape')->stream('scheduling-report-view.pdf');
        }

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

        $grouped = $rawQuery
            ->groupBy('user_id', DB::raw('YEAR(start_time)'), 'status')
            ->get()
            ->groupBy('user_id');

        $pivotData = [];
        foreach ($pivotUsers as $user) {
            $byYear = $grouped->get($user->id, collect())->groupBy('year');
            $rowData = ['unit' => $user->sub_unit, 'name' => $user->name, 'years' => []];

            foreach ($selectedYears as $year) {
                $yearRows = $byYear->get((string) $year, collect());
                $yearCounts = [];
                foreach ($pivotStatuses as $status) {
                    $yearCounts[$status] = (int) ($yearRows->firstWhere('status', $status)?->day_count ?? 0);
                }
                $rowData['years'][$year] = $yearCounts;
            }

            $pivotData[] = $rowData;
        }

        $pdf = Pdf::loadView('pdf.schedules-report', [
            'pivotYears' => $selectedYears,
            'pivotStatuses' => $pivotStatuses,
            'pivotData' => $pivotData,
            'filters' => [
                'sub_unit' => $request->input('sub_unit'),
                'store_id' => $request->input('store_id'),
            ],
        ]);

        return $pdf->setPaper('a4', 'landscape')->stream('scheduling-report-view.pdf');
    }
}
