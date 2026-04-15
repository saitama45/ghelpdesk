<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ScheduleExportController extends Controller
{
    public function pdf(Request $request)
    {
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
        $logsBySegment  = $attendanceLogs->whereNotNull('schedule_store_id')->groupBy('schedule_store_id');

        // Flatten to one row per store visit
        $rows = [];
        foreach ($schedules as $schedule) {
            if ($schedule->scheduleStores->isNotEmpty()) {
                foreach ($schedule->scheduleStores as $ss) {
                    $segLogs = $logsBySegment->get($ss->id, collect());
                    $timeIn  = $segLogs->firstWhere('type', 'time_in');
                    $timeOut = $segLogs->filter(fn($l) => $l->type === 'time_out')->last();

                    $rows[] = [
                        'user'           => $schedule->user->name,
                        'status'         => $schedule->status,
                        'pickup_start'   => $schedule->pickup_start,
                        'pickup_end'     => $schedule->pickup_end,
                        'backlogs_start' => $schedule->backlogs_start,
                        'backlogs_end'   => $schedule->backlogs_end,
                        'remarks'        => $ss->remarks,
                        'actual_time_in' => $timeIn?->log_time,
                        'actual_time_out'=> $timeOut?->log_time,
                        'date'           => $ss->start_time->format('Y-m-d'),
                        'store'          => $ss->store->name ?? '-',
                        'start_time'     => $ss->start_time,
                        'end_time'       => $ss->end_time,
                    ];
                }
            } else {
                // Legacy fallback: schedule has no schedule_stores entries
                $logs    = $logsBySchedule->get($schedule->id, collect());
                $timeIn  = $logs->firstWhere('type', 'time_in');
                $timeOut = $logs->filter(fn($l) => $l->type === 'time_out')->last();

                $rows[] = [
                    'user'           => $schedule->user->name,
                    'status'         => $schedule->status,
                    'pickup_start'   => $schedule->pickup_start,
                    'pickup_end'     => $schedule->pickup_end,
                    'backlogs_start' => $schedule->backlogs_start,
                    'backlogs_end'   => $schedule->backlogs_end,
                    'remarks'        => $schedule->remarks,
                    'actual_time_in' => $timeIn?->log_time,
                    'actual_time_out'=> $timeOut?->log_time,
                    'date'           => $schedule->start_time->format('Y-m-d'),
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
}
