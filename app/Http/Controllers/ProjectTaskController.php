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
    public function __construct(
        private ProjectTaskBoardSyncService $projectTaskBoards,
        private \App\Services\NotificationService $notifications
    )
    {
    }

    public function applyTemplates(Request $request, Project $project)
    {
        // Applying a template rewrites the milestone/activity structure — a
        // management action. Only the owner/admin may do it.
        abort_unless($project->isManagedBy($request->user()), 403, 'You do not have permission to modify this project.');

        $request->validate([
            'project_template_id' => 'required|exists:project_templates,id',
        ]);

        $template = ProjectTemplate::with('activities')->findOrFail($request->project_template_id);
        $activities = $this->withResolvedMilestoneOrders($template->activities);

        if ($activities->isEmpty()) {
            return redirect()->back()->with('info', 'The selected template has no activities.');
        }

        $actorId = $request->user()->id;

        [$addedCount, $reorderedCount] = DB::transaction(function () use ($project, $activities, $actorId) {
            $addedCount = 0;
            $reorderedCount = 0;
            $projectTasksByTemplateActivity = [];

            foreach ($activities->filter(fn ($activity) => empty($activity->parent_activity_template_id))->sortBy([
                ['milestone_order', 'asc'],
                ['order', 'asc'],
                ['id', 'asc'],
            ]) as $activity) {
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
                        'milestone_order' => $activity->milestone_order,
                        'asset_item' => $activity->asset_item,
                        'model_specs' => $activity->model_specs,
                        'qty' => $activity->qty,
                        'responsible' => $activity->responsible,
                        'department' => $activity->department,
                        'sub_unit' => $activity->sub_unit,
                        'status' => 'Pending',
                        'progress' => 0,
                        'order' => $activity->order,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]);
                    $addedCount++;
                } elseif ((int) $task->order !== (int) $activity->order || (int) $task->milestone_order !== (int) $activity->milestone_order) {
                    $task->update([
                        'milestone_order' => $activity->milestone_order,
                        'order' => $activity->order,
                    ]);
                    $reorderedCount++;
                }

                $projectTasksByTemplateActivity[$activity->id] = $task->fresh();
            }

            foreach ($activities->filter(fn ($activity) => !empty($activity->parent_activity_template_id))->sortBy([
                ['milestone_order', 'asc'],
                ['order', 'asc'],
                ['id', 'asc'],
            ]) as $activity) {
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
                        'milestone_order' => $activity->milestone_order,
                        'asset_item' => $activity->asset_item,
                        'model_specs' => $activity->model_specs,
                        'qty' => $activity->qty,
                        'responsible' => $activity->responsible,
                        'status' => 'Pending',
                        'progress' => 0,
                        'order' => $activity->order,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]);
                    $addedCount++;
                } elseif ((int) $task->order !== (int) $activity->order || (int) $task->milestone_order !== (int) $activity->milestone_order) {
                    $task->update([
                        'milestone_order' => $activity->milestone_order,
                        'order' => $activity->order,
                    ]);
                    $reorderedCount++;
                }
            }

            return [$addedCount, $reorderedCount];
        });

        $this->projectTaskBoards->syncProject($project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, $request->boolean('auto_create_monthly_boards'));
        $this->projectTaskBoards->syncLinkedBoardItemsFromProject($project->fresh());

        if ($addedCount > 0) {
            return redirect()->back()->with('success', "Applied {$addedCount} activities from \"{$template->name}\" template successfully.");
        }

        if ($reorderedCount > 0) {
            return redirect()->back()->with('success', "Reapplied \"{$template->name}\" template sort order successfully.");
        }

        return redirect()->back()->with('info', 'All activities from this template have already been added.');
    }

    private function withResolvedMilestoneOrders($activities)
    {
        $ordersByMilestone = [];
        $nextOrder = 1;

        $activities
            ->filter(fn ($activity) => empty($activity->parent_activity_template_id))
            ->sortBy([
                ['milestone_order', 'asc'],
                ['order', 'asc'],
                ['id', 'asc'],
            ])
            ->each(function ($activity) use (&$ordersByMilestone, &$nextOrder) {
                $milestone = $activity->milestone ?: 'General';

                if (!array_key_exists($milestone, $ordersByMilestone)) {
                    $ordersByMilestone[$milestone] = filled($activity->milestone_order)
                        ? (int) $activity->milestone_order
                        : $nextOrder;
                    $nextOrder = max($nextOrder, $ordersByMilestone[$milestone] + 1);
                }
            });

        return $activities->map(function ($activity) use ($ordersByMilestone) {
            $milestone = $activity->milestone ?: 'General';
            $activity->milestone_order = filled($activity->milestone_order)
                ? (int) $activity->milestone_order
                : ($ordersByMilestone[$milestone] ?? 1);

            return $activity;
        });
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'parent_task_id' => 'nullable|exists:project_tasks,id',
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'milestone_order' => 'nullable|integer|min:0',
            'assigned_to' => 'nullable',
            'support_by' => 'nullable',
            'status' => 'required|string',
            'progress' => 'integer|min:0|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'order' => 'nullable|integer',
        ]);

        // Adding new milestones / activities / sub-tasks is a management action.
        $project = Project::findOrFail($validated['project_id']);
        abort_unless($project->isManagedBy($request->user()), 403, 'You do not have permission to add tasks to this project.');

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

            if (blank($validated['milestone_order'] ?? null)) {
                $validated['milestone_order'] = $parentTask->milestone_order;
            }
        }

        if (!$validated['parent_task_id'] && blank($validated['milestone_order'] ?? null)) {
            $validated['milestone_order'] = ((int) ProjectTask::where('project_id', $validated['project_id'])
                ->whereNull('parent_task_id')
                ->max('milestone_order')) + 1;
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

        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $task = ProjectTask::create($validated);
        $this->projectTaskBoards->syncProject($task->project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, $request->boolean('auto_create_monthly_boards'));
        $this->projectTaskBoards->syncLinkedBoardItemsFromProject($task->project);

        // Notify the assignee + project team that a new activity/sub-task was added.
        $kind = $task->parent_task_id ? 'sub-task' : 'activity';
        $this->notifications->notifyProjectTask(
            $task,
            'created',
            'New ' . $kind . ' added',
            ($task->project?->name ? $task->project->name . ': ' : '') . "new {$kind} \"" . \Illuminate\Support\Str::limit($task->name, 50) . '"',
            $request->user()->id
        );

        return redirect()->back()->with('success', 'Task added successfully.');
    }

    public function update(Request $request, ProjectTask $projects_task)
    {
        // A project manager may edit any row; everyone else only the activity /
        // sub-task assigned to them.
        abort_unless($projects_task->isEditableBy($request->user()), 403, 'You can only edit rows assigned to you.');

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|nullable|string|max:255',
            'milestone_order' => 'sometimes|nullable|integer|min:0',
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

        $oldStatus = $projects_task->status;
        $oldProgress = (int) $projects_task->progress;
        $oldAssignee = $projects_task->assigned_to;

        $validated['updated_by'] = $request->user()->id;

        $projects_task->update($validated);
        $this->projectTaskBoards->syncProject($projects_task->project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, $request->boolean('auto_create_monthly_boards'));
        $this->projectTaskBoards->syncLinkedBoardItemsFromProject($projects_task->project);

        // ── In-app (bell) notifications ──
        $actorId = $request->user()->id;
        $taskLabel = \Illuminate\Support\Str::limit($projects_task->name, 50);

        if (array_key_exists('status', $validated) && $oldStatus !== $projects_task->status) {
            $this->notifications->notifyProjectTask(
                $projects_task,
                'status',
                'Task status changed',
                "{$taskLabel}: {$oldStatus} → {$projects_task->status}",
                $actorId,
                [],
                $projects_task->status === 'Completed' ? 'success' : 'info'
            );
        } elseif (array_key_exists('progress', $validated) && $oldProgress !== (int) $projects_task->progress) {
            $this->notifications->notifyProjectTask(
                $projects_task,
                'progress',
                'Task progress updated',
                "{$taskLabel}: {$oldProgress}% → {$projects_task->progress}%",
                $actorId
            );
        }

        if ($projects_task->assigned_to && (int) $projects_task->assigned_to !== (int) $oldAssignee) {
            $this->notifications->dispatch([$projects_task->assigned_to], $actorId, [
                'domain' => 'project_task',
                'event' => 'assignment',
                'title' => 'Assigned to a task',
                'message' => 'You were assigned to ' . $taskLabel,
                'subject' => 'project_task:' . $projects_task->id,
                'url' => route('projects.show', $projects_task->project_id, false),
            ]);
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'task' => $projects_task]);
        }

        return redirect()->back()->with('success', 'Task updated successfully.');
    }

    public function destroy(Request $request, ProjectTask $projects_task)
    {
        $project = $projects_task->project;

        // Deleting a row (and its sub-tasks) is a management action.
        abort_unless($project && $project->isManagedBy($request->user()), 403, 'You do not have permission to delete tasks in this project.');

        $taskIds = $projects_task->subTasks()->pluck('id')->push($projects_task->id);
        $this->projectTaskBoards->archiveProjectTaskCards($taskIds, $request->user());
        $this->projectTaskBoards->removeBoardItemsForProjectTasks($taskIds);

        $projects_task->subTasks()->delete();
        $projects_task->delete();

        if ($project) {
            $this->projectTaskBoards->syncProject($project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, $request->boolean('auto_create_monthly_boards'));
        }

        return redirect()->back()->with('success', 'Task deleted successfully.');
    }

    public function destroyMilestone(Request $request, Project $project)
    {
        abort_unless($project->isManagedBy($request->user()), 403, 'You do not have permission to delete milestones in this project.');

        $validated = $request->validate([
            'category' => 'required|string|max:255',
        ]);

        $category = $validated['category'] ?: 'General';

        $deletedCount = DB::transaction(function () use ($request, $project, $category) {
            $topLevelTasks = ProjectTask::query()
                ->where('project_id', $project->id)
                ->whereNull('parent_task_id')
                ->where(function ($query) use ($category) {
                    if ($category === 'General') {
                        $query->whereNull('category')->orWhere('category', 'General');
                    } else {
                        $query->where('category', $category);
                    }
                })
                ->with('subTasks:id,parent_task_id')
                ->get();

            if ($topLevelTasks->isEmpty()) {
                return 0;
            }

            $taskIds = $topLevelTasks
                ->flatMap(fn (ProjectTask $task) => $task->subTasks->pluck('id')->push($task->id))
                ->values();

            $this->projectTaskBoards->archiveProjectTaskCards($taskIds, $request->user());

            ProjectTask::query()
                ->whereIn('parent_task_id', $topLevelTasks->pluck('id'))
                ->delete();

            ProjectTask::query()
                ->whereIn('id', $topLevelTasks->pluck('id'))
                ->delete();

            return $taskIds->count();
        });

        if ($deletedCount > 0) {
            $this->projectTaskBoards->syncProject($project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, $request->boolean('auto_create_monthly_boards'));
        }

        return redirect()->back()->with('success', 'Milestone deleted successfully.');
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
            'tasks.*.milestone_order' => 'nullable|integer|min:0',
            'tasks.*.order' => 'nullable|integer|min:0',
        ]);

        // Reordering / bulk timeline edits span the whole plan — a management action.
        // Every task in the batch must belong to a project the user manages.
        $projects = ProjectTask::whereIn('id', collect($validated['tasks'])->pluck('id'))
            ->with('project')
            ->get()
            ->pluck('project')
            ->filter()
            ->unique('id');

        foreach ($projects as $project) {
            abort_unless($project->isManagedBy($request->user()), 403, 'You do not have permission to reorder tasks in this project.');
        }

        $progressChanges = [];

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
            if (array_key_exists('milestone_order', $taskData)) {
                $updates['milestone_order'] = $taskData['milestone_order'];
            }
            if (array_key_exists('order', $taskData)) {
                $updates['order'] = $taskData['order'];
            }

            if (empty($updates)) {
                continue;
            }

            // Track progress changes (load the model only when % is part of the update)
            // so we can notify the assignee/team without spamming on pure date/order drags.
            if (array_key_exists('progress', $taskData)) {
                $task = ProjectTask::find($taskData['id']);
                if ($task) {
                    $oldProgress = (int) $task->progress;
                    $task->update($updates);
                    if ((int) $task->progress !== $oldProgress) {
                        $progressChanges[] = [$task, $oldProgress];
                    }
                }
            } else {
                ProjectTask::where('id', $taskData['id'])->update($updates);
            }
        }

        $this->projectTaskBoards->syncProjectTaskIds(collect($validated['tasks'])->pluck('id'), $request->user(), $request->boolean('auto_create_monthly_boards'));

        $firstTask = ProjectTask::find(collect($validated['tasks'])->pluck('id')->first());
        if ($firstTask?->project) {
            $this->projectTaskBoards->syncLinkedBoardItemsFromProject($firstTask->project);
        }

        // Notify assignee + team for each task whose progress % actually changed.
        foreach ($progressChanges as [$task, $oldProgress]) {
            $this->notifications->notifyProjectTask(
                $task,
                'progress',
                'Task progress updated',
                \Illuminate\Support\Str::limit($task->name, 50) . ": {$oldProgress}% → {$task->progress}%",
                $request->user()->id,
                [],
                (int) $task->progress >= 100 ? 'success' : 'info'
            );
        }

        return response()->json(['success' => true]);
    }
}
