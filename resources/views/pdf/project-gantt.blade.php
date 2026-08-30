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

        {{-- The summary ends the page. Everything below is the detailed breakdown,
             which stays dense so a milestone's activities and sub-tasks read as one
             table instead of being spread thin. --}}
        <div class="page-break"></div>
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
