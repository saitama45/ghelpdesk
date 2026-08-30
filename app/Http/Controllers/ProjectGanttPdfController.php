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

        return Pdf::loadView('pdf.project-gantt', [
            'project' => $project,
            'milestones' => $milestones,
            'statusCounts' => $statusCounts,
            'activityCount' => $roots->count(),
            'subTaskCount' => $tasks->whereNotNull('parent_task_id')->count(),
            'timelineStart' => $timelineStart,
            'timelineEnd' => $timelineEnd,
            'generatedAt' => now(),
        ])->setPaper('a3', 'landscape')->stream(Str::slug($project->name).'-gantt-progress.pdf');
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
