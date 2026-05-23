<?php

namespace App\Http\Controllers;

use App\Models\ProjectTask;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Services\ProjectTaskBoardSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectTaskController extends Controller
{
    public function __construct(private ProjectTaskBoardSyncService $projectTaskBoards)
    {
    }

    public function applyTemplates(Request $request, Project $project)
    {
        $request->validate([
            'project_template_id' => 'required|exists:project_templates,id',
        ]);

        $template = ProjectTemplate::with('activities')->findOrFail($request->project_template_id);
        $activities = $template->activities;

        if ($activities->isEmpty()) {
            return redirect()->back()->with('info', 'The selected template has no activities.');
        }

        [$addedCount, $reorderedCount] = DB::transaction(function () use ($project, $activities) {
            $addedCount = 0;
            $reorderedCount = 0;
            $projectTasksByTemplateActivity = [];

            foreach ($activities->filter(fn ($activity) => empty($activity->parent_activity_template_id))->sortBy('order') as $activity) {
                $task = ProjectTask::where('project_id', $project->id)
                    ->whereNull('parent_task_id')
                    ->where('name', $activity->activity)
                    ->where('category', $activity->milestone)
                    ->first();

                if (!$task) {
                    $task = ProjectTask::create([
                        'project_id' => $project->id,
                        'name' => $activity->activity,
                        'category' => $activity->milestone,
                        'asset_item' => $activity->asset_item,
                        'model_specs' => $activity->model_specs,
                        'qty' => $activity->qty,
                        'responsible' => $activity->responsible,
                        'department' => $activity->department,
                        'sub_unit' => $activity->sub_unit,
                        'status' => 'Pending',
                        'progress' => 0,
                        'order' => $activity->order,
                    ]);
                    $addedCount++;
                } elseif ((int) $task->order !== (int) $activity->order) {
                    $task->update(['order' => $activity->order]);
                    $reorderedCount++;
                }

                $projectTasksByTemplateActivity[$activity->id] = $task->fresh();
            }

            foreach ($activities->filter(fn ($activity) => !empty($activity->parent_activity_template_id))->sortBy('order') as $activity) {
                $parentTask = $projectTasksByTemplateActivity[$activity->parent_activity_template_id] ?? null;

                if (!$parentTask) {
                    continue;
                }

                $task = ProjectTask::where('project_id', $project->id)
                    ->where('parent_task_id', $parentTask->id)
                    ->where('name', $activity->activity)
                    ->first();

                if (!$task) {
                    ProjectTask::create([
                        'project_id' => $project->id,
                        'parent_task_id' => $parentTask->id,
                        'name' => $activity->activity,
                        'category' => $activity->milestone,
                        'asset_item' => $activity->asset_item,
                        'model_specs' => $activity->model_specs,
                        'qty' => $activity->qty,
                        'responsible' => $activity->responsible,
                        'status' => 'Pending',
                        'progress' => 0,
                        'order' => $activity->order,
                    ]);
                    $addedCount++;
                } elseif ((int) $task->order !== (int) $activity->order) {
                    $task->update(['order' => $activity->order]);
                    $reorderedCount++;
                }
            }

            return [$addedCount, $reorderedCount];
        });

        $this->projectTaskBoards->syncProject($project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, $request->boolean('auto_create_monthly_boards'));

        if ($addedCount > 0) {
            return redirect()->back()->with('success', "Applied {$addedCount} activities from \"{$template->name}\" template successfully.");
        }

        if ($reorderedCount > 0) {
            return redirect()->back()->with('success', "Reapplied \"{$template->name}\" template sort order successfully.");
        }

        return redirect()->back()->with('info', 'All activities from this template have already been added.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'parent_task_id' => 'nullable|exists:project_tasks,id',
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'assigned_to' => 'nullable',
            'support_by' => 'nullable',
            'status' => 'required|string',
            'progress' => 'integer|min:0|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'order' => 'nullable|integer',
        ]);

        // Convert empty strings to null for database foreign keys
        $validated['parent_task_id'] = ($validated['parent_task_id'] ?? null) ?: null;
        $validated['support_by'] = ($validated['support_by'] ?? null) ?: null;

        if ($validated['parent_task_id']) {
            $parentTask = ProjectTask::findOrFail($validated['parent_task_id']);

            if ((int) $parentTask->project_id !== (int) $validated['project_id']) {
                throw ValidationException::withMessages([
                    'parent_task_id' => 'The selected parent task does not belong to this project.',
                ]);
            }

            if ($parentTask->parent_task_id) {
                throw ValidationException::withMessages([
                    'parent_task_id' => 'Only one sub-task level is supported.',
                ]);
            }

            if (blank($validated['category'] ?? null)) {
                $validated['category'] = $parentTask->category;
            }
        }

        if (!array_key_exists('order', $validated) || $validated['order'] === null) {
            $orderQuery = ProjectTask::where('project_id', $validated['project_id'])
                ->where('parent_task_id', $validated['parent_task_id']);

            if (!$validated['parent_task_id']) {
                $orderQuery->where('category', $validated['category'] ?? null);
            }

            $validated['order'] = ((int) $orderQuery->max('order')) + 1;
        }

        // Logic for Assignment: Handle both User IDs and External Names
        $assignment = ($validated['assigned_to'] ?? null) ?: null;
        if ($assignment) {
            if (is_numeric($assignment)) {
                $validated['assigned_to'] = $assignment;
                $validated['external_assignment'] = null;
            } else {
                $validated['assigned_to'] = null;
                $validated['external_assignment'] = $assignment;
            }
        } else {
            $validated['assigned_to'] = null;
            $validated['external_assignment'] = null;
        }

        $task = ProjectTask::create($validated);
        $this->projectTaskBoards->syncProject($task->project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, $request->boolean('auto_create_monthly_boards'));

        return redirect()->back()->with('success', 'Task added successfully.');
    }

    public function update(Request $request, ProjectTask $projects_task)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|nullable|string|max:255',
            'parent_task_id' => 'sometimes|nullable|exists:project_tasks,id',
            'status' => 'sometimes|required|string',
            'progress' => 'sometimes|integer|min:0|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'assigned_to' => 'nullable',
            'support_by' => 'nullable',
            'order' => 'sometimes|integer',
        ]);

        if (array_key_exists('parent_task_id', $validated)) {
            $validated['parent_task_id'] = $validated['parent_task_id'] ?: null;

            if ($validated['parent_task_id']) {
                $parentTask = ProjectTask::findOrFail($validated['parent_task_id']);

                if ((int) $parentTask->id === (int) $projects_task->id) {
                    throw ValidationException::withMessages([
                        'parent_task_id' => 'A task cannot be its own parent.',
                    ]);
                }

                if ((int) $parentTask->project_id !== (int) $projects_task->project_id) {
                    throw ValidationException::withMessages([
                        'parent_task_id' => 'The selected parent task does not belong to this project.',
                    ]);
                }

                if ($parentTask->parent_task_id) {
                    throw ValidationException::withMessages([
                        'parent_task_id' => 'Only one sub-task level is supported.',
                    ]);
                }

                if ($projects_task->subTasks()->exists()) {
                    throw ValidationException::withMessages([
                        'parent_task_id' => 'An activity with sub-tasks cannot also be a sub-task.',
                    ]);
                }
            }
        }

        if (array_key_exists('support_by', $validated)) {
            $validated['support_by'] = $validated['support_by'] ?: null;
        }

        if (array_key_exists('assigned_to', $validated)) {
            $assignment = $validated['assigned_to'] ?: null;
            if ($assignment) {
                if (is_numeric($assignment)) {
                    $validated['assigned_to'] = $assignment;
                    $validated['external_assignment'] = null;
                } else {
                    $validated['assigned_to'] = null;
                    $validated['external_assignment'] = $assignment;
                }
            } else {
                $validated['assigned_to'] = null;
                $validated['external_assignment'] = null;
            }
        }

        $projects_task->update($validated);
        $this->projectTaskBoards->syncProject($projects_task->project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, $request->boolean('auto_create_monthly_boards'));

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'task' => $projects_task]);
        }

        return redirect()->back()->with('success', 'Task updated successfully.');
    }

    public function destroy(Request $request, ProjectTask $projects_task)
    {
        $project = $projects_task->project;
        $taskIds = $projects_task->subTasks()->pluck('id')->push($projects_task->id);
        $this->projectTaskBoards->archiveProjectTaskCards($taskIds, $request->user());

        $projects_task->subTasks()->delete();
        $projects_task->delete();

        if ($project) {
            $this->projectTaskBoards->syncProject($project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, $request->boolean('auto_create_monthly_boards'));
        }

        return redirect()->back()->with('success', 'Task deleted successfully.');
    }

    public function updateGantt(Request $request)
    {
        // Specialized endpoint for drag-and-drop updates from Gantt Chart
        $validated = $request->validate([
            'tasks' => 'required|array',
            'tasks.*.id' => 'required|exists:project_tasks,id',
            'tasks.*.start_date' => 'nullable|date',
            'tasks.*.end_date' => 'nullable|date',
            'tasks.*.progress' => 'nullable|integer|min:0|max:100',
            'tasks.*.order' => 'nullable|integer|min:0',
        ]);

        foreach ($validated['tasks'] as $taskData) {
            $updates = [];

            if (array_key_exists('start_date', $taskData)) {
                $updates['start_date'] = $taskData['start_date'];
            }
            if (array_key_exists('end_date', $taskData)) {
                $updates['end_date'] = $taskData['end_date'];
            }
            if (array_key_exists('progress', $taskData)) {
                $updates['progress'] = $taskData['progress'] ?? 0;
            }
            if (array_key_exists('order', $taskData)) {
                $updates['order'] = $taskData['order'];
            }

            if (!empty($updates)) {
                ProjectTask::where('id', $taskData['id'])->update($updates);
            }
        }

        $this->projectTaskBoards->syncProjectTaskIds(collect($validated['tasks'])->pluck('id'), $request->user(), $request->boolean('auto_create_monthly_boards'));

        return response()->json(['success' => true]);
    }
}
