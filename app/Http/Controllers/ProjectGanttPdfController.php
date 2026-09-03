<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectWeeklyProgressChart;
use App\Services\ProjectWeeklyProgressService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ProjectGanttPdfController extends Controller
{
    public function __construct(
        private ProjectWeeklyProgressService $weeklyProgress,
        private ProjectWeeklyProgressChart $weeklyProgressChart
    ) {
    }

    public function __invoke(Project $project)
    {
        $project->load([
            'entityCompany:id,name,code',
            'brandCompany:id,name,code',
            'store:id,name,code',
            'creator:id,name',
            'tasks.assignedUser:id,name,department,org_path',
            'tasks.store:id,code,name',
        ]);

        $tasks = $project->tasks
            ->reject(fn ($task) => in_array(trim((string) $task->status), ['N/A', 'Not Applicable'], true))
            ->values();
        $datedTasks = $tasks->filter(fn ($task) => $task->start_date && $task->end_date);
        $timelineStart = $datedTasks->min('start_date') ?: now()->startOfDay();
        $timelineEnd = $datedTasks->max('end_date') ?: $timelineStart->copy()->addDay();
        $timelineStart = Carbon::parse($timelineStart)->startOfDay();
        $timelineEnd = Carbon::parse($timelineEnd)->startOfDay();
        $timelineDays = max(1, $timelineStart->diffInDays($timelineEnd) + 1);

        $children = $tasks->whereNotNull('parent_task_id')->groupBy('parent_task_id');
        $roots = $tasks->whereNull('parent_task_id');

        $milestones = $roots
            ->groupBy(fn ($task) => $task->category ?: 'General')
            ->map(function ($activities, $name) use ($children, $timelineStart, $timelineDays) {
                $explicitWeights = $activities->every(fn ($task) => $task->activity_weight !== null);
                $weightTotal = (float) $activities->sum(fn ($task) => $explicitWeights ? (float) $task->activity_weight : 1);
                $progress = $weightTotal > 0
                    ? (int) round($activities->sum(fn ($task) => ($explicitWeights ? (float) $task->activity_weight : 1) * (int) $task->progress) / $weightTotal)
                    : 0;

                $rows = collect();
                foreach ($activities as $activity) {
                    $rows->push($this->presentationRow($activity, 0, $timelineStart, $timelineDays));
                    foreach ($children->get($activity->id, collect()) as $subTask) {
                        $rows->push($this->presentationRow($subTask, 1, $timelineStart, $timelineDays));
                    }
                }

                return [
                    'name' => $name,
                    'weight' => (float) ($activities->first()->milestone_weight ?? 0),
                    'progress' => $progress,
                    'rows' => $rows,
                ];
            })
            ->values();

        $statusCounts = $tasks->groupBy('status')->map->count();
        $progressSeries = $this->weeklyProgress->build($project, $tasks);
        $weeklyChartImage = $this->weeklyProgressChart->render($progressSeries);
        $weeklyReport = $this->buildWeeklyReport(
            $tasks,
            $milestones,
            $project,
            $progressSeries
        );

        return Pdf::loadView('pdf.project-gantt', [
            'project' => $project,
            'milestones' => $milestones,
            'statusCounts' => $statusCounts,
            'weeklyReport' => $weeklyReport,
            'weeklyChartImage' => $weeklyChartImage,
            'activityCount' => $roots->count(),
            'subTaskCount' => $tasks->whereNotNull('parent_task_id')->count(),
            'timelineStart' => $timelineStart,
            'timelineEnd' => $timelineEnd,
            'generatedAt' => now(),
        ])->setPaper('a3', 'landscape')->stream(Str::slug($project->name).'-gantt-progress.pdf');
    }

    private function buildWeeklyReport(
        $tasks,
        $milestones,
        $project,
        array $progressSeries
    ): array
    {
        $now = now();
        // Shared Monday-Sunday reporting periods used by the Weekly Timeline.
        $weeks = collect($progressSeries['weeks'])->map(fn (array $week) => [
            ...$week,
            'start' => Carbon::parse($week['start'])->startOfDay(),
            'end' => Carbon::parse($week['end'])->endOfDay(),
        ])->all();
        $currentWeekIdx = min((int) ($progressSeries['active_index'] ?? 0), max(0, count($weeks) - 1));

        $activeWeek = $weeks[$currentWeekIdx];
        $prevWeek = $currentWeekIdx > 0 ? $weeks[$currentWeekIdx - 1] : null;
        $nextWeek = $currentWeekIdx < count($weeks) - 1 ? $weeks[$currentWeekIdx + 1] : null;

        // KPI cards must be the exact points drawn in the shared chart.
        $currentActual = (int) ($progressSeries['actual'][$currentWeekIdx] ?? 0);
        $prevActual = $prevWeek ? (int) ($progressSeries['actual'][$currentWeekIdx - 1] ?? 0) : 0;
        $currentPlanned = (int) ($progressSeries['planned'][$currentWeekIdx] ?? 0);
        $prevPlanned = $prevWeek ? (int) ($progressSeries['planned'][$currentWeekIdx - 1] ?? 0) : 0;

        $wowActualDelta = $currentActual - $prevActual;
        $variance = $currentActual - $currentPlanned;

        $milestoneComparison = $milestones->map(function ($m) use ($progressSeries, $currentWeekIdx, $prevWeek) {
            $series = $progressSeries['milestones'][$m['name']] ?? ['planned' => [], 'actual' => []];
            $currentActual = (int) ($series['actual'][$currentWeekIdx] ?? 0);
            $previousActual = $prevWeek ? (int) ($series['actual'][$currentWeekIdx - 1] ?? 0) : 0;
            $delta = $currentActual - $previousActual;

            return [
                'name' => $m['name'],
                'weight' => $m['weight'],
                'prev_actual' => $previousActual,
                'current_actual' => $currentActual,
                'delta' => $delta,
                'planned' => (int) ($series['planned'][$currentWeekIdx] ?? 0),
                'status' => $currentActual >= 100 ? 'Completed' : ($delta > 0 ? 'Progressing' : ($currentActual > 0 ? 'Ongoing' : 'Pending')),
            ];
        });

        $weekStart = $activeWeek['start'];
        $weekEnd = $activeWeek['end'];

        // Per Store rows have their own executive summary below. Keeping them in
        // the generic activity stream produced eight indistinguishable "Store
        // Deployment" entries and hid the store-level result the review needs.
        $standardTasks = $tasks
            ->reject(fn ($task) => $task->activity_mode === 'per_store')
            ->values();

        $storeRows = $tasks
            ->filter(fn ($task) => $task->activity_mode === 'per_store'
                && ! $task->parent_task_id
                && $task->store_id)
            ->groupBy('store_id')
            ->map(function ($storeTasks) {
                $explicitWeights = $storeTasks->every(fn ($task) => $task->activity_weight !== null);
                $weightTotal = (float) $storeTasks->sum(
                    fn ($task) => $explicitWeights ? (float) $task->activity_weight : 1
                );
                $progress = $weightTotal > 0
                    ? (int) round($storeTasks->sum(
                        fn ($task) => ($explicitWeights ? (float) $task->activity_weight : 1) * (int) $task->progress
                    ) / $weightTotal)
                    : 0;
                $store = $storeTasks->first()->store;

                return [
                    'id' => (int) $storeTasks->first()->store_id,
                    'code' => $store?->code,
                    'name' => $store?->name ?: 'Store #'.$storeTasks->first()->store_id,
                    'progress' => $progress,
                    'status' => $progress >= 100 ? 'Completed' : ($progress > 0 ? 'In Progress' : 'Pending'),
                ];
            })
            ->sortBy(fn (array $store) => strtolower(($store['code'] ?: '').' '.$store['name']))
            ->values();
        $selectedStoreCount = $storeRows->count();
        $targetStoreCount = max((int) ($project->target_store_count ?? 0), $selectedStoreCount);
        $storeTargetProgress = $targetStoreCount > 0
            ? (int) round($storeRows->sum('progress') / $targetStoreCount)
            : 0;
        $storeRollout = [
            'stores' => $storeRows,
            'selected' => $selectedStoreCount,
            'target' => $targetStoreCount,
            'completed' => $storeRows->where('progress', '>=', 100)->count(),
            'in_progress' => $storeRows->whereBetween('progress', [1, 99])->count(),
            'pending' => $storeRows->where('progress', 0)->count(),
            'unselected' => max(0, $targetStoreCount - $selectedStoreCount),
            'progress' => $storeTargetProgress,
        ];

        $completedThisWeek = $standardTasks->filter(function ($t) use ($weekStart, $weekEnd) {
            $isDone = (int) $t->progress >= 100 || strcasecmp((string)$t->status, 'Done') === 0;
            if (!$isDone) return false;
            $e = $t->end_date ? Carbon::parse($t->end_date) : null;
            return $e && $e->betweenIncluded($weekStart, $weekEnd);
        })->values();

        if ($completedThisWeek->isEmpty()) {
            $completedThisWeek = $standardTasks->filter(fn ($t) => (int) $t->progress >= 100 || strcasecmp((string)$t->status, 'Done') === 0)->take(6)->values();
        }

        $activeThisWeek = $standardTasks->filter(function ($t) use ($weekStart, $weekEnd) {
            $prog = (int) ($t->progress ?? 0);
            if ($prog >= 100) return false;
            $s = $t->start_date ? Carbon::parse($t->start_date) : null;
            $e = $t->end_date ? Carbon::parse($t->end_date) : null;
            if ($s && $e) {
                return $s->lte($weekEnd) && $e->gte($weekStart);
            }
            return $prog > 0;
        })->take(8)->values();

        $criticalOrOverdue = $standardTasks->filter(function ($t) use ($now) {
            $prog = (int) ($t->progress ?? 0);
            if ($prog >= 100) return false;
            $manual = strtolower((string) ($t->manual_status ?? ''));
            if (in_array($manual, ['blocked', 'for approval', 'delayed'], true)) {
                return true;
            }
            $e = $t->end_date ? Carbon::parse($t->end_date)->endOfDay() : null;
            return $e && $e->lt($now);
        })->take(8)->values();

        $nextWeekTasks = collect();
        if ($nextWeek) {
            $nwStart = $nextWeek['start'];
            $nwEnd = $nextWeek['end'];
            $nextWeekTasks = $standardTasks->filter(function ($t) use ($nwStart, $nwEnd) {
                $s = $t->start_date ? Carbon::parse($t->start_date) : null;
                return $s && $s->betweenIncluded($nwStart, $nwEnd);
            })->take(6)->values();
        }

        return [
            'activeWeek' => $activeWeek,
            'prevWeek' => $prevWeek,
            'nextWeek' => $nextWeek,
            'currentActual' => $currentActual,
            'prevActual' => $prevActual,
            'currentPlanned' => $currentPlanned,
            'prevPlanned' => $prevPlanned,
            'wowActualDelta' => $wowActualDelta,
            'variance' => $variance,
            'chart' => $progressSeries,
            'milestoneComparison' => $milestoneComparison,
            'storeRollout' => $storeRollout,
            'completedThisWeek' => $completedThisWeek,
            'activeThisWeek' => $activeThisWeek,
            'criticalOrOverdue' => $criticalOrOverdue,
            'nextWeekTasks' => $nextWeekTasks,
        ];
    }

    private function presentationRow($task, int $depth, Carbon $timelineStart, int $timelineDays): array
    {
        $start = $task->start_date ? Carbon::parse($task->start_date)->startOfDay() : null;
        $end = $task->end_date ? Carbon::parse($task->end_date)->startOfDay() : null;
        $left = $start ? max(0, $timelineStart->diffInDays($start, false)) / $timelineDays * 100 : 0;
        $width = ($start && $end)
            ? max(0.8, ($start->diffInDays($end) + 1) / $timelineDays * 100)
            : 0;

        return [
            'task' => $task,
            'depth' => $depth,
            'left' => min(100, $left),
            'width' => min(100 - min(100, $left), $width),
        ];
    }
}
