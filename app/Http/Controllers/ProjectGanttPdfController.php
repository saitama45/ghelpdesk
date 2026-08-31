<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ProjectGanttPdfController extends Controller
{
    public function __invoke(Project $project)
    {
        $project->load([
            'entityCompany:id,name,code',
            'brandCompany:id,name,code',
            'store:id,name,code',
            'creator:id,name',
            'tasks.assignedUser:id,name,department,org_path',
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
        $weeklyReport = $this->buildWeeklyReport($tasks, $milestones, $timelineStart, $timelineEnd, $project);

        return Pdf::loadView('pdf.project-gantt', [
            'project' => $project,
            'milestones' => $milestones,
            'statusCounts' => $statusCounts,
            'weeklyReport' => $weeklyReport,
            'activityCount' => $roots->count(),
            'subTaskCount' => $tasks->whereNotNull('parent_task_id')->count(),
            'timelineStart' => $timelineStart,
            'timelineEnd' => $timelineEnd,
            'generatedAt' => now(),
        ])->setPaper('a3', 'landscape')->stream(Str::slug($project->name).'-gantt-progress.pdf');
    }

    private function buildWeeklyReport($tasks, $milestones, Carbon $timelineStart, Carbon $timelineEnd, $project): array
    {
        $weeks = [];
        $current = $timelineStart->copy()->startOfDay();
        $end = $timelineEnd->copy()->startOfDay();
        $weekIndex = 1;
        $now = now();

        while ($current->lte($end)) {
            $weekStart = $current->copy()->startOfDay();
            $weekEnd = $current->copy()->addDays(6)->endOfDay();
            $isCurrentWeek = $now->betweenIncluded($weekStart, $weekEnd);
            $isPastWeek = $now->gt($weekEnd);
            $isFutureWeek = $now->lt($weekStart);

            $weeks[] = [
                'index' => $weekIndex,
                'label' => "Week {$weekIndex}",
                'start' => $weekStart,
                'end' => $weekEnd,
                'formattedRange' => $weekStart->format('M d') . ' – ' . $weekEnd->format('M d, Y'),
                'isCurrentWeek' => $isCurrentWeek,
                'isPastWeek' => $isPastWeek,
                'isFutureWeek' => $isFutureWeek,
            ];

            $current->addDays(7);
            $weekIndex++;
        }

        if (empty($weeks)) {
            $weekStart = $timelineStart->copy()->startOfDay();
            $weekEnd = $timelineEnd->copy()->endOfDay();
            $weeks[] = [
                'index' => 1,
                'label' => 'Week 1',
                'start' => $weekStart,
                'end' => $weekEnd,
                'formattedRange' => $weekStart->format('M d') . ' – ' . $weekEnd->format('M d, Y'),
                'isCurrentWeek' => true,
                'isPastWeek' => false,
                'isFutureWeek' => false,
            ];
        }

        $currentWeekIdx = 0;
        foreach ($weeks as $i => $w) {
            if ($w['isCurrentWeek']) {
                $currentWeekIdx = $i;
                break;
            }
        }
        if (!$weeks[$currentWeekIdx]['isCurrentWeek']) {
            if ($now->lt($timelineStart)) {
                $currentWeekIdx = 0;
            } else {
                $currentWeekIdx = max(0, count($weeks) - 1);
            }
        }

        $activeWeek = $weeks[$currentWeekIdx];
        $prevWeek = $currentWeekIdx > 0 ? $weeks[$currentWeekIdx - 1] : null;
        $nextWeek = $currentWeekIdx < count($weeks) - 1 ? $weeks[$currentWeekIdx + 1] : null;

        $totalTaskCount = max(1, $tasks->count());

        $calcWeekMetrics = function ($week) use ($tasks, $totalTaskCount) {
            if (!$week) return ['planned' => 0, 'actual' => 0];

            $weekEnd = $week['end'];
            $plannedSum = 0;
            $actualSum = 0;

            foreach ($tasks as $t) {
                $s = $t->start_date ? Carbon::parse($t->start_date)->startOfDay() : null;
                $e = $t->end_date ? Carbon::parse($t->end_date)->endOfDay() : null;
                $prog = (float) ($t->progress ?? 0);

                if ($s && $e) {
                    if ($weekEnd->gte($e)) {
                        $plannedSum += 100;
                    } elseif ($weekEnd->gte($s)) {
                        $totalDuration = max(1, $s->diffInDays($e) + 1);
                        $elapsed = max(0, $s->diffInDays($weekEnd) + 1);
                        $ratio = min(1, $elapsed / $totalDuration);
                        $plannedSum += ($ratio * 100);
                    }
                } else {
                    $plannedSum += ($t->status === 'Done' ? 100 : 0);
                }

                $actualSum += $prog;
            }

            return [
                'planned' => (int) round($plannedSum / $totalTaskCount),
                'actual' => (int) round($actualSum / $totalTaskCount),
            ];
        };

        $currentWeekMetrics = $calcWeekMetrics($activeWeek);
        $prevWeekMetrics = $prevWeek ? $calcWeekMetrics($prevWeek) : ['planned' => 0, 'actual' => 0];

        $currentActual = (int) ($project->progress_percentage ?? $currentWeekMetrics['actual']);
        $prevActual = $prevWeek ? (int) $prevWeekMetrics['actual'] : 0;
        $currentPlanned = $currentWeekMetrics['planned'];
        $prevPlanned = $prevWeek ? (int) $prevWeekMetrics['planned'] : 0;

        $wowActualDelta = $currentActual - $prevActual;
        $variance = $currentActual - $currentPlanned;

        $milestoneComparison = $milestones->map(function ($m) use ($activeWeek, $prevWeek) {
            $rows = $m['rows'] ?? collect();
            $tasks = $rows->pluck('task');
            $count = max(1, $tasks->count());

            $calcMilestoneWeek = function ($week) use ($tasks, $count) {
                if (!$week) return ['planned' => 0, 'actual' => 0];
                $weekEnd = $week['end'];
                $pSum = 0;
                $aSum = 0;
                foreach ($tasks as $t) {
                    $s = $t->start_date ? Carbon::parse($t->start_date)->startOfDay() : null;
                    $e = $t->end_date ? Carbon::parse($t->end_date)->endOfDay() : null;
                    $prog = (float) ($t->progress ?? 0);
                    if ($s && $e) {
                        if ($weekEnd->gte($e)) {
                            $pSum += 100;
                        } elseif ($weekEnd->gte($s)) {
                            $totalDuration = max(1, $s->diffInDays($e) + 1);
                            $elapsed = max(0, $s->diffInDays($weekEnd) + 1);
                            $pSum += (min(1, $elapsed / $totalDuration) * 100);
                        }
                    }
                    $aSum += $prog;
                }
                return [
                    'planned' => (int) round($pSum / $count),
                    'actual' => (int) round($aSum / $count),
                ];
            };

            $currM = $calcMilestoneWeek($activeWeek);
            $prevM = $prevWeek ? $calcMilestoneWeek($prevWeek) : ['planned' => 0, 'actual' => 0];
            $currAct = (int) ($m['progress'] ?? $currM['actual']);
            $prevAct = (int) $prevM['actual'];
            $delta = $currAct - $prevAct;

            return [
                'name' => $m['name'],
                'weight' => $m['weight'],
                'prev_actual' => $prevAct,
                'current_actual' => $currAct,
                'delta' => $delta,
                'planned' => $currM['planned'],
                'status' => $currAct >= 100 ? 'Completed' : ($delta > 0 ? 'Progressing' : ($currAct > 0 ? 'Ongoing' : 'Pending')),
            ];
        });

        $weekStart = $activeWeek['start'];
        $weekEnd = $activeWeek['end'];

        $completedThisWeek = $tasks->filter(function ($t) use ($weekStart, $weekEnd) {
            $isDone = (int) $t->progress >= 100 || strcasecmp((string)$t->status, 'Done') === 0;
            if (!$isDone) return false;
            $e = $t->end_date ? Carbon::parse($t->end_date) : null;
            return $e && $e->betweenIncluded($weekStart, $weekEnd);
        })->values();

        if ($completedThisWeek->isEmpty()) {
            $completedThisWeek = $tasks->filter(fn ($t) => (int) $t->progress >= 100 || strcasecmp((string)$t->status, 'Done') === 0)->take(6)->values();
        }

        $activeThisWeek = $tasks->filter(function ($t) use ($weekStart, $weekEnd) {
            $prog = (int) ($t->progress ?? 0);
            if ($prog >= 100) return false;
            $s = $t->start_date ? Carbon::parse($t->start_date) : null;
            $e = $t->end_date ? Carbon::parse($t->end_date) : null;
            if ($s && $e) {
                return $s->lte($weekEnd) && $e->gte($weekStart);
            }
            return $prog > 0;
        })->take(8)->values();

        $criticalOrOverdue = $tasks->filter(function ($t) use ($now) {
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
            $nextWeekTasks = $tasks->filter(function ($t) use ($nwStart, $nwEnd) {
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
            'milestoneComparison' => $milestoneComparison,
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
