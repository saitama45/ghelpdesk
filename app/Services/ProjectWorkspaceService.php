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
 * Builds the three read-only screens on /projects/{id}: the department
 * Workspace on the Overview tab, the Monitoring tab, and the Reports tab.
 *
 * Everything is scoped to the ONE project being viewed — the numbers describe
 * the project you opened, not the portfolio. (The per-type portfolio roll-up is
 * a different screen: ProjectOverviewService, on the /projects list.)
 *
 * Departments are attributed with ProjectTask::resolvedDepartment() — assignee's
 * department first, the activity template's department as fallback.
 */
class ProjectWorkspaceService
{
    /** Activity names that stand in for a compliance gate, lowercase. */
    private const PERMIT_KEYWORDS = ['permit', 'clearance', 'license', 'licence', 'occupancy'];

    /** "Due soon" horizon on the workspace cards. */
    private const DUE_SOON_DAYS = 7;

    /**
     * @return array{workspace: array, monitoring: array, reports: array}
     */
    public function build(Project $project, ?User $user): array
    {
        $today = CarbonImmutable::today();

        $tasks = ProjectTask::query()
            ->where('project_id', $project->id)
            ->with('assignedUser:id,name,department')
            // project_tasks.comments / model_specs are LOB columns — leave them out.
            ->get([
                'id', 'project_id', 'parent_task_id', 'depends_on_task_id', 'name',
                'category', 'milestone_order', 'department', 'sub_unit', 'assigned_to',
                'external_assignment', 'status', 'manual_status', 'progress',
                'start_date', 'end_date', 'lead_time_days', 'order',
            ]);

        $wbs = $this->wbsMap($tasks);

        return [
            'workspace'  => $this->buildWorkspace($tasks, $wbs, $user, $today),
            'monitoring' => $this->buildMonitoring($tasks, $wbs, $today),
            'reports'    => $this->buildReports($tasks, $today),
        ];
    }

    /* ------------------------------------------------------------------ wbs */

    /**
     * Outline numbers ("3.1", "3.1.2") for every row.
     *
     * A milestone is only a category string, so its number is the shared
     * milestone_order of its rows; activities are numbered by their position
     * within the milestone and sub-tasks by their position under the parent.
     * Numbering is positional, not stored, so inserting a row renumbers what
     * follows — exactly like the Gantt's own ordering.
     *
     * @return array<int, string>  task id => WBS number
     */
    private function wbsMap(Collection $tasks): array
    {
        $map = [];

        $activities = $tasks->whereNull('parent_task_id')
            ->sortBy([['milestone_order', 'asc'], ['order', 'asc'], ['id', 'asc']]);

        $counters = [];

        foreach ($activities as $activity) {
            $milestone = (int) ($activity->milestone_order ?: 0);
            $counters[$milestone] = ($counters[$milestone] ?? 0) + 1;
            $map[$activity->id] = $milestone . '.' . $counters[$milestone];

            $subIndex = 0;
            foreach ($tasks->where('parent_task_id', $activity->id)->sortBy([['order', 'asc'], ['id', 'asc']]) as $subTask) {
                $subIndex++;
                $map[$subTask->id] = $map[$activity->id] . '.' . $subIndex;
            }
        }

        return $map;
    }

    /* ------------------------------------------------------------ workspace */

    /**
     * The Overview tab's department workspace: what this project owes the
     * viewer's own department, per the "I belong to" axis.
     */
    private function buildWorkspace(Collection $tasks, array $wbs, ?User $user, CarbonImmutable $today): array
    {
        $department = $this->viewerDepartment($user);

        $mine = $department === null
            ? collect()
            : $tasks->filter(fn (ProjectTask $task) => $this->sameDepartment($task->resolvedDepartment(), $department));

        $outstanding = $mine->reject(fn (ProjectTask $task) => $this->isDone($task));

        $dueSoon = $outstanding->filter(function (ProjectTask $task) use ($today) {
            if (! $task->end_date) {
                return false;
            }
            $due = CarbonImmutable::parse($task->end_date);

            return $due->gte($today) && $due->lte($today->addDays(self::DUE_SOON_DAYS));
        });

        $overdue = $mine->filter(fn (ProjectTask $task) => $this->isOverdue($task, $today));

        return [
            'department'   => $department,
            'has_department' => $department !== null,
            'cards' => [
                ['key' => 'assigned',   'label' => 'Assigned activities', 'value' => (string) $mine->count(),  'tone' => 'slate'],
                ['key' => 'due_soon',   'label' => 'Due within 7 days',   'value' => (string) $dueSoon->count(), 'tone' => 'amber'],
                ['key' => 'overdue',    'label' => 'Overdue',             'value' => (string) $overdue->count(), 'tone' => 'rose'],
                ['key' => 'completion', 'label' => 'Department completion', 'value' => $this->meanProgress($mine) . '%', 'tone' => 'emerald'],
            ],
            'rows' => $mine
                ->sortBy(fn (ProjectTask $task) => $this->wbsSortKey($wbs[$task->id] ?? ''))
                ->map(fn (ProjectTask $task) => $this->rowPayload($task, $wbs, $today))
                ->values()
                ->all(),
        ];
    }

