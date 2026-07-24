<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\Scopes\ActiveEntityScope;
use App\Services\BrandHealthService;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * The prototype's Executive master view ("President's WIG Executive Scorecard"):
 * an enterprise roll-up across every department of the ACTIVE entity, styled as a
 * WIG scorecard. ghelpdesk has no enterprise WIG-attainment data, so "attainment"
 * is the department's real ticket closure rate and every figure below is derived
 * from real service-delivery data — no fabricated financials. Strategic area /
 * key result are stable department descriptors.
 *
 * Gated to elevated cross-scope users (same gate as "I belong to → Executive").
 */
class ExecutiveController extends Controller
{
    private const CLOSED = ['resolved', 'closed'];

    /** Descriptive WIG focus per department (strategic area, primary key result). */
    private const WIG_DETAILS = [
        'Business Development' => ['Strategic Growth', 'Qualified expansion pipeline'],
        'Project Development' => ['Expansion Readiness', 'On-time development gates'],
        'Facilities Management' => ['Asset Reliability', 'Preventive maintenance completion'],
        'Marketing' => ['Brand Growth', 'Campaign reach and readiness'],
        'People and Organization' => ['People Readiness', 'Critical-role fulfilment'],
        'Organizational Wellness & Development' => ['Culture & Wellness', 'Program participation and actions'],
        'Leadership Development' => ['Leadership Pipeline', 'Program and successor coverage'],
        'Supply Chain Management' => ['Supply Reliability', 'Supplier OTIF and availability'],
        'Finance and Accounting' => ['Financial Excellence', 'Close and processing discipline'],
        'Technology and Solutions' => ['Digital Foundation', 'Reliability and transformation'],
        'Operations' => ['Operational Excellence', 'Service delivery consistency'],
    ];

    /** WIG pillar → member departments (for the pillar averages). */
    private const PILLARS = [
        'Strategic & Planning' => ['Business Development', 'Project Development', 'Marketing'],
        'Cost Management' => ['Finance and Accounting', 'Supply Chain Management', 'Facilities Management'],
        'People & Culture' => ['People and Organization', 'Organizational Wellness & Development', 'Leadership Development'],
        'Transformation' => ['Technology and Solutions', 'Operations'],
    ];

    private const PILLAR_NOTES = [
        'Strategic & Planning' => 'Growth, reliability, and customer experience',
        'Cost Management' => 'Asset, resource, and workflow efficiency',
        'People & Culture' => 'Capability, wellness, leadership, and readiness',
        'Transformation' => 'Digital capability, scalability, and adaptability',
    ];

