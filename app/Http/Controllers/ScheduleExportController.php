<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ScheduleExportController extends Controller
{
    public function pdf(Request $request)
    {
        $query = Schedule::with(['user', 'store'])
            ->orderBy('start_time', 'asc');

        if ($request->filled('start') && $request->filled('end')) {
            $query->whereBetween('start_time', [
                Carbon::parse($request->start)->startOfDay(),
                Carbon::parse($request->end)->endOfDay()
            ]);
        }

        $schedules = $query->get();

        // Group by date
        $groupedSchedules = $schedules->groupBy(function($item) {
            return $item->start_time->format('Y-m-d');
        });

        $pdf = Pdf::loadView('pdf.schedules', [
            'groupedSchedules' => $groupedSchedules
        ]);

        return $pdf->setPaper('a4', 'landscape')->stream('scheduling-report.pdf');
    }
}
