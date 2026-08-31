<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Support\DepartmentContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the Projects → <type> → Overview tab: a portfolio read-out for one
 * project type (the NSO Workspace, when the type is Store Opening).
 *
 * Everything here is DERIVED from project_tasks — there is no approvals or
 * permits table. "Pending approval" and "permit" rows are recognised by the
 * activity names the templates already use (see APPROVAL_KEYWORDS), which is why
 * those numbers track /activity-templates wording rather than a workflow state.
 *
 * Scope is every project of the type INCLUDING Completed and Cancelled ones, so
 * the percentages describe the whole portfolio's history. The one exception is
 * the "Active Projects" KPI, which by definition counts only the live ones.
 *
 * Department attribution goes through ProjectTask::resolvedDepartment() — the
 * activity/sub-task's accountable department first, assignee department only as
 * a fallback when the row has no department.
 */
class ProjectOverviewService
{
    /** Statuses that mean a row/project needs no further work. */
    private const TERMINAL_STATUSES = ['Completed', 'Cancelled'];

    /** Activity names that stand in for an approval gate, lowercase. */
    private const APPROVAL_KEYWORDS = [
        'approval', 'approve', 'sign-off', 'sign off', 'signoff',
        'endorsement', 'endorse', 'clearance', 'permit',
    ];

    /** Narrower set used to call out compliance gates specifically. */
    private const PERMIT_KEYWORDS = ['permit', 'clearance', 'license', 'licence', 'occupancy'];

    /** How many rows the Project Health and Critical Alerts panels show. */
    private const HEALTH_LIMIT = 8;
    private const ALERT_LIMIT = 8;

    /** Rows carried in a drill-down modal before it says "showing N of M". */
    private const BREAKDOWN_LIMIT = 40;

    public function build(string $type, ?User $user = null): array
    {
        $today = CarbonImmutable::today();

        $projects = Project::query()
            ->where('project_type', $type)
            ->with(['store:id,name,area', 'subject'])
            // Pinned columns: projects.remarks is nvarchar(MAX) and drags the
            // whole list over the Azure link for no benefit here.
            ->get([
                'id', 'name', 'project_type', 'status', 'store_id',
                'subject_type', 'subject_id', 'target_go_live', 'day1_date', 'created_by',
            ]);

        if ($projects->isEmpty()) {
            return $this->emptyPayload($type, $user);
        }

        $tasks = ProjectTask::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->with('assignedUser:id,department')
            // project_tasks.comments / model_specs are LOB columns — leave them out.
            ->get([
                'id', 'project_id', 'parent_task_id', 'depends_on_task_id', 'name',
                'category', 'milestone_order', 'department', 'sub_unit', 'assigned_to',
                'status', 'progress', 'start_date', 'end_date', 'lead_time_days',
            ]);

        $tasksByProject = $tasks->groupBy('project_id');
        $overdue = $tasks->filter(fn (ProjectTask $task) => $this->isOverdue($task, $today));

        // Every drill-down names the project a row belongs to.
        $projectNames = $projects->pluck('name', 'id');

        return [
            'type'         => $type,
            'generated_at' => $today->toDateString(),
            'banner'       => $this->buildBanner($type, $user, $tasks),
            'kpis'         => $this->buildKpis($projects, $tasks, $tasksByProject, $overdue, $today, $projectNames),
            'tiles'        => $this->buildTiles($tasks, $projectNames),
            'departments'  => $this->buildDepartments($tasks, $projectNames),
            'health'       => $this->buildHealth($projects, $tasksByProject, $today),
            'alerts'       => $this->buildAlerts($projects, $tasksByProject, $today),
        ];
    }

    /* ------------------------------------------------------------ breakdown */

