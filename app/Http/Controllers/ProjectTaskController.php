<?php

namespace App\Http\Controllers;

use App\Models\ProjectTask;
use App\Models\Project;
use App\Models\ActivityTemplate;
use App\Models\ProjectTemplate;
use Illuminate\Http\Request;

class ProjectTaskController extends Controller
{
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

        $addedCount = 0;

        foreach ($activities as $activity) {
            // Check if task already exists to prevent duplicates
            $exists = ProjectTask::where('project_id', $project->id)
                ->where('name', $activity->activity)
                ->exists();

            if (!$exists) {
                ProjectTask::create([
                    'project_id' => $project->id,
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
            }
        }

        if ($addedCount > 0) {
            return redirect()->back()->with('success', "Applied {$addedCount} activities from \"{$template->name}\" template successfully.");
        }

        return redirect()->back()->with('info', 'All activities from this template have already been added.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'parent_task_id' => 'nullable',
            'name' => 'required|string|max:255',
            'category' => 'nullable|string',
            'assigned_to' => 'nullable',
            'support_by' => 'nullable',
            'status' => 'required|string',
            'progress' => 'integer|min:0|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'order' => 'integer',
        ]);

        // Convert empty strings to null for database foreign keys
        $validated['parent_task_id'] = ($validated['parent_task_id'] ?? null) ?: null;
        $validated['support_by'] = ($validated['support_by'] ?? null) ?: null;

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

        return redirect()->back()->with('success', 'Task added successfully.');
    }

    public function update(Request $request, ProjectTask $projects_task)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'status' => 'sometimes|required|string',
            'progress' => 'sometimes|integer|min:0|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'assigned_to' => 'nullable',
            'support_by' => 'nullable',
            'order' => 'sometimes|integer',
        ]);

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

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'task' => $projects_task]);
        }

        return redirect()->back()->with('success', 'Task updated successfully.');
    }

    public function destroy(ProjectTask $projects_task)
    {
        $projects_task->delete();

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

        return response()->json(['success' => true]);
    }
}