    /** The department the viewer is currently acting as, from the "I belong to" axis. */
    private function viewerDepartment(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $id = DepartmentContext::homeDepartmentId($user);
        $name = $id ? Department::whereKey($id)->value('name') : null;
        $name = $name ?: trim((string) $user->department);

        return $name !== '' ? $name : null;
    }

    /* ----------------------------------------------------------- monitoring */

    private function buildMonitoring(Collection $tasks, array $wbs, CarbonImmutable $today): array
    {
        $outstanding = $tasks->reject(fn (ProjectTask $task) => $this->isDone($task));

        $overdue = $tasks->filter(fn (ProjectTask $task) => $this->isOverdue($task, $today));
        $blocked = $tasks->filter(fn (ProjectTask $task) => trim((string) $task->manual_status) === 'Blocked');

        $conflicts = $this->dependencyConflicts($tasks, $wbs);

        $missingPermits = $outstanding->filter(
            fn (ProjectTask $task) => $this->matchesAny($task->name, self::PERMIT_KEYWORDS)
        );

        return [
            'cards' => [
                ['key' => 'overdue',      'label' => 'Overdue activities',      'value' => (string) $overdue->count(),        'tone' => 'rose'],
                ['key' => 'blocked',      'label' => 'Blocked activities',      'value' => (string) $blocked->count(),        'tone' => 'amber'],
                ['key' => 'dependencies', 'label' => 'Dependencies to validate', 'value' => (string) count($conflicts),        'tone' => 'indigo'],
                ['key' => 'permits',      'label' => 'Missing critical permits', 'value' => (string) $missingPermits->count(), 'tone' => 'rose'],
            ],
            'dependencies' => $conflicts,
            'overdue' => $overdue
                ->sortBy(fn (ProjectTask $task) => $task->end_date?->toDateString() ?? '9999')
                ->map(fn (ProjectTask $task) => $this->rowPayload($task, $wbs, $today))
                ->values()
                ->all(),
            'permits' => $missingPermits
                ->sortBy(fn (ProjectTask $task) => $this->wbsSortKey($wbs[$task->id] ?? ''))
                ->map(fn (ProjectTask $task) => $this->rowPayload($task, $wbs, $today))
                ->values()
                ->all(),
        ];
    }

    /**
     * Rows whose schedule contradicts their stated requisite. Only genuine
     * contradictions are listed — a row that simply follows the previous one is
     * normal and is not flagged.
     */
    private function dependencyConflicts(Collection $tasks, array $wbs): array
    {
        $byId = $tasks->keyBy('id');
        $conflicts = [];

        foreach ($tasks as $task) {
            $requisite = $task->depends_on_task_id ? $byId->get($task->depends_on_task_id) : null;

            if (! $requisite) {
                continue;
            }

            $reason = null;

            if ($task->start_date && $requisite->end_date
                && CarbonImmutable::parse($requisite->end_date)->gt(CarbonImmutable::parse($task->start_date))) {
                $reason = 'Requisite ' . ($wbs[$requisite->id] ?? '') . ' ' . $requisite->name
                    . ' finishes ' . CarbonImmutable::parse($requisite->end_date)->format('j M Y')
                    . ', after this row starts ' . CarbonImmutable::parse($task->start_date)->format('j M Y') . '.';
            } elseif ((int) $task->progress > 0 && ! $this->isDone($requisite)) {
                $reason = 'This row is at ' . (int) $task->progress . '% while its requisite '
                    . ($wbs[$requisite->id] ?? '') . ' ' . $requisite->name
                    . ' is only at ' . (int) $requisite->progress . '%.';
            } elseif ((int) $requisite->milestone_order > (int) $task->milestone_order) {
                $reason = 'Requisite ' . ($wbs[$requisite->id] ?? '') . ' ' . $requisite->name
                    . ' sits in a later milestone than this row.';
            }

            if ($reason) {
                $conflicts[] = [
                    'id'      => $task->id,
                    'wbs'     => $wbs[$task->id] ?? '',
                    'name'    => $task->name,
                    'reason'  => $reason,
                    'department' => $task->resolvedDepartment(),
                ];
            }
        }

        usort($conflicts, fn (array $a, array $b) => $this->wbsSortKey($a['wbs']) <=> $this->wbsSortKey($b['wbs']));

        return $conflicts;
    }