    /**
     * The payload behind a clickable box: the rule in words, the counts that rule
     * produced, and the rows themselves so the number can be audited rather than
     * trusted. Rows are capped — a department can own hundreds of activities and
     * nobody reads past the first screen.
     *
     * @param  Collection  $rows  Already ordered; $map turns one into a cell array.
     */
    private function makeBreakdown(
        string $formula,
        array $summary,
        array $columns,
        Collection $rows,
        callable $map
    ): array {
        $total = $rows->count();
        $shown = $rows->take(self::BREAKDOWN_LIMIT);

        return [
            'formula'   => $formula,
            'summary'   => $summary,
            'columns'   => $columns,
            'rows'      => $shown->map($map)->values()->all(),
            'total'     => $total,
            'truncated' => $total > self::BREAKDOWN_LIMIT,
        ];
    }

    private function summaryRow(string $label, string|int $value): array
    {
        return ['label' => $label, 'value' => (string) $value];
    }

    private function shortDate($date): string
    {
        return $date ? CarbonImmutable::parse($date)->format('j M Y') : '—';
    }

    /** Project · Activity · Due · Progress — the shape most drill-downs use. */
    private function taskRowMapper(Collection $projectNames): callable
    {
        return fn (ProjectTask $task) => [
            $projectNames[$task->project_id] ?? 'Unknown project',
            ($task->parent_task_id ? '↳ ' : '') . $task->name,
            $this->shortDate($task->end_date),
            $task->progress . '%',
        ];
    }

    /* ---------------------------------------------------------------- rules */

    /** Past its end date with work still outstanding. */
    private function isOverdue(ProjectTask $task, CarbonImmutable $today): bool
    {
        if (! $task->end_date || $this->isTerminal($task->status)) {
            return false;
        }

        return (int) $task->progress < 100
            && CarbonImmutable::parse($task->end_date)->lt($today);
    }

    private function isTerminal(?string $status): bool
    {
        return in_array(trim((string) $status), self::TERMINAL_STATUSES, true);
    }

    private function isDone(ProjectTask $task): bool
    {
        return (int) $task->progress >= 100 || $this->isTerminal($task->status);
    }

