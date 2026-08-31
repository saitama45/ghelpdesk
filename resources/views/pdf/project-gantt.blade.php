<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $project->name }} - Gantt Progress</title>
    <style>
        @page { margin: 24px 28px 28px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 8px; }
        .header { border-bottom: 3px solid #4f46e5; padding-bottom: 10px; margin-bottom: 12px; }
        .header-table, .summary, .milestone-table { width: 100%; border-collapse: collapse; }
        h1 { margin: 0; font-size: 22px; color: #111827; }
        .eyebrow { color: #4f46e5; font-weight: bold; letter-spacing: 1.2px; text-transform: uppercase; font-size: 7px; }
        .meta { margin-top: 6px; color: #64748b; font-size: 8px; }

        /* Entity / Brand / Store chips. Laid out as table cells rather than
           inline-blocks or floats — dompdf sizes table cells to their content
           reliably, and its float handling is what put the timeline's end date on
           the wrong side of the header. */
        .chips { border-collapse: collapse; margin-top: 8px; }
        .chips td { border: 0; padding: 0 7px 0 0; vertical-align: top; }
        .chips-filler { width: 100%; }
        .chip { padding: 9px 15px; border-radius: 3px; border-left: 5px solid; white-space: nowrap; }
        .chip-key { display: block; margin-bottom: 2px; font-size: 7px; font-weight: bold; letter-spacing: 1.3px; text-transform: uppercase; }
        .chip-value { font-size: 19px; font-weight: bold; }
        .chip-sub { margin-left: 4px; font-size: 8px; color: #475569; }
        .chip-entity { background: #eef2ff; border-left-color: #4f46e5; }
        .chip-entity .chip-key { color: #6366f1; }
        .chip-entity .chip-value { color: #3730a3; }
        .chip-brand { background: #fff7ed; border-left-color: #ea580c; }
        .chip-brand .chip-key { color: #f97316; }
        .chip-brand .chip-value { color: #9a3412; }
        .chip-store { background: #ecfdf5; border-left-color: #059669; }
        .chip-store .chip-key { color: #10b981; }
        .chip-store .chip-value { color: #065f46; }
        .overall { text-align: right; width: 150px; }
        .overall strong { display: block; color: #4f46e5; font-size: 30px; line-height: 1; }
        .overall span { color: #64748b; font-weight: bold; text-transform: uppercase; font-size: 7px; }
        .summary td { width: 16.66%; border: 1px solid #e2e8f0; padding: 8px; background: #f8fafc; }
        .metric-label { color: #64748b; font-size: 6px; font-weight: bold; text-transform: uppercase; }
        .metric-value { margin-top: 2px; font-size: 13px; font-weight: bold; color: #1e293b; }
        .section-title { margin: 14px 0 6px; font-size: 10px; font-weight: bold; text-transform: uppercase; color: #334155; }

        /* Milestone progress owns the summary page on its own, so it is set large
           and airy: one milestone per full-width line rather than columns squeezed
           side by side. Everything after the page break is detail and stays dense. */
        .section-title-lead { margin: 26px 0 14px; font-size: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: 1.4px; color: #334155; }
        .milestone-line { border: 1px solid #ddd6fe; background: #f5f3ff; padding: 18px 20px; margin-bottom: 14px; page-break-inside: avoid; }
        .milestone-line-head { width: 100%; border-collapse: collapse; }
        .milestone-line-head td { border: 0; padding: 0; vertical-align: middle; }
        .milestone-line-name { font-size: 16px; font-weight: bold; color: #4338ca; }
        .milestone-line-meta { margin-top: 6px; color: #64748b; font-size: 10px; }
        .milestone-line-percent { width: 130px; text-align: right; font-size: 30px; font-weight: bold; color: #4f46e5; }
        .progress-track-lead { height: 13px; background: #e2e8f0; margin-top: 14px; }
        .progress-fill-lead { height: 13px; background: #4f46e5; }
        .page-break { page-break-after: always; }
        .milestone { margin-top: 12px; page-break-inside: avoid; }
        .milestone-header { background: #312e81; color: white; padding: 7px 9px; font-weight: bold; font-size: 9px; }
        .milestone-header .right { float: right; }
        .milestone-table { table-layout: fixed; }
        .milestone-table th { background: #eef2ff; color: #475569; text-transform: uppercase; font-size: 6px; letter-spacing: .4px; padding: 5px 4px; border: 1px solid #dbeafe; }
        .milestone-table td { padding: 5px 4px; border: 1px solid #e5e7eb; vertical-align: middle; }
        .milestone-table tr:nth-child(even) td { background: #fafafa; }
        .activity { font-weight: bold; }
        .subtask { padding-left: 14px; color: #475569; }
        .sub-prefix { color: #8b5cf6; font-weight: bold; }
        .center { text-align: center; }
        .status { border-radius: 8px; padding: 2px 5px; font-size: 6px; font-weight: bold; text-transform: uppercase; }
        .done { background: #dcfce7; color: #166534; }
        .ongoing { background: #dbeafe; color: #1d4ed8; }
        .pending { background: #f1f5f9; color: #475569; }
        .delayed, .blocked { background: #fee2e2; color: #991b1b; }
        /* Higher specificity than `.milestone-table td`, which would otherwise put a
           border and padding on these nested cells. */
        .milestone-table .timeline-head { width: 100%; border-collapse: collapse; margin-top: 2px; }
        .milestone-table .timeline-head td { border: 0; padding: 0; font-size: 6px; font-weight: normal; color: #64748b; }
        .milestone-table .timeline-head .timeline-start { text-align: left; }
        .milestone-table .timeline-head .timeline-end { text-align: right; }
        .track { position: relative; height: 13px; background: #f1f5f9; border-left: 1px solid #cbd5e1; border-right: 1px solid #cbd5e1; }
        .bar { position: absolute; top: 3px; height: 7px; min-width: 2px; background: #6366f1; }
        .bar-done { background: #10b981; }
        .bar-delayed, .bar-blocked { background: #ef4444; }
        .bar-progress { height: 7px; background: rgba(15, 23, 42, .18); }
        .footer { position: fixed; bottom: -18px; left: 0; right: 0; color: #94a3b8; font-size: 6px; border-top: 1px solid #e2e8f0; padding-top: 4px; }
        .footer .right { float: right; }
    </style>
</head>
<body>
    <div class="footer">
        Presentation Gantt generated {{ $generatedAt->format('M d, Y g:i A') }}
        <span class="right">{{ $project->name }} · Progress {{ $project->progress_percentage }}%</span>
    </div>

    <div class="header">
        <table class="header-table"><tr>
            <td>
                <div class="eyebrow">Project Progress Presentation</div>
                <h1>{{ $project->name }}</h1>
                {{-- Entity and Brand are what a reader looks for first on a
                     presentation deck, so they are pulled out of the grey meta line
                     into colour-coded chips instead of being buried in it. --}}
                <table class="chips"><tr>
                    @if($project->entityCompany)
                        <td><div class="chip chip-entity">
                            <span class="chip-key">Entity</span>
                            <span class="chip-value">{{ $project->entityCompany->code ?: $project->entityCompany->name }}</span>
                            {{-- The full name only when it says something the code does not:
                                 several companies are recorded with the code as their name. --}}
                            @if($project->entityCompany->code && $project->entityCompany->name && strcasecmp($project->entityCompany->code, $project->entityCompany->name) !== 0)
                                <span class="chip-sub">{{ $project->entityCompany->name }}</span>
                            @endif
                        </div></td>
                    @endif
                    @if($project->brandCompany)
                        <td><div class="chip chip-brand">
                            <span class="chip-key">Brand</span>
                            <span class="chip-value">{{ $project->brandCompany->code ?: $project->brandCompany->name }}</span>
                            {{-- The full name only when it says something the code does not:
                                 several companies are recorded with the code as their name. --}}
                            @if($project->brandCompany->code && $project->brandCompany->name && strcasecmp($project->brandCompany->code, $project->brandCompany->name) !== 0)
                                <span class="chip-sub">{{ $project->brandCompany->name }}</span>
                            @endif
                        </div></td>
                    @endif
                    @if($project->store)
                        <td><div class="chip chip-store">
                            <span class="chip-key">Store</span>
                            <span class="chip-value">{{ $project->store->name }}</span>
                        </div></td>
                    @endif
                    <td class="chips-filler"></td>
                </tr></table>

                <div class="meta">
                    {{ $project->project_type }}
                    · Timeline {{ $timelineStart->format('M d, Y') }} – {{ $timelineEnd->format('M d, Y') }}
                </div>
            </td>
            <td class="overall">
                <strong>{{ $project->progress_percentage }}%</strong>
                <span>Overall completion</span>
            </td>
        </tr></table>
    </div>

    <table class="summary"><tr>
        <td><div class="metric-label">Status</div><div class="metric-value">{{ $project->status }}</div></td>
        <td><div class="metric-label">Milestones</div><div class="metric-value">{{ $milestones->count() }}</div></td>
        <td><div class="metric-label">Activities</div><div class="metric-value">{{ $activityCount }}</div></td>
        <td><div class="metric-label">Sub-tasks</div><div class="metric-value">{{ $subTaskCount }}</div></td>
        <td><div class="metric-label">Done</div><div class="metric-value">{{ $statusCounts->get('Done', 0) }}</div></td>
        <td><div class="metric-label">In progress</div><div class="metric-value">{{ $statusCounts->get('Ongoing', 0) + $statusCounts->get('In Progress', 0) }}</div></td>
    </tr></table>

    @if($milestones->isNotEmpty())
        <div class="section-title-lead">Milestone progress</div>
        @foreach($milestones as $milestone)
            <div class="milestone-line">
                <table class="milestone-line-head"><tr>
                    <td>
                        <div class="milestone-line-name">{{ $milestone['name'] }}</div>
                        <div class="milestone-line-meta">
                            {{ $milestone['rows']->count() }} rows
                            @if($milestone['weight'] > 0) · {{ number_format($milestone['weight'], 0) }}% weight @endif
                        </div>
                    </td>
                    <td class="milestone-line-percent">{{ $milestone['progress'] }}%</td>
                </tr></table>
                <div class="progress-track-lead"><div class="progress-fill-lead" style="width:{{ $milestone['progress'] }}%"></div></div>
            </div>
        @endforeach

        <div class="page-break"></div>
    @endif

    {{-- PAGE 2: Weekly Horizon, Progress & Movements Report --}}
    @if(isset($weeklyReport))
        <div class="weekly-page">
            <div class="weekly-header" style="border-bottom:2px solid #4f46e5;padding-bottom:6px;margin-bottom:8px;">
                <table class="header-table"><tr>
                    <td>
                        <div class="eyebrow" style="color:#4f46e5;">Executive Weekly Horizon &amp; Movement Report</div>
                        <h2 style="margin:2px 0 0;font-size:15px;color:#1e293b;">
                            {{ $weeklyReport['activeWeek']['label'] }} Executive Review
                            <span style="font-size:9px;font-weight:normal;color:#64748b;">({{ $weeklyReport['activeWeek']['formattedRange'] }})</span>
                        </h2>
                    </td>
                    <td style="text-align:right;vertical-align:bottom;">
                        <span style="display:inline-block;padding:3px 7px;background:#e0e7ff;color:#3730a3;font-weight:bold;font-size:8px;border-radius:3px;">
                            WoW Growth: {{ $weeklyReport['wowActualDelta'] >= 0 ? '+' : '' }}{{ $weeklyReport['wowActualDelta'] }}%
                        </span>
                    </td>
                </tr></table>
            </div>

            <!-- KPI Cards -->
            <table style="width:100%;margin-top:6px;border-collapse:collapse;">
                <tr>
                    <td style="width:20%;border:1px solid #cbd5e1;background:#f8fafc;padding:6px 8px;">
                        <div class="metric-label">Prev Week Actual ({{ $weeklyReport['prevWeek']['label'] ?? 'W0' }})</div>
                        <div class="metric-value" style="font-size:15px;color:#475569;">{{ $weeklyReport['prevActual'] }}%</div>
                        <div style="font-size:6.5px;color:#94a3b8;margin-top:1px;">Previous week end status</div>
                    </td>
                    <td style="width:20%;border:1px solid #93c5fd;background:#eff6ff;padding:6px 8px;">
                        <div class="metric-label" style="color:#1d4ed8;">Current Week Actual</div>
                        <div class="metric-value" style="font-size:15px;color:#1d4ed8;">{{ $weeklyReport['currentActual'] }}%</div>
                        <div style="font-size:6.5px;color:#3b82f6;margin-top:1px;">
                            WoW Delta: <strong>{{ $weeklyReport['wowActualDelta'] >= 0 ? '+' : '' }}{{ $weeklyReport['wowActualDelta'] }}%</strong>
                        </div>
                    </td>
                    <td style="width:20%;border:1px solid #cbd5e1;background:#f8fafc;padding:6px 8px;">
                        <div class="metric-label">Current Planned Target</div>
                        <div class="metric-value" style="font-size:15px;color:#334155;">{{ $weeklyReport['currentPlanned'] }}%</div>
                        <div style="font-size:6.5px;color:#64748b;margin-top:1px;">Baseline S-Curve target</div>
                    </td>
                    <td style="width:20%;border:1px solid #cbd5e1;background:#f8fafc;padding:6px 8px;">
                        <div class="metric-label">Schedule Variance</div>
                        <div class="metric-value" style="font-size:15px;color:{{ $weeklyReport['variance'] >= 0 ? '#166534' : '#991b1b' }};">
                            {{ $weeklyReport['variance'] >= 0 ? '+' : '' }}{{ $weeklyReport['variance'] }}%
                        </div>
                        <div style="font-size:6.5px;color:{{ $weeklyReport['variance'] >= 0 ? '#16a34a' : '#dc2626' }};margin-top:1px;font-weight:bold;">
                            {{ $weeklyReport['variance'] >= 0 ? 'Ahead of schedule' : 'Behind schedule' }}
                        </div>
                    </td>
                    <td style="width:20%;border:1px solid #cbd5e1;background:#f8fafc;padding:6px 8px;">
                        <div class="metric-label">Week Deliverables</div>
                        <div class="metric-value" style="font-size:15px;color:#1e293b;">{{ $weeklyReport['activeThisWeek']->count() + $weeklyReport['completedThisWeek']->count() }}</div>
                        <div style="font-size:6.5px;color:#64748b;margin-top:1px;">
                            <strong style="color:#166534;">{{ $weeklyReport['completedThisWeek']->count() }} completed</strong>
                        </div>
                    </td>
                </tr>
            </table>

            <!-- Milestone WoW Comparison Table -->
            <div style="margin-top:10px;">
                <div style="font-size:9px;font-weight:bold;text-transform:uppercase;color:#334155;margin-bottom:4px;letter-spacing:0.5px;">
                    Milestone Progress Movements (Previous vs Current Week)
                </div>
                <table style="width:100%;border-collapse:collapse;table-layout:fixed;">
                    <thead>
                        <tr style="background:#eef2ff;">
                            <th style="width:30%;text-align:left;padding:4px 6px;border:1px solid #c7d2fe;font-size:6.5px;color:#3730a3;text-transform:uppercase;">Milestone Category</th>
                            <th style="width:12%;text-align:center;padding:4px 6px;border:1px solid #c7d2fe;font-size:6.5px;color:#3730a3;text-transform:uppercase;">Weight</th>
                            <th style="width:14%;text-align:center;padding:4px 6px;border:1px solid #c7d2fe;font-size:6.5px;color:#3730a3;text-transform:uppercase;">{{ $weeklyReport['prevWeek']['label'] ?? 'Prev Wk' }} Actual</th>
                            <th style="width:14%;text-align:center;padding:4px 6px;border:1px solid #c7d2fe;font-size:6.5px;color:#3730a3;text-transform:uppercase;">Current Actual</th>
                            <th style="width:14%;text-align:center;padding:4px 6px;border:1px solid #c7d2fe;font-size:6.5px;color:#3730a3;text-transform:uppercase;">WoW Movement</th>
                            <th style="width:16%;text-align:center;padding:4px 6px;border:1px solid #c7d2fe;font-size:6.5px;color:#3730a3;text-transform:uppercase;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($weeklyReport['milestoneComparison'] as $mRow)
                            <tr style="border-bottom:1px solid #e2e8f0;background:{{ $loop->even ? '#fafafa' : '#ffffff' }};">
                                <td style="padding:4px 6px;border:1px solid #e5e7eb;font-weight:bold;color:#1e293b;">{{ $mRow['name'] }}</td>
                                <td style="padding:4px 6px;border:1px solid #e5e7eb;text-align:center;color:#64748b;">{{ $mRow['weight'] > 0 ? number_format($mRow['weight'], 0).'%' : '-' }}</td>
                                <td style="padding:4px 6px;border:1px solid #e5e7eb;text-align:center;color:#64748b;">{{ $mRow['prev_actual'] }}%</td>
                                <td style="padding:4px 6px;border:1px solid #e5e7eb;text-align:center;font-weight:bold;color:#1e293b;">{{ $mRow['current_actual'] }}%</td>
                                <td style="padding:4px 6px;border:1px solid #e5e7eb;text-align:center;">
                                    <span style="font-weight:bold;color:{{ $mRow['delta'] > 0 ? '#16a34a' : ($mRow['delta'] < 0 ? '#dc2626' : '#64748b') }};">
                                        {{ $mRow['delta'] > 0 ? '+' : '' }}{{ $mRow['delta'] }}%
                                    </span>
                                </td>
                                <td style="padding:4px 6px;border:1px solid #e5e7eb;text-align:center;">
                                    @if($mRow['current_actual'] >= 100)
                                        <span class="status done">Completed</span>
                                    @elseif($mRow['delta'] > 0)
                                        <span class="status ongoing" style="background:#dcfce7;color:#15803d;">+{{ $mRow['delta'] }}% Moved</span>
                                    @elseif($mRow['current_actual'] > 0)
                                        <span class="status ongoing">Ongoing</span>
                                    @else
                                        <span class="status pending">Pending</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Weekly Highlights & Movements (3-Pillar Table) -->
            <div style="margin-top:10px;">
                <div style="font-size:9px;font-weight:bold;text-transform:uppercase;color:#334155;margin-bottom:4px;letter-spacing:0.5px;">
                    Weekly Activity Highlights &amp; Movement Stream
                </div>
                <table style="width:100%;border-collapse:collapse;table-layout:fixed;">
                    <tr>
                        <!-- Column 1: Completed This Week -->
                        <td style="width:33.33%;vertical-align:top;border:1px solid #bbf7d0;background:#f0fdf4;padding:6px 8px;">
                            <div style="font-size:7.5px;font-weight:bold;color:#166534;text-transform:uppercase;border-bottom:1px solid #bbf7d0;padding-bottom:2px;margin-bottom:4px;">
                                Completed This Week ({{ $weeklyReport['completedThisWeek']->count() }})
                            </div>
                            @forelse($weeklyReport['completedThisWeek'] as $doneTask)
                                <div style="margin-bottom:4px;padding-bottom:3px;border-bottom:1px dashed #dcfce7;font-size:7px;">
                                    <div style="font-weight:bold;color:#14532d;">✓ {{ $doneTask->name }}</div>
                                    <div style="color:#64748b;font-size:6px;margin-top:1px;">
                                        {{ $doneTask->category ?: 'General' }} · {{ $doneTask->assignedUser?->name ?: $doneTask->external_assignment ?: 'Unassigned' }}
                                    </div>
                                </div>
                            @empty
                                <div style="color:#64748b;font-style:italic;font-size:6.5px;">No tasks finalized during this week window.</div>
                            @endforelse
                        </td>

                        <!-- Column 2: In Progress / Active This Week -->
                        <td style="width:33.33%;vertical-align:top;border:1px solid #bfdbfe;background:#eff6ff;padding:6px 8px;">
                            <div style="font-size:7.5px;font-weight:bold;color:#1e40af;text-transform:uppercase;border-bottom:1px solid #bfdbfe;padding-bottom:2px;margin-bottom:4px;">
                                In Progress / Active ({{ $weeklyReport['activeThisWeek']->count() }})
                            </div>
                            @forelse($weeklyReport['activeThisWeek'] as $actTask)
                                <div style="margin-bottom:4px;padding-bottom:3px;border-bottom:1px dashed #dbeafe;font-size:7px;">
                                    <div style="font-weight:bold;color:#1e3a8a;">
                                        ⟳ {{ $actTask->name }}
                                        <span style="float:right;color:#2563eb;">{{ $actTask->progress }}%</span>
                                    </div>
                                    <div style="color:#64748b;font-size:6px;margin-top:1px;">
                                        {{ $actTask->category ?: 'General' }} · {{ $actTask->assignedUser?->name ?: $actTask->external_assignment ?: 'Unassigned' }}
                                    </div>
                                </div>
                            @empty
                                <div style="color:#64748b;font-style:italic;font-size:6.5px;">No active in-progress activities recorded.</div>
                            @endforelse
                        </td>

                        <!-- Column 3: Critical / Overdue / Attention -->
                        <td style="width:33.33%;vertical-align:top;border:1px solid #fecdd3;background:#fff1f2;padding:6px 8px;">
                            <div style="font-size:7.5px;font-weight:bold;color:#9f1239;text-transform:uppercase;border-bottom:1px solid #fecdd3;padding-bottom:2px;margin-bottom:4px;">
                                Critical / Overdue / Flags ({{ $weeklyReport['criticalOrOverdue']->count() }})
                            </div>
                            @forelse($weeklyReport['criticalOrOverdue'] as $critTask)
                                <div style="margin-bottom:4px;padding-bottom:3px;border-bottom:1px dashed #ffe4e6;font-size:7px;">
                                    <div style="font-weight:bold;color:#881337;">
                                        ⚠ {{ $critTask->name }}
                                        @if($critTask->manual_status)
                                            <span style="float:right;background:#fda4af;color:#881337;padding:1px 3px;border-radius:2px;font-size:5.5px;font-weight:bold;">
                                                {{ $critTask->manual_status }}
                                            </span>
                                        @else
                                            <span style="float:right;color:#e11d48;font-weight:bold;">{{ $critTask->progress }}%</span>
                                        @endif
                                    </div>
                                    <div style="color:#64748b;font-size:6px;margin-top:1px;">
                                        {{ $critTask->category ?: 'General' }} · {{ $critTask->end_date ? 'Due '.$critTask->end_date->format('M d') : 'Overdue' }}
                                    </div>
                                </div>
                            @empty
                                <div style="color:#166534;font-weight:bold;font-size:6.5px;">✓ All tasks on schedule. No overdue items.</div>
                            @endforelse
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Lookahead Next Week -->
            @if($weeklyReport['nextWeek'] && $weeklyReport['nextWeekTasks']->isNotEmpty())
                <div style="margin-top:8px;padding:5px 8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:3px;">
                    <span style="font-size:7.5px;font-weight:bold;color:#475569;text-transform:uppercase;">
                        Lookahead Focus for {{ $weeklyReport['nextWeek']['label'] }} ({{ $weeklyReport['nextWeek']['formattedRange'] }}):
                    </span>
                    <span style="font-size:7px;color:#334155;margin-left:4px;">
                        @foreach($weeklyReport['nextWeekTasks'] as $nwTask)
                            <strong>{{ $nwTask->name }}</strong> ({{ $nwTask->category }}){{ !$loop->last ? ' • ' : '' }}
                        @endforeach
                    </span>
                </div>
            @endif

            <div class="page-break"></div>
        </div>
    @endif

    @forelse($milestones as $milestone)
        <div class="milestone">
            <div class="milestone-header">
                {{ $milestone['name'] }}
                <span class="right">{{ $milestone['progress'] }}% complete · {{ $milestone['rows']->count() }} rows</span>
            </div>
            <table class="milestone-table">
                <thead><tr>
                    <th style="width:25%">Activity / Sub-task</th>
                    <th style="width:12%">Responsible</th>
                    <th style="width:8%">Status</th>
                    <th style="width:6%">Progress</th>
                    <th style="width:13%">Schedule</th>
                    <th style="width:36%">
                        <div>Timeline</div>
                        {{-- A two-cell table, not a floated span: dompdf pulled the
                             floated end date to the START of the line, so the last
                             date printed on the left of the axis it labels. --}}
                        <table class="timeline-head"><tr>
                            <td class="timeline-start">{{ $timelineStart->format('M d, Y') }}</td>
                            <td class="timeline-end">{{ $timelineEnd->format('M d, Y') }}</td>
                        </tr></table>
                    </th>
                </tr></thead>
                <tbody>
                    @foreach($milestone['rows'] as $row)
                        @php
                            $task = $row['task'];
                            $statusKey = strtolower(str_replace(' ', '-', $task->manual_status ?: $task->status));
                        @endphp
                        <tr>
                            <td class="{{ $row['depth'] ? 'subtask' : 'activity' }}">
                                @if($row['depth'])<span class="sub-prefix">↳ </span>@endif{{ $task->name }}
                            </td>
                            <td>{{ $task->assignedUser?->name ?: $task->external_assignment ?: 'Unassigned' }}</td>
                            <td class="center"><span class="status {{ $statusKey }}">{{ $task->manual_status ?: $task->status }}</span></td>
                            <td class="center"><strong>{{ $task->progress }}%</strong></td>
                            <td class="center">
                                @if($task->start_date && $task->end_date)
                                    {{ $task->start_date->format('M d') }} – {{ $task->end_date->format('M d') }}
                                @else
                                    Not scheduled
                                @endif
                            </td>
                            <td>
                                <div class="track">
                                    @if($row['width'] > 0)
                                        <div class="bar bar-{{ $statusKey }}" style="left:{{ $row['left'] }}%;width:{{ $row['width'] }}%">
                                            <div class="bar-progress" style="width:{{ $task->progress }}%"></div>
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <div style="padding:30px;text-align:center;color:#64748b">No project tasks are available for this presentation.</div>
    @endforelse
</body>
</html>