    /* -------------------------------------------------------------- reports */

    /** Department accountability for this project: assignments, completed, overdue, completion. */
    private function buildReports(Collection $tasks, CarbonImmutable $today): array
    {
        $canonical = Department::query()
            ->pluck('name')
            ->mapWithKeys(fn (string $name) => [mb_strtolower(trim($name)) => $name]);

        $departments = $tasks
            ->filter(fn (ProjectTask $task) => $task->resolvedDepartment() !== null)
            ->groupBy(fn (ProjectTask $task) => mb_strtolower($task->resolvedDepartment()))
            ->map(fn (Collection $group, string $key) => [
                'name'        => $canonical[$key] ?? $group->first()->resolvedDepartment(),
                'assignments' => $group->count(),
                'completed'   => $group->filter(fn (ProjectTask $task) => $this->isDone($task))->count(),
                'overdue'     => $group->filter(fn (ProjectTask $task) => $this->isOverdue($task, $today))->count(),
                'completion'  => $this->meanProgress($group),
            ])
            ->sortByDesc('assignments')
            ->values()
            ->all();

        $unattributed = $tasks->filter(fn (ProjectTask $task) => $task->resolvedDepartment() === null)->count();

        return [
            'departments'  => $departments,
            'unattributed' => $unattributed,
            'totals' => [
                'assignments' => $tasks->count(),
                'completed'   => $tasks->filter(fn (ProjectTask $task) => $this->isDone($task))->count(),
                'overdue'     => $tasks->filter(fn (ProjectTask $task) => $this->isOverdue($task, $today))->count(),
                'completion'  => $this->meanProgress($tasks),
            ],
        ];
    }

    /* --------------------------------------------------------------- shared */

    private function rowPayload(ProjectTask $task, array $wbs, CarbonImmutable $today): array
    {
        return [
            'id'         => $task->id,
            'wbs'        => $wbs[$task->id] ?? '',
            'name'       => $task->name,
            'milestone'  => $task->category,
            'department' => $task->resolvedDepartment(),
            'assignee'   => $task->assignedUser?->name ?: ($task->external_assignment ?: null),
            'finish'     => $task->end_date ? CarbonImmutable::parse($task->end_date)->format('j M Y') : null,
            'progress'   => min(100, max(0, (int) $task->progress)),
            'status'     => $task->displayStatus(),
            'is_overdue' => $this->isOverdue($task, $today),
            'is_sub_task' => $task->parent_task_id !== null,
        ];
    }

    /** "3.10" must sort after "3.9", so compare segment by segment, numerically. */
    private function wbsSortKey(string $wbs): string
    {
        return collect(explode('.', $wbs))
            ->map(fn (string $part) => str_pad($part, 4, '0', STR_PAD_LEFT))
            ->implode('.');
    }

    private function sameDepartment(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    private function isDone(ProjectTask $task): bool
    {
        return (int) $task->progress >= 100 || trim((string) $task->status) === 'Done';
    }

    private function isOverdue(ProjectTask $task, CarbonImmutable $today): bool
    {
        if (! $task->end_date || $this->isDone($task)) {
            return false;
        }

        return CarbonImmutable::parse($task->end_date)->lt($today);
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

    private function meanProgress(Collection $tasks): int
    {
        if ($tasks->isEmpty()) {
            return 0;
        }

        return (int) round($tasks->avg(fn (ProjectTask $task) => min(100, max(0, (int) $task->progress))));
    }
}