    public function index(Request $request)
    {
        abort_unless($request->user()->can('dashboard.filter_entity'), 403);

        $user = $request->user();
        $companyId = CompanyContext::activeCompanyId();
        $company = $companyId ? Company::find($companyId) : null;
        $monthStart = Carbon::now('Asia/Manila')->startOfMonth();

        $departments = Department::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $ticket = fn () => Ticket::query()->withoutGlobalScope(ActiveEntityScope::class);

        $rows = $departments->map(function (Department $d) use ($ticket, $monthStart) {
            $base = fn () => (clone $ticket())->where('department_id', $d->id);
            $open = (clone $base())->whereNotIn('status', self::CLOSED)->count();
            $highUrgent = (clone $base())->whereNotIn('status', self::CLOSED)->whereIn('priority', ['high', 'urgent'])->count();
            $resolvedMtd = (clone $base())->whereIn('status', self::CLOSED)->where('updated_at', '>=', $monthStart)->count();
            $total = (clone $base())->count();
            $resolvedAll = (clone $base())->whereIn('status', self::CLOSED)->count();
            $attainment = $total > 0 ? (int) round(($resolvedAll / $total) * 100) : 0;
            [$area, $result] = self::WIG_DETAILS[$d->name] ?? ['Service Delivery', 'Timely resolution and quality'];

            $outlook = $highUrgent > 3 ? 'Needs Attention' : ($open > 30 ? 'Watch' : 'On Track');

            return [
                'id' => $d->id,
                'name' => $d->name,
                'code' => $d->code,
                'attainment' => $attainment,
                'strategic_area' => $area,
                'key_result' => $result,
                'evidence' => $open . ' open · ' . $highUrgent . ' priority · ' . $resolvedMtd . ' resolved MTD',
                'open' => $open,
                'high_urgent' => $highUrgent,
                'outlook' => $outlook,
            ];
        })->values();

        $withWork = $rows->where('open', '>', 0)->merge($rows->where('attainment', '>', 0))->unique('id');
        $enterpriseWig = $withWork->count() ? (int) round($withWork->avg('attainment')) : 0;
        $onTrack = $rows->where('outlook', 'On Track')->count();
        $needsAttention = $rows->where('outlook', 'Needs Attention')->count();
        $totalOpen = $rows->sum('open');

        // WIG pillars: average attainment of member departments present in this entity.
        $pillars = collect(self::PILLARS)->map(function ($members, $name) use ($rows) {
            $memberRows = $rows->whereIn('name', $members);
            return [
                'name' => $name,
                'value' => $memberRows->count() ? (int) round($memberRows->avg('attainment')) : 0,
                'note' => self::PILLAR_NOTES[$name] ?? '',
            ];
        })->values();

        $brandTotals = app(BrandHealthService::class)->build($user)['totals'] ?? [];
        $activeProjects = Project::whereNotIn('status', ['Completed', 'Cancelled'])->count();

        // Portfolio outlook from real project statuses.
        $projectStatuses = Project::query()
            ->selectRaw("status, COUNT(*) as c")->groupBy('status')->pluck('c', 'status');
        $portfolio = [
            ['label' => 'On Schedule', 'value' => (int) ($projectStatuses['In Progress'] ?? 0), 'tone' => 'green'],
            ['label' => 'Planning', 'value' => (int) ($projectStatuses['Planning'] ?? 0) + (int) ($projectStatuses['Pending'] ?? 0), 'tone' => 'blue'],
            ['label' => 'Watch', 'value' => (int) ($projectStatuses['Delayed'] ?? 0), 'tone' => 'amber'],
            ['label' => 'Completed', 'value' => (int) ($projectStatuses['Completed'] ?? 0), 'tone' => 'green'],
        ];

        // President's attention agenda = departments flagged for attention/watch.
        $agenda = $rows->whereIn('outlook', ['Needs Attention', 'Watch'])
            ->sortByDesc('high_urgent')
            ->take(5)
            ->map(fn ($r) => [
                'code' => $r['code'] ?: $r['name'],
                'item' => $r['high_urgent'] > 0 ? $r['high_urgent'] . ' high/urgent tickets need action' : $r['open'] . ' open items to review',
                'status' => $r['outlook'] === 'Needs Attention' ? 'Decision' : 'Review',
                'attainment' => $r['attainment'],
            ])->values();

        return Inertia::render('Executive/Overview', [
            'entity' => [
                'code' => $company?->code ?? '—',
                'name' => $company?->name ?? 'Enterprise',
            ],
            'period' => Carbon::now('Asia/Manila')->format('F Y') . ' · MTD',
            'kpis' => [
                ['label' => 'Enterprise WIG', 'value' => $enterpriseWig . '%', 'note' => 'Avg closure attainment', 'tone' => 'teal'],
                ['label' => 'On-Track Departments', 'value' => $onTrack . ' of ' . $rows->count(), 'note' => 'Service delivery', 'tone' => 'green'],
                ['label' => 'Strategic Priorities', 'value' => $activeProjects, 'note' => 'Active projects', 'tone' => 'blue'],
                ['label' => 'Open Work', 'value' => $totalOpen, 'note' => 'Across departments', 'tone' => 'blue'],
                ['label' => 'Executive Attention', 'value' => $needsAttention, 'note' => 'Departments flagged', 'tone' => $needsAttention > 0 ? 'red' : 'green'],
            ],
            'pillars' => $pillars,
            'departments' => $rows,
            'portfolio' => $portfolio,
            'agenda' => $agenda,
            'brandHealth' => $brandTotals,
        ]);
    }
}
