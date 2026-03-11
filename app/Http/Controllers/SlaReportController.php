<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Ticket;
use App\Models\TicketSlaMetric;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SlaReportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:reports.sla_performance', only: ['index', 'pdf']),
        ];
    }

    public function index(Request $request)
    {
        $data = $this->getReportData($request);

        return Inertia::render('Reports/SlaPerformance', [
            'reportData' => $data['reportData'],
            'users' => $data['users'],
            'subUnits' => $data['subUnits'],
            'filters' => $data['filters']
        ]);
    }

    public function pdf(Request $request)
    {
        $data = $this->getReportData($request);

        $pdf = Pdf::loadView('pdf.sla-performance', [
            'reportData' => $data['reportData'],
            'dateRange' => Carbon::parse($data['filters']['start_date'])->format('M d, Y') . ' - ' . Carbon::parse($data['filters']['end_date'])->format('M d, Y'),
            'subUnit' => $data['filters']['sub_unit'],
        ]);

        return $pdf->setPaper('a4', 'portrait')->stream('sla-performance-report.pdf');
    }

    public function getTickets(Request $request)
    {
        $userId = $request->input('user_id');
        $type = $request->input('type'); // 'response' or 'resolution'
        $status = $request->input('status'); // 'met', 'breached', 'pending'
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = Ticket::query()
            ->join('ticket_sla_metrics', 'tickets.id', '=', 'ticket_sla_metrics.ticket_id')
            ->where('tickets.assignee_id', $userId)
            ->whereBetween('tickets.created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay()
            ]);

        if ($type === 'response') {
            if ($status === 'met') {
                $query->whereNotNull('ticket_sla_metrics.first_response_at')->where('ticket_sla_metrics.is_response_breached', false);
            } elseif ($status === 'breached') {
                $query->where('ticket_sla_metrics.is_response_breached', true);
            } elseif ($status === 'pending') {
                $query->whereNull('ticket_sla_metrics.first_response_at')->where('ticket_sla_metrics.is_response_breached', false);
            }
        } else { // resolution
            if ($status === 'met') {
                $query->whereNotNull('ticket_sla_metrics.resolved_at')->where('ticket_sla_metrics.is_resolution_breached', false);
            } elseif ($status === 'breached') {
                $query->where('ticket_sla_metrics.is_resolution_breached', true);
            } elseif ($status === 'pending') {
                $query->whereNull('ticket_sla_metrics.resolved_at')->where('ticket_sla_metrics.is_resolution_breached', false);
            }
        }

        $tickets = $query->select('tickets.id', 'tickets.ticket_key', 'tickets.title', 'tickets.status', 'tickets.created_at')->latest('tickets.created_at')->get();

        return response()->json([
            'tickets' => $tickets
        ]);
    }

    protected function getReportData(Request $request)
    {
        $userId = $request->input('user_id', 'all');
        $subUnit = $request->input('sub_unit', 'all');
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('end_date', Carbon::now()->format('Y-m-d'));

        $query = TicketSlaMetric::query()
            ->join('tickets', 'ticket_sla_metrics.ticket_id', '=', 'tickets.id')
            ->join('users', 'tickets.assignee_id', '=', 'users.id')
            ->whereBetween('tickets.created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay()
            ]);

        if ($userId !== 'all') {
            $query->where('tickets.assignee_id', $userId);
        }

        if ($subUnit !== 'all') {
            $query->where('users.sub_unit', $subUnit);
        }

        $metrics = $query->select(
            'users.id as user_id',
            'users.name as user_name',
            'users.sub_unit',
            'ticket_sla_metrics.*'
        )->get();

        $reportData = $metrics->groupBy('user_id')->map(function ($userMetrics, $userId) {
            $total = $userMetrics->count();
            
            // Response Calculation
            $responseMet = $userMetrics->filter(function ($m) {
                return $m->first_response_at && !$m->is_response_breached;
            })->count();
            
            $responseBreached = $userMetrics->filter(function ($m) {
                return $m->is_response_breached;
            })->count();

            $responsePending = $userMetrics->filter(function ($m) {
                return !$m->first_response_at && !$m->is_response_breached;
            })->count();

            // Resolution Calculation
            $resolutionMet = $userMetrics->filter(function ($m) {
                return $m->resolved_at && !$m->is_resolution_breached;
            })->count();

            $resolutionBreached = $userMetrics->filter(function ($m) {
                return $m->is_resolution_breached;
            })->count();

            $resolutionPending = $userMetrics->filter(function ($m) {
                return !$m->resolved_at && !$m->is_resolution_breached;
            })->count();

            return [
                'user_id' => $userId,
                'user_name' => $userMetrics->first()->user_name,
                'sub_unit' => $userMetrics->first()->sub_unit,
                'total_tickets' => $total,
                'response' => [
                    'met' => $responseMet,
                    'breached' => $responseBreached,
                    'pending' => $responsePending,
                    'percentage' => $total > 0 ? round(($responseMet / ($total - $responsePending ?: 1)) * 100, 2) : 0
                ],
                'resolution' => [
                    'met' => $resolutionMet,
                    'breached' => $resolutionBreached,
                    'pending' => $resolutionPending,
                    'percentage' => $total > 0 ? round(($resolutionMet / ($total - $resolutionPending ?: 1)) * 100, 2) : 0
                ]
            ];
        })->values();

        $users = User::active()->whereHas('roles', function($q) {
            $q->where('is_assignable', true);
        })->select('id', 'name', 'sub_unit')->get();

        $subUnits = User::whereNotNull('sub_unit')->distinct()->pluck('sub_unit');

        return [
            'reportData' => $reportData,
            'users' => $users,
            'subUnits' => $subUnits,
            'filters' => [
                'user_id' => $userId,
                'sub_unit' => $subUnit,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]
        ];
    }
}
