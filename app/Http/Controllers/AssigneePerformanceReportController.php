<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AssigneePerformanceReportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:reports.assignee_performance', only: ['index', 'pdf']),
        ];
    }

    public function index(Request $request)
    {
        $data = $this->getReportData($request);

        return Inertia::render('Reports/AssigneePerformance', [
            'reportData' => $data['reportData'],
            'users'      => $data['users'],
            'subUnits'   => $data['subUnits'],
            'filters'    => $data['filters'],
        ]);
    }

    public function pdf(Request $request)
    {
        $data = $this->getReportData($request);

        $pdf = Pdf::loadView('pdf.assignee-performance', [
            'reportData' => $data['reportData'],
            'dateRange'  => Carbon::parse($data['filters']['start_date'])->format('M d, Y') . ' - ' . Carbon::parse($data['filters']['end_date'])->format('M d, Y'),
            'subUnit'    => $data['filters']['sub_unit'],
            'userName'   => $data['filters']['user_id'] !== 'all'
                ? optional(User::find($data['filters']['user_id']))->name
                : null,
        ]);

        return $pdf->setPaper('a4', 'landscape')->stream('assignee-performance-report.pdf');
    }

    protected function getReportData(Request $request): array
    {
        $userId    = $request->input('user_id', 'all');
        $subUnit   = $request->input('sub_unit', 'all');
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $endDate   = $request->input('end_date', Carbon::now()->format('Y-m-d'));

        $query = Ticket::query()
            ->whereNotNull('tickets.assignee_id')
            ->join('users', 'tickets.assignee_id', '=', 'users.id')
            ->leftJoin('ticket_sla_metrics', 'ticket_sla_metrics.ticket_id', '=', 'tickets.id')
            ->leftJoin('ticket_surveys', 'ticket_surveys.ticket_id', '=', 'tickets.id')
            ->whereBetween('tickets.created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ]);

        if ($userId !== 'all') {
            $query->where('tickets.assignee_id', $userId);
        }

        if ($subUnit !== 'all') {
            $query->where('users.sub_unit', $subUnit);
        }

        $rows = $query->select(
            'tickets.assignee_id as user_id',
            'users.name as user_name',
            'users.sub_unit',
            'tickets.status',
            'ticket_sla_metrics.first_response_at',
            'ticket_sla_metrics.is_response_breached',
            'ticket_sla_metrics.resolved_at',
            'ticket_sla_metrics.is_resolution_breached',
            'ticket_surveys.rating as survey_rating',
            'ticket_surveys.feedback as survey_feedback'
        )->get();

        $reportData = $rows->groupBy('user_id')->map(function ($userRows) {
            $total = $userRows->count();

            // SLA — Response
            $responseMet      = $userRows->filter(fn($r) => $r->first_response_at && !$r->is_response_breached)->count();
            $responseBreached = $userRows->filter(fn($r) => $r->is_response_breached)->count();
            $responsePending  = $userRows->filter(fn($r) => !$r->first_response_at && !$r->is_response_breached)->count();
            $responseBase     = $total - $responsePending ?: 1;

            // SLA — Resolution
            $resolutionMet      = $userRows->filter(fn($r) => $r->resolved_at && !$r->is_resolution_breached)->count();
            $resolutionBreached = $userRows->filter(fn($r) => $r->is_resolution_breached)->count();
            $resolutionPending  = $userRows->filter(fn($r) => !$r->resolved_at && !$r->is_resolution_breached)->count();
            $resolutionBase     = $total - $resolutionPending ?: 1;

            // Closed / Resolved tickets
            $closedTickets = $userRows->whereIn('status', ['closed', 'resolved'])->count();

            // Survey
            $surveyed   = $userRows->whereNotNull('survey_rating');
            $surveyTotal = $surveyed->count();
            $avgRating  = $surveyTotal > 0 ? round($surveyed->avg('survey_rating'), 2) : 0;
            
            $feedbacks = $userRows->whereNotNull('survey_feedback')
                ->map(fn($r) => [
                    'rating' => $r->survey_rating,
                    'text'   => $r->survey_feedback,
                    'date'   => $r->resolved_at ? Carbon::parse($r->resolved_at)->format('M d, Y') : null,
                ])->values();

            return [
                'user_id'        => $userRows->first()->user_id,
                'user_name'      => $userRows->first()->user_name,
                'sub_unit'       => $userRows->first()->sub_unit,
                'total_tickets'  => $total,
                'closed_tickets' => $closedTickets,
                'sla'            => [
                    'response' => [
                        'met'        => $responseMet,
                        'breached'   => $responseBreached,
                        'pending'    => $responsePending,
                        'percentage' => $total > 0 ? round(($responseMet / $responseBase) * 100, 2) : 0,
                    ],
                    'resolution' => [
                        'met'        => $resolutionMet,
                        'breached'   => $resolutionBreached,
                        'pending'    => $resolutionPending,
                        'percentage' => $total > 0 ? round(($resolutionMet / $resolutionBase) * 100, 2) : 0,
                    ],
                ],
                'survey' => [
                    'total'     => $surveyTotal,
                    'avg_rating'=> $avgRating,
                    'excellent' => $surveyed->where('survey_rating', 4)->count(),
                    'good'      => $surveyed->where('survey_rating', 3)->count(),
                    'fair'      => $surveyed->where('survey_rating', 2)->count(),
                    'poor'      => $surveyed->where('survey_rating', 1)->count(),
                    'feedbacks' => $feedbacks,
                ],
            ];
        })->values();

        $users = User::active()
            ->whereHas('roles', fn($q) => $q->where('is_assignable', true))
            ->select('id', 'name', 'sub_unit')
            ->orderBy('name')
            ->get();

        $subUnits = User::whereNotNull('sub_unit')->distinct()->orderBy('sub_unit')->pluck('sub_unit');

        return [
            'reportData' => $reportData,
            'users'      => $users,
            'subUnits'   => $subUnits,
            'filters'    => [
                'user_id'    => $userId,
                'sub_unit'   => $subUnit,
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ],
        ];
    }
}