    private function matchesAny(?string $name, array $keywords): bool
    {
        $name = mb_strtolower(trim((string) $name));

        if ($name === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            if (str_contains($name, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /** Mean progress of a set of rows, 0 when the set is empty. */
    private function meanProgress(Collection $tasks): int
    {
        if ($tasks->isEmpty()) {
            return 0;
        }

        return (int) round($tasks->avg(fn (ProjectTask $task) => min(100, max(0, (int) $task->progress))));
    }

    /* --------------------------------------------------------------- banner */

    /**
     * Describes access the user ALREADY has — it grants nothing. Manager rights
     * still come from Project::isManagedBy (creator, or Admin / Solutions Admin).
     */
    private function buildBanner(string $type, ?User $user, Collection $tasks): array
    {
        $homeDepartmentId = $user ? DepartmentContext::homeDepartmentId($user) : null;
        $homeDepartment = $homeDepartmentId
            ? Department::whereKey($homeDepartmentId)->value('name')
            : trim((string) $user?->department);

        $isManager = $user && $user->hasAnyRole(['Admin', 'Solutions Admin']);
        $assignedRows = $user
            ? $tasks->filter(fn (ProjectTask $task) => (int) $task->assigned_to === (int) $user->id)->count()
            : 0;

        if ($isManager) {
            $access = 'manager';
            $headline = $type . ' Portfolio Manager';
            $blurb = 'You can manage schedules, department deliverables and readiness across every ' . $type . ' project.';
        } elseif ($assignedRows > 0) {
            $access = 'contributor';
            $headline = $type . ' Contributor';
            $blurb = 'You can update the ' . $assignedRows . ' ' . ($assignedRows === 1 ? 'activity' : 'activities')
                . ' assigned to you. Projects you created are fully editable.';
        } else {
            $access = 'viewer';
            $headline = $type . ' Viewer';
            $blurb = 'You can review schedules and readiness. Editing is limited to projects you created and rows assigned to you.';
        }

        return [
            'department' => $homeDepartment ?: null,
            'access'     => $access,
            'headline'   => $headline,
            'blurb'      => $blurb,
            'pill'       => match ($access) {
                'manager'     => 'Portfolio access • All projects',
                'contributor' => 'Contributor access • Assigned rows',
                default       => 'Read access • Review only',
            },
        ];
    }

    /* ----------------------------------------------------------------- kpis */

    private function buildKpis(
        Collection $projects,
        Collection $tasks,
        Collection $tasksByProject,
        Collection $overdue,
        CarbonImmutable $today,
        Collection $projectNames
    ): array {
        $active = $projects->reject(fn (Project $project) => $this->isTerminal($project->status));

        $overdueByProject = $overdue->groupBy('project_id');
        $needingAttention = $active->filter(
            fn (Project $project) => ($overdueByProject[$project->id] ?? collect())->isNotEmpty()
        )->count();

        $approvals = $tasks->filter(fn (ProjectTask $task) => ! $this->isDone($task)
            && $this->matchesAny($task->name, self::APPROVAL_KEYWORDS));

        $dueToday = $approvals->filter(
            fn (ProjectTask $task) => $task->end_date && CarbonImmutable::parse($task->end_date)->isSameDay($today)
        )->count();

        // "Critical path" here means an overdue row that something else is waiting
        // on — the ones whose slippage actually pushes the plan.
        $blockingIds = $tasks->pluck('depends_on_task_id')->filter()->unique()->flip();
        $onCriticalPath = $overdue->filter(fn (ProjectTask $task) => $blockingIds->has($task->id))->count();

        $readiness = $this->portfolioReadiness($projects, $tasksByProject);
        $withWarnings = $active->filter(function (Project $project) use ($tasksByProject, $today) {
            return $this->projectState($project, $tasksByProject[$project->id] ?? collect(), $today) !== 'on_track';
        })->count();

        $taskMapper = $this->taskRowMapper($projectNames);

        return [
            [
                'key'     => 'active_projects',
                'label'   => 'Active Projects',
                'value'   => $active->count(),
                'display' => (string) $active->count(),
                'caption' => $needingAttention . ' needing attention',
                'tone'    => 'slate',
                'breakdown' => $this->makeBreakdown(
                    'Every project of this type whose status is not Completed or Cancelled. "Needing attention" counts those with at least one overdue activity.',
                    [
                        $this->summaryRow('Projects of this type', $projects->count()),
                        $this->summaryRow('Completed or cancelled', $projects->count() - $active->count()),
                        $this->summaryRow('Active', $active->count()),
                        $this->summaryRow('With an overdue activity', $needingAttention),
                    ],
                    ['Project', 'Status', 'Target go-live', 'Overdue rows'],
                    $active->sortBy('name')->values(),
                    fn (Project $project) => [
                        $project->name,
                        $project->status ?: '—',
                        $this->shortDate($project->target_go_live),
                        (string) ($overdueByProject[$project->id] ?? collect())->count(),
                    ]
                ),
            ],
            [
                'key'     => 'pending_approvals',
                'label'   => 'Pending Approvals',
                'value'   => $approvals->count(),
                'display' => (string) $approvals->count(),
                'caption' => $dueToday . ' due today',
                'tone'    => 'indigo',
                'breakdown' => $this->makeBreakdown(
                    'There is no approvals table — an activity counts as an approval when its name contains one of: '
                        . implode(', ', self::APPROVAL_KEYWORDS)
                        . '. It is pending while progress is below 100% and status is not Completed or Cancelled.',
                    [
                        $this->summaryRow('Matching activities', $tasks->filter(fn (ProjectTask $task) => $this->matchesAny($task->name, self::APPROVAL_KEYWORDS))->count()),
                        $this->summaryRow('Still pending', $approvals->count()),
                        $this->summaryRow('Due today', $dueToday),
                        $this->summaryRow('Already overdue', $approvals->filter(fn (ProjectTask $task) => $this->isOverdue($task, $today))->count()),
                    ],
                    ['Project', 'Activity', 'Due', 'Progress'],
                    $approvals->sortBy(fn (ProjectTask $task) => $task->end_date?->toDateString() ?? '9999')->values(),
                    $taskMapper
                ),
            ],
            [
                'key'     => 'overdue_activities',
                'label'   => 'Overdue Activities',
                'value'   => $overdue->count(),
                'display' => (string) $overdue->count(),
                'caption' => $onCriticalPath . ' blocking other rows',
                'tone'    => 'rose',
                'breakdown' => $this->makeBreakdown(
                    'End date is in the past, progress is below 100%, and status is not Completed or Cancelled. "Blocking other rows" means another activity names this one as its requisite.',
                    [
                        $this->summaryRow('Activities with an end date', $tasks->filter(fn (ProjectTask $task) => (bool) $task->end_date)->count()),
                        $this->summaryRow('Overdue', $overdue->count()),
                        $this->summaryRow('Blocking another row', $onCriticalPath),
                        $this->summaryRow('Not yet started (0%)', $overdue->filter(fn (ProjectTask $task) => (int) $task->progress === 0)->count()),
                    ],
                    ['Project', 'Activity', 'Due', 'Progress'],
                    $overdue->sortBy(fn (ProjectTask $task) => $task->end_date?->toDateString() ?? '9999')->values(),
                    $taskMapper
                ),
            ],
            [
                'key'     => 'readiness',
                'label'   => 'Portfolio Readiness',
                'value'   => $readiness,
                'display' => $readiness . '%',
                'caption' => $withWarnings . ' with active warnings',
                'tone'    => 'emerald',
                'breakdown' => $this->makeBreakdown(
                    'Each project scores the mean progress of its own activities; the portfolio figure is the mean of those project scores, so a 300-row project does not outweigh a 20-row one. Completed projects are included.',
                    [
                        $this->summaryRow('Projects scored', $projects->count()),
                        $this->summaryRow('Portfolio readiness', $readiness . '%'),
                        $this->summaryRow('Active with a warning', $withWarnings),
                    ],
                    ['Project', 'Status', 'Activities', 'Readiness'],
                    $projects->sortByDesc(fn (Project $project) => $this->meanProgress($tasksByProject[$project->id] ?? collect()))->values(),
                    fn (Project $project) => [
                        $project->name,
                        $project->status ?: '—',
                        (string) ($tasksByProject[$project->id] ?? collect())->count(),
                        $this->meanProgress($tasksByProject[$project->id] ?? collect()) . '%',
                    ]
                ),
            ],
        ];
    }

    /** Mean of each project's mean task progress, so a 300-row project does not outvote a 20-row one. */
    private function portfolioReadiness(Collection $projects, Collection $tasksByProject): int
    {
        if ($projects->isEmpty()) {
            return 0;
        }

        $scored = $projects->map(
            fn (Project $project) => $this->meanProgress($tasksByProject[$project->id] ?? collect())
        );

        return (int) round($scored->avg());
    }

    /* ---------------------------------------------------------------- tiles */

    /**
     * Tile 1 is always schedule compliance; tiles 2-4 are the type's three
     * largest milestones, so Store Opening surfaces its construction / permit /
     * opening milestones without any per-type configuration.
     */
    private function buildTiles(Collection $tasks, Collection $projectNames): array
    {
        $dated = $tasks->filter(fn (ProjectTask $task) => (bool) $task->end_date);
        $today = CarbonImmutable::today();
        $taskMapper = $this->taskRowMapper($projectNames);

        $lapsed = $dated->filter(fn (ProjectTask $task) => $this->isOverdue($task, $today));

        $compliance = $dated->isEmpty()
            ? 0
            : (int) round(($dated->count() - $lapsed->count()) / $dated->count() * 100);

        $tiles = [[
            'label'   => 'Overall Schedule Compliance',
            'value'   => $compliance,
            'caption' => $dated->count() . ' scheduled activities',
            'breakdown' => $this->makeBreakdown(
                'The share of activities with an end date that are NOT overdue. Rows without an end date are excluded because they cannot be late. Listed below are the overdue rows dragging the figure down.',
                [
                    $this->summaryRow('Activities with an end date', $dated->count()),
                    $this->summaryRow('On time', $dated->count() - $lapsed->count()),
                    $this->summaryRow('Overdue', $lapsed->count()),
                    $this->summaryRow('Compliance', $compliance . '%'),
                ],
                ['Project', 'Overdue activity', 'Due', 'Progress'],
                $lapsed->sortBy(fn (ProjectTask $task) => $task->end_date?->toDateString() ?? '9999')->values(),
                $taskMapper
            ),
        ]];

        $milestones = $tasks
            ->filter(fn (ProjectTask $task) => filled($task->category))
            ->groupBy('category')
            ->sortByDesc(fn (Collection $group) => $group->count())
            ->take(3);

        foreach ($milestones as $name => $group) {
            $label = $this->titleCase((string) $name);

            $tiles[] = [
                'label'   => $label,
                'value'   => $this->meanProgress($group),
                'caption' => $group->count() . ' activities',
                'breakdown' => $this->makeBreakdown(
                    'One of the three largest milestones for this project type, scored as the mean progress of every activity and sub-task filed under it across all projects.',
                    [
                        $this->summaryRow('Milestone', $label),
                        $this->summaryRow('Activities counted', $group->count()),
                        $this->summaryRow('Finished (100%)', $group->filter(fn (ProjectTask $task) => (int) $task->progress >= 100)->count()),
                        $this->summaryRow('Not started (0%)', $group->filter(fn (ProjectTask $task) => (int) $task->progress === 0)->count()),
                        $this->summaryRow('Mean progress', $this->meanProgress($group) . '%'),
                    ],
                    ['Project', 'Activity', 'Due', 'Progress'],
                    $group->sortBy('progress')->values(),
                    $taskMapper
                ),
            ];
        }

        return $tiles;
    }

    /**
     * Template milestones are stored inconsistently — "SITE SELECTION &
     * DOCUMENTATION", "construction", "STORE opening" all appear. Title-case each
     * word, but leave short all-caps words alone so acronyms (POS, IT, HR)
     * survive as themselves.
     */
    private function titleCase(string $value): string
    {
        $words = preg_split('/(\s+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];

        return implode('', array_map(function (string $word) {
            $isAcronym = mb_strlen($word) <= 3 && $word === mb_strtoupper($word);

            return $isAcronym ? $word : mb_convert_case(mb_strtolower($word), MB_CASE_TITLE, 'UTF-8');
        }, $words));
    }

    /* ---------------------------------------------------------- departments */

    /**
     * Grouped case-insensitively: the same department reaches us spelled three
     * ways ("Technology and Solutions" on the departments table, "Technology And
     * Solutions" on a template row), and those must not become three bars. The
     * departments table supplies the canonical spelling when it recognises the
     * name; otherwise the first spelling seen wins.
     *
     * Names that genuinely differ ("Supply Chain" vs "Supply Chain Management")
     * stay separate — merging those would be guessing.
     */
    private function buildDepartments(Collection $tasks, Collection $projectNames): array
    {
        $canonical = Department::query()
            ->pluck('name')
            ->mapWithKeys(fn (string $name) => [mb_strtolower(trim($name)) => $name]);

        return $tasks
            ->filter(fn (ProjectTask $task) => $task->resolvedDepartment() !== null)
            ->groupBy(fn (ProjectTask $task) => mb_strtolower($task->resolvedDepartment()))
            ->map(function (Collection $group, string $key) use ($canonical, $projectNames) {
                $name = $canonical[$key] ?? $group->first()->resolvedDepartment();
                $fromTemplate = $group->filter(fn (ProjectTask $task) => filled($task->department));
                $fromAssigneeFallback = $group->filter(
                    fn (ProjectTask $task) => blank($task->department)
                        && $task->assigned_to
                        && filled($task->assignedUser?->department)
                );

                return [
                    'name'     => $name,
                    'progress' => (int) round($group->avg(fn (ProjectTask $task) => min(100, max(0, (int) $task->progress)))),
                    'tasks'    => $group->count(),
                    'breakdown' => $this->makeBreakdown(
                        'Mean progress of every activity and sub-task attributed to this department. The department stored on the row is the accountable process department and wins for monitoring; the assignee\'s department is used only when the row has no department. Departments are matched case-insensitively, so alternate spellings collapse into one bar.',
                        [
                            $this->summaryRow('Department', $name),
                            $this->summaryRow('Activities attributed', $group->count()),
                            $this->summaryRow('Via accountable row department', $fromTemplate->count()),
                            $this->summaryRow('Via assignee fallback', $fromAssigneeFallback->count()),
                            $this->summaryRow('Mean progress', (int) round($group->avg('progress')) . '%'),
                        ],
                        ['Project', 'Activity', 'Attributed by', 'Progress'],
                        $group->sortBy('progress')->values(),
                        fn (ProjectTask $task) => [
                            $projectNames[$task->project_id] ?? 'Unknown project',
                            ($task->parent_task_id ? '↳ ' : '') . $task->name,
                            filled($task->department) ? 'Accountable row department' : 'Assignee fallback',
                            $task->progress . '%',
                        ]
                    ),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /* --------------------------------------------------------------- health */

    /**
     * on_track / at_risk / delayed for one project. Independent of the go-live
     * date comparison shown beside it — a project can be on target for its date
     * and still be delayed on the ground, which is what the mock's pills show.
     */
    private function projectState(Project $project, Collection $tasks, CarbonImmutable $today): string
    {
        if ($this->isTerminal($project->status)) {
            return 'on_track';
        }

        if (trim((string) $project->status) === 'Delayed') {
            return 'delayed';
        }

        $overdue = $tasks->filter(fn (ProjectTask $task) => $this->isOverdue($task, $today))->count();

        if ($overdue > 0) {
            return 'delayed';
        }

        // Nothing overdue yet, but go-live is close and the plan is barely started.
        if ($project->target_go_live) {
            $daysOut = $today->diffInDays(CarbonImmutable::parse($project->target_go_live), false);

            if ($daysOut >= 0 && $daysOut <= 60 && $this->meanProgress($tasks) < 60) {
                return 'at_risk';
            }
        }

        return 'on_track';
    }

    private function buildHealth(Collection $projects, Collection $tasksByProject, CarbonImmutable $today): array
    {
        return $projects
            ->reject(fn (Project $project) => $this->isTerminal($project->status))
            ->sortBy(fn (Project $project) => $project->target_go_live?->toDateString() ?? '9999-12-31')
            ->take(self::HEALTH_LIMIT)
            ->map(function (Project $project) use ($tasksByProject, $today) {
                $tasks = $tasksByProject[$project->id] ?? collect();
                $goLive = $project->target_go_live ? CarbonImmutable::parse($project->target_go_live) : null;
                $daysLate = $goLive && $goLive->lt($today) ? $goLive->diffInDays($today) : 0;

                return [
                    'id'           => $project->id,
                    'name'         => $project->name,
                    'location'     => $project->store?->area ?: $project->subject_label,
                    'opens_label'  => $goLive ? 'Opens ' . $goLive->format('j M Y') : 'No target date',
                    'schedule'     => $daysLate > 0
                        ? 'Delayed by ' . $daysLate . ' ' . ($daysLate === 1 ? 'day' : 'days')
                        : 'On target',
                    'state'        => $this->projectState($project, $tasks, $today),
                    'readiness'    => $this->meanProgress($tasks),
                ];
            })
            ->values()
            ->all();
    }

    /* --------------------------------------------------------------- alerts */

    /**
     * Rule-derived warnings. Each one names the row it came from so the panel can
     * link straight to the project.
     */
    private function buildAlerts(Collection $projects, Collection $tasksByProject, CarbonImmutable $today): array
    {
        $alerts = [];

        foreach ($projects as $project) {
            $tasks = $tasksByProject[$project->id] ?? collect();

            if ($tasks->isEmpty()) {
                continue;
            }

            $outstanding = $tasks->reject(fn (ProjectTask $task) => $this->isDone($task));

            // 1. The project claims it is finished while rows are still open.
            if ($this->isTerminal($project->status) && $outstanding->isNotEmpty()) {
                $alerts[] = [
                    'severity'   => 3,
                    'project_id' => $project->id,
                    'title'      => $project->status === 'Completed' ? 'Marked complete too early' : 'Cancelled with open work',
                    'detail'     => $project->name . ' is ' . strtolower((string) $project->status) . ' while '
                        . $outstanding->count() . ' ' . ($outstanding->count() === 1 ? 'activity remains' : 'activities remain')
                        . ' incomplete.',
                ];
            }

            // 2. An approval gate is sitting open past its date.
            $approval = $outstanding
                ->filter(fn (ProjectTask $task) => $this->matchesAny($task->name, self::APPROVAL_KEYWORDS)
                    && $this->isOverdue($task, $today))
                ->sortBy(fn (ProjectTask $task) => $task->end_date?->toDateString())
                ->first();

            if ($approval) {
                $alerts[] = [
                    'severity'   => 3,
                    'project_id' => $project->id,
                    'title'      => 'Approval pending',
                    'detail'     => $project->name . ': "' . $approval->name . '" is awaiting decision since '
                        . CarbonImmutable::parse($approval->end_date)->format('j M Y') . '.',
                ];
            }

            // 3. A compliance gate has not been started at all.
            $permit = $outstanding
                ->filter(fn (ProjectTask $task) => $this->matchesAny($task->name, self::PERMIT_KEYWORDS)
                    && (int) $task->progress === 0
                    && $task->start_date
                    && CarbonImmutable::parse($task->start_date)->lt($today))
                ->sortBy(fn (ProjectTask $task) => $task->start_date?->toDateString())
                ->first();

            if ($permit) {
                $alerts[] = [
                    'severity'   => 2,
                    'project_id' => $project->id,
                    'title'      => 'Compliance gate not started',
                    'detail'     => $project->name . ': "' . $permit->name . '" should have started '
                        . CarbonImmutable::parse($permit->start_date)->format('j M Y') . ' and is still at 0%.',
                ];
            }

            // 4. A row is materially behind where its own dates say it should be.
            $behind = $outstanding
                ->map(fn (ProjectTask $task) => [
                    'task' => $task,
                    'gap'  => $this->scheduleGap($task, $today),
                ])
                ->filter(fn (array $row) => $row['gap'] >= 40)
                ->sortByDesc('gap')
                ->first();

            if ($behind) {
                $alerts[] = [
                    'severity'   => 1,
                    'project_id' => $project->id,
                    'title'      => 'Activity behind plan',
                    'detail'     => $project->name . ': "' . $behind['task']->name . '" is at '
                        . (int) $behind['task']->progress . '% with ' . $behind['gap'] . ' points of its schedule elapsed.',
                ];
            }
        }

        usort($alerts, fn (array $a, array $b) => $b['severity'] <=> $a['severity']);

        return array_slice($alerts, 0, self::ALERT_LIMIT);
    }

    /**
     * How many percentage points a row's elapsed schedule is ahead of its
     * reported progress. 0 when the row has no usable span or is not yet behind.
     */
    private function scheduleGap(ProjectTask $task, CarbonImmutable $today): int
    {
        if (! $task->start_date || ! $task->end_date) {
            return 0;
        }

        $start = CarbonImmutable::parse($task->start_date);
        $end = CarbonImmutable::parse($task->end_date);
        $span = $start->diffInDays($end);

        if ($span <= 0 || $today->lte($start)) {
            return 0;
        }

        $elapsed = min(100, (int) round($start->diffInDays($today) / $span * 100));

        return max(0, $elapsed - (int) $task->progress);
    }

    /* ---------------------------------------------------------------- empty */

    private function emptyPayload(string $type, ?User $user): array
    {
        return [
            'type'         => $type,
            'generated_at' => CarbonImmutable::today()->toDateString(),
            'banner'       => $this->buildBanner($type, $user, collect()),
            'kpis'         => $this->buildKpis(collect(), collect(), collect(), collect(), CarbonImmutable::today(), collect()),
            'tiles'        => $this->buildTiles(collect(), collect()),
            'departments'  => [],
            'health'       => [],
            'alerts'       => [],
        ];
    }
}
