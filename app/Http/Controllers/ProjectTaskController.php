<?php

namespace App\Http\Controllers;

use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Store;
use App\Services\ProjectTaskBoardSyncService;
use App\Support\ProjectPlanAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectTaskController extends Controller
{
    public function __construct(
        private ProjectTaskBoardSyncService $projectTaskBoards,
        private \App\Services\NotificationService $notifications,
        private \App\Services\ProjectScheduler $scheduler
    )
    {
    }

    /** Blank manual_status means "no flag" — store NULL, never an empty string. */
    private function normaliseManualStatus(Request $request): void
    {
        if ($request->has('manual_status') && blank($request->input('manual_status'))) {
            $request->merge(['manual_status' => null]);
        }
    }

    public function applyTemplates(Request $request, Project $project)
    {
        // Applying a template rewrites the milestone/activity structure — a
        // management action. Only the owner/admin may do it.
        abort_unless($project->isManagedBy($request->user()), 403, 'You do not have permission to modify this project.');

        $request->validate([
            'project_template_id' => 'required|exists:project_templates,id',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'integer|distinct|exists:stores,id',
        ]);

        $template = ProjectTemplate::with('activities')->findOrFail($request->project_template_id);

        // The same rule that decides which templates the modal lists, so the list
        // and this guard can never disagree — they used to, and a template the list
        // offered was refused here with an error nothing rendered.
        if ($reason = $template->applicabilityErrorFor($project)) {
            return redirect()->back()->withErrors(['project_template_id' => $reason]);
        }
        $activities = $this->withResolvedMilestoneOrders($template->activities);

        if ($activities->isEmpty()) {
            return redirect()->back()->with('info', 'The selected template has no activities.');
        }

        if ($activities->contains(fn ($activity) => $activity->activity_mode === 'per_store')) {
            return $this->applyPerStoreTemplate($request, $project, $template, $activities);
        }

        $actorId = $request->user()->id;
        $schedule = $this->buildTemplateSchedule($activities, $project);

        [$addedCount, $reorderedCount] = DB::transaction(function () use ($project, $activities, $actorId, $schedule) {
            $addedCount = 0;
            $reorderedCount = 0;
            $projectTasksByTemplateActivity = [];

            foreach ($activities->filter(fn ($activity) => empty($activity->parent_activity_template_id))->sortBy([
                ['milestone_order', 'asc'],
                ['order', 'asc'],
                ['id', 'asc'],
            ]) as $activity) {
                $dates = $schedule[$activity->id] ?? null;

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
                        'start_date' => $dates['start'] ?? null,
                        'end_date' => $dates['end'] ?? null,
                        'lead_time_days' => $activity->default_duration_days,
                        'can_run_parallel' => (bool) $activity->can_run_parallel,
                        'activity_mode' => $activity->activity_mode,
                        'milestone_weight' => $activity->milestone_weight,
                        'activity_weight' => $activity->activity_weight,
                        'sub_task_weight' => $activity->sub_task_weight,
                        'acceptance_criteria' => $activity->acceptance_criteria,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]);
                    $addedCount++;
                } else {
                    $changedOrder = (float) $task->order !== (float) $activity->order || (int) $task->milestone_order !== (int) $activity->milestone_order;
                    if ($changedOrder) {
                        $reorderedCount++;
                    }
                    if ($dates) {
                        $task->update([
                            'milestone_order' => $activity->milestone_order,
                            'order' => $activity->order,
                            'start_date' => $dates['start'],
                            'end_date' => $dates['end'],
                            'lead_time_days' => $activity->default_duration_days,
                            'can_run_parallel' => (bool) $activity->can_run_parallel,
                            'activity_mode' => $activity->activity_mode,
                            'milestone_weight' => $activity->milestone_weight,
                            'activity_weight' => $activity->activity_weight,
                            'sub_task_weight' => $activity->sub_task_weight,
                            'acceptance_criteria' => $activity->acceptance_criteria,
                        ]);
                    } elseif ($changedOrder) {
                        $task->update([
                            'milestone_order' => $activity->milestone_order,
                            'order' => $activity->order,
                        ]);
                    }
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

                $dates = $schedule[$activity->id] ?? null;

                $task = ProjectTask::where('project_id', $project->id)
                    ->where('parent_task_id', $parentTask->id)
                    ->where('name', $activity->activity)
                    ->first();

                if (!$task) {
                    $task = ProjectTask::create([
                        'project_id' => $project->id,
                        'parent_task_id' => $parentTask->id,
                        'name' => $activity->activity,
                        'category' => $activity->milestone,
                        'milestone_order' => $activity->milestone_order,
                        'asset_item' => $activity->asset_item,
                        'model_specs' => $activity->model_specs,
                        'qty' => $activity->qty,
                        'responsible' => $activity->responsible,
                        // Sub-tasks used to be created without these, so every
                        // template sub-task landed with a NULL department and
                        // dropped out of any department roll-up. Fall back to the
                        // parent activity when the template row leaves it blank.
                        'department' => blank($activity->department) ? $parentTask->department : $activity->department,
                        'sub_unit' => blank($activity->sub_unit) ? $parentTask->sub_unit : $activity->sub_unit,
                        'status' => 'Pending',
                        'progress' => 0,
                        'order' => $activity->order,
                        'start_date' => $dates['start'] ?? null,
                        'end_date' => $dates['end'] ?? null,
                        'lead_time_days' => $activity->default_duration_days,
                        'can_run_parallel' => (bool) $activity->can_run_parallel,
                        'activity_mode' => $activity->activity_mode,
                        'milestone_weight' => $activity->milestone_weight,
                        'activity_weight' => $activity->activity_weight,
                        'sub_task_weight' => $activity->sub_task_weight,
                        'acceptance_criteria' => $activity->acceptance_criteria,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]);
                    $addedCount++;
                } else {
                    $changedOrder = (float) $task->order !== (float) $activity->order || (int) $task->milestone_order !== (int) $activity->milestone_order;
                    if ($changedOrder) {
                        $reorderedCount++;
                    }
                    if ($dates) {
                        $task->update([
                            'milestone_order' => $activity->milestone_order,
                            'order' => $activity->order,
                            'start_date' => $dates['start'],
                            'end_date' => $dates['end'],
                            'lead_time_days' => $activity->default_duration_days,
                            'can_run_parallel' => (bool) $activity->can_run_parallel,
                            'activity_mode' => $activity->activity_mode,
                            'milestone_weight' => $activity->milestone_weight,
                            'activity_weight' => $activity->activity_weight,
                            'sub_task_weight' => $activity->sub_task_weight,
                            'acceptance_criteria' => $activity->acceptance_criteria,
                        ]);
                    } elseif ($changedOrder) {
                        $task->update([
                            'milestone_order' => $activity->milestone_order,
                            'order' => $activity->order,
                        ]);
                    }

                    // Existing sub-tasks created before the fix above have no
                    // department at all. Fill only the blanks; never overwrite a
                    // department a project manager set by hand.
                    if (blank($task->department)) {
                        $task->update([
                            'department' => blank($activity->department) ? $parentTask->department : $activity->department,
                            'sub_unit' => blank($activity->sub_unit) ? $parentTask->sub_unit : $activity->sub_unit,
                        ]);
                    }
                }

                $projectTasksByTemplateActivity[$activity->id] = $task->fresh();
            }

            // Requisites can point at any row, so they can only be wired once
            // every activity in the template has a task to point at.
            foreach ($activities as $activity) {
                $task = $projectTasksByTemplateActivity[$activity->id] ?? null;
                $requisite = $activity->depends_on_template_id
                    ? ($projectTasksByTemplateActivity[$activity->depends_on_template_id] ?? null)
                    : null;

                if (!$task) {
                    continue;
                }

                $requisiteId = $requisite?->id;

                if ((int) $task->depends_on_task_id !== (int) $requisiteId) {
                    $task->update(['depends_on_task_id' => $requisiteId]);
                }
            }

            return [$addedCount, $reorderedCount];
        });

        // The template lays rows down back-to-back; re-chaining afterwards folds
        // in the parent rollups and any Start Dates the user has pinned.
        $this->rescheduleProjectTasks($project->fresh());

        $this->projectTaskBoards->syncProject($project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, $request->boolean('auto_create_monthly_boards'));
        $this->projectTaskBoards->syncLinkedBoardItemsFromProject($project->fresh());

        $scheduleNote = $project->day1_date
            ? ''
            : ' Set a Day 1 Date on the project to auto-schedule Start/End dates next time.';

        if ($addedCount > 0) {
            return redirect()->back()->with('success', "Applied {$addedCount} activities from \"{$template->name}\" template successfully.{$scheduleNote}");
        }

        if ($reorderedCount > 0) {
            return redirect()->back()->with('success', "Reapplied \"{$template->name}\" template sort order successfully.{$scheduleNote}");
        }

        if ($project->day1_date) {
            return redirect()->back()->with('success', "Rescheduled activities from \"{$template->name}\" template using Day 1 Date " . $project->day1_date->format('M j, Y') . '.');
        }

        return redirect()->back()->with('info', 'All activities from this template have already been added.' . $scheduleNote);
    }

    /**
     * Apply a mixed Standard / Per Store template.
     *
     * Standard rows are created once. A Per Store root and all of its children
     * are cloned for each selected real store, with store_id as the durable row
     * identity. Reapplying the template is therefore safe and supports rollout
     * waves without manufacturing placeholder stores.
     */
    private function applyPerStoreTemplate(Request $request, Project $project, ProjectTemplate $template, $activities)
    {
        $storeIds = collect($request->input('store_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($storeIds->isEmpty()) {
            throw ValidationException::withMessages([
                'store_ids' => 'Select at least one rollout store for the Per Store activities.',
            ]);
        }

        $eligibleStores = Store::query()
            ->whereIn('id', $storeIds)
            ->where('is_active', true)
            ->when($project->brand_company_id, fn ($query, $brandId) => $query->where('company_id', $brandId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        if ($eligibleStores->count() !== $storeIds->count()) {
            throw ValidationException::withMessages([
                'store_ids' => 'Every rollout store must be active and belong to this project brand.',
            ]);
        }

        $existingStoreIds = ProjectTask::query()
            ->where('project_id', $project->id)
            ->where('activity_mode', 'per_store')
            ->whereNotNull('store_id')
            ->distinct()
            ->pluck('store_id')
            ->map(fn ($id) => (int) $id);
        $rolloutStoreCount = $existingStoreIds->merge($storeIds)->unique()->count();

        if ($project->target_store_count && $rolloutStoreCount > $project->target_store_count) {
            throw ValidationException::withMessages([
                'store_ids' => "The selected rollout scope exceeds this project's {$project->target_store_count}-store target.",
            ]);
        }

        $actorId = $request->user()->id;
        $schedule = $this->buildTemplateSchedule($activities, $project);

        [$addedCount, $reorderedCount] = DB::transaction(function () use (
            $project, $activities, $storeIds, $actorId, $schedule
        ) {
            $addedCount = 0;
            $reorderedCount = 0;
            $tasksByTemplate = [];
            $roots = $activities->filter(fn ($activity) => empty($activity->parent_activity_template_id))->sortBy([
                ['milestone_order', 'asc'], ['order', 'asc'], ['id', 'asc'],
            ]);

            foreach ($roots as $activity) {
                $contexts = $activity->activity_mode === 'per_store' ? $storeIds : collect([null]);

                foreach ($contexts as $contextIndex => $storeId) {
                    [$task, $created, $reordered] = $this->upsertExpandedTemplateTask(
                        $project, $activity, null, $storeId, $actorId,
                        $schedule[$activity->id] ?? null,
                        $contextIndex === 0
                    );
                    $tasksByTemplate[$activity->id][$storeId ?: 'standard'] = $task;
                    $addedCount += $created ? 1 : 0;
                    $reorderedCount += $reordered ? 1 : 0;
                }
            }

            $children = $activities->filter(fn ($activity) => ! empty($activity->parent_activity_template_id))->sortBy([
                ['milestone_order', 'asc'], ['order', 'asc'], ['id', 'asc'],
            ]);

            foreach ($children as $activity) {
                $parentContexts = $tasksByTemplate[$activity->parent_activity_template_id] ?? [];

                foreach ($parentContexts as $context => $parentTask) {
                    $storeId = $context === 'standard' ? null : (int) $context;
                    [$task, $created, $reordered] = $this->upsertExpandedTemplateTask(
                        $project, $activity, $parentTask, $storeId, $actorId,
                        $schedule[$activity->id] ?? null,
                        true
                    );
                    $tasksByTemplate[$activity->id][$context] = $task;
                    $addedCount += $created ? 1 : 0;
                    $reorderedCount += $reordered ? 1 : 0;
                }
            }

            // Resolve each requisite in the same store context. A Standard row
            // after a Per Store activity waits for the last clone; all clones
            // share the same planned span, so this represents wave completion.
            foreach ($activities as $activity) {
                foreach ($tasksByTemplate[$activity->id] ?? [] as $context => $task) {
                    $requisites = $activity->depends_on_template_id
                        ? ($tasksByTemplate[$activity->depends_on_template_id] ?? [])
                        : [];
                    $requisite = $requisites[$context]
                        ?? ($requisites['standard'] ?? (empty($requisites) ? null : end($requisites)));
                    $parallel = (bool) $activity->can_run_parallel;

                    // If a Per Store root has no explicit requisite, anchor later
                    // stores to the first clone in parallel instead of stretching
                    // a 35-store rollout into 35 sequential copies.
                    if (! $requisite && empty($activity->parent_activity_template_id) && count($tasksByTemplate[$activity->id] ?? []) > 1) {
                        $first = reset($tasksByTemplate[$activity->id]);
                        if ($first && $first->id !== $task->id) {
                            $requisite = $first;
                            $parallel = true;
                        }
                    }

                    $updates = [];
                    if ((int) $task->depends_on_task_id !== (int) $requisite?->id) {
                        $updates['depends_on_task_id'] = $requisite?->id;
                    }
                    if ((bool) $task->can_run_parallel !== $parallel) {
                        $updates['can_run_parallel'] = $parallel;
                    }
                    if ($updates !== []) {
                        $task->update($updates);
                    }
                }
            }

            return [$addedCount, $reorderedCount];
        });

        $this->rescheduleProjectTasks($project->fresh());
        $this->projectTaskBoards->syncProject(
            $project->fresh(['teamMembers.user', 'tasks']),
            $request->user(),
            null,
            $request->boolean('auto_create_monthly_boards')
        );
        $this->projectTaskBoards->syncLinkedBoardItemsFromProject($project->fresh());

        $target = $project->target_store_count ?: $rolloutStoreCount;
        $scope = "{$rolloutStoreCount}/{$target} rollout stores selected";
        $scheduleNote = $project->day1_date
            ? ''
            : ' Set a Day 1 Date on the project to auto-schedule Start/End dates next time.';

        if ($addedCount > 0) {
            return redirect()->back()->with('success', "Applied {$template->name}: {$addedCount} rows added; {$scope}.{$scheduleNote}");
        }

        if ($reorderedCount > 0 || $project->day1_date) {
            return redirect()->back()->with('success', "Reapplied {$template->name}; {$scope}.{$scheduleNote}");
        }

        return redirect()->back()->with('info', "All selected store rows already exist; {$scope}.{$scheduleNote}");
    }

    /** @return array{0: ProjectTask, 1: bool, 2: bool} */
    private function upsertExpandedTemplateTask(
        Project $project,
        $activity,
        ?ProjectTask $parentTask,
        ?int $storeId,
        int $actorId,
        ?array $dates,
        bool $mayAdoptLegacyRow
    ): array {
        $query = ProjectTask::query()
            ->where('project_id', $project->id)
            ->where('parent_task_id', $parentTask?->id)
            ->where('name', $activity->activity);

        if (! $parentTask) {
            $query->where('category', $activity->milestone);
        }

        $task = (clone $query)->where('store_id', $storeId)->first();

        // A Per Store template applied before store-aware rollout created one
        // generic row. Adopt it as the first selected store instead of leaving a
        // duplicate legacy row beside the new clones.
        if (! $task && $storeId && $mayAdoptLegacyRow) {
            $task = (clone $query)
                ->whereNull('store_id')
                ->where('activity_mode', 'per_store')
                ->first();
            if ($task) {
                $task->update(['store_id' => $storeId]);
            }
        }

        $department = blank($activity->department) ? $parentTask?->department : $activity->department;
        $subUnit = blank($activity->sub_unit) ? $parentTask?->sub_unit : $activity->sub_unit;

        if (! $task) {
            $task = ProjectTask::create([
                'project_id' => $project->id,
                'store_id' => $storeId,
                'parent_task_id' => $parentTask?->id,
                'name' => $activity->activity,
                'category' => $activity->milestone,
                'milestone_order' => $activity->milestone_order,
                'asset_item' => $activity->asset_item,
                'model_specs' => $activity->model_specs,
                'qty' => $activity->qty,
                'responsible' => $activity->responsible,
                'department' => $department,
                'sub_unit' => $subUnit,
                'status' => 'Pending',
                'progress' => 0,
                'order' => $activity->order,
                'start_date' => $dates['start'] ?? null,
                'end_date' => $dates['end'] ?? null,
                'lead_time_days' => $activity->default_duration_days,
                'can_run_parallel' => (bool) $activity->can_run_parallel,
                'activity_mode' => $activity->activity_mode,
                'milestone_weight' => $activity->milestone_weight,
                'activity_weight' => $activity->activity_weight,
                'sub_task_weight' => $activity->sub_task_weight,
                'acceptance_criteria' => $activity->acceptance_criteria,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            return [$task, true, false];
        }

        $reordered = (float) $task->order !== (float) $activity->order
            || (int) $task->milestone_order !== (int) $activity->milestone_order;
        $updates = [
            'milestone_order' => $activity->milestone_order,
            'order' => $activity->order,
            'lead_time_days' => $activity->default_duration_days,
            'activity_mode' => $activity->activity_mode,
            'milestone_weight' => $activity->milestone_weight,
            'activity_weight' => $activity->activity_weight,
            'sub_task_weight' => $activity->sub_task_weight,
            'acceptance_criteria' => $activity->acceptance_criteria,
            'updated_by' => $actorId,
        ];
        if ($dates) {
            $updates['start_date'] = $dates['start'];
            $updates['end_date'] = $dates['end'];
        }
        if (blank($task->department)) {
            $updates['department'] = $department;
            $updates['sub_unit'] = $subUnit;
        }
        $task->update($updates);

        return [$task->fresh(), false, $reordered];
    }

    /**
     * Saving from the Gantt tab must land back on the Gantt tab, not bounce the
     * user to Overview. The requests carry ?tab=, so redirect to the project
     * page with it rather than to a bare referer.
     */
    private function backToTab(Request $request, ?int $projectId, string $type, string $message)
    {
        $tab = $request->query('tab');

        if ($tab && $projectId) {
            return redirect()
                ->route('projects.show', ['project' => $projectId, 'tab' => $tab])
                ->with($type, $message);
        }

        return redirect()->back()->with($type, $message);
    }

    /**
     * Return only the plan data needed to repaint the open Gantt. This avoids a
     * full projects.show reload, whose workspace and report props are much more
     * expensive than saving one activity.
     */
    private function ganttSaveResponse(Project $project, string $message, int $status = 200)
    {
        $tasks = $project->tasks()
            ->with([
                'store:id,code,name',
                'assignedUser:id,name,profile_photo,org_path',
                'supportUser:id,name,profile_photo,org_path',
            ])
            ->get();

        $milestones = $project->milestones()
            ->with('assignedUser:id,name')
            ->get()
            ->map(fn (ProjectMilestone $milestone) => [
                'category' => $milestone->category,
                'assigned_to' => $milestone->assigned_to,
                'owner_name' => $milestone->assignedUser?->name,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'message' => $message,
            'tasks' => $tasks,
            'milestones' => $milestones,
        ], $status);
    }

    private function calculatorFor(?Project $project): \App\Services\ScheduleCalculator
    {
        return $this->scheduler->calculatorFor($project);
    }

    /**
     * Unpoints every requisite aimed at $taskIds. Tasks soft-delete, so the FK
     * would survive either way — but a pointer at a row nobody can see any more
     * shows up as a blank requisite on the Gantt, so clear it properly.
     */
    private function releaseRequisitePointers(\Illuminate\Support\Collection $taskIds): void
    {
        if ($taskIds->isEmpty()) {
            return;
        }

        ProjectTask::whereIn('depends_on_task_id', $taskIds)
            ->update(['depends_on_task_id' => null]);
    }

    /**
     * Chain each template row's Start/End Date from the project's Day 1 Date.
     * Rows are placed requisite-first — see ScheduleChain for the Can Run
     * Parallel rule. Returns [] when no Day 1 Date is set.
     *
     * @return array<int, array{start: string, end: string, days: int}>
     */
    private function buildTemplateSchedule($activities, ?Project $project): array
    {
        return $this->scheduler->scheduleForTemplate($activities, $project);
    }

    private function syncParentRollups(Project $project): void
    {
        $this->scheduler->syncParentRollups($project);
    }

    private function rescheduleProjectTasks(Project $project): array
    {
        return $this->scheduler->reschedule($project);
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
        // The Gantt's "None" option posts an empty string; the in: rule only
        // accepts a real status or null.
        $this->normaliseManualStatus($request);

        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'parent_task_id' => 'nullable|exists:project_tasks,id',
            'depends_on_task_id' => 'nullable|exists:project_tasks,id',
            'can_run_parallel' => 'nullable|boolean',
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'milestone_order' => 'nullable|integer|min:0',
            'assigned_to' => 'nullable',
            'support_by' => 'nullable',
            'status' => 'required|string',
            'manual_status' => 'nullable|string|in:' . implode(',', ProjectTask::manualStatuses()),
            'progress' => 'integer|min:0|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'lead_time_days' => 'nullable|integer|min:1',
            'order' => 'nullable|numeric',
        ]);

        $project = Project::findOrFail($validated['project_id']);

        // Convert empty strings to null for database foreign keys
        $validated['parent_task_id'] = ($validated['parent_task_id'] ?? null) ?: null;
        $validated['depends_on_task_id'] = ($validated['depends_on_task_id'] ?? null) ?: null;
        $validated['support_by'] = ($validated['support_by'] ?? null) ?: null;
        $validated['can_run_parallel'] = (bool) ($validated['can_run_parallel'] ?? false);

        if ($validated['depends_on_task_id']) {
            $requisite = ProjectTask::findOrFail($validated['depends_on_task_id']);

            if ((int) $requisite->project_id !== (int) $validated['project_id']) {
                throw ValidationException::withMessages([
                    'depends_on_task_id' => 'The requisite task does not belong to this project.',
                ]);
            }
        }

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

        // What is actually being added decides who may add it — see
        // App\Support\ProjectPlanAccess. Resolved here, after the category has
        // been defaulted, because that is what identifies the milestone.
        $actor = $request->user();
        $category = ProjectMilestone::normaliseCategory($validated['category'] ?? null);
        $startsNewMilestone = false;

        if ($validated['parent_task_id']) {
            abort_unless(
                ProjectPlanAccess::canAddSubTask($parentTask, $actor),
                403,
                'You can only add sub-tasks under a milestone you own or an activity assigned to you.'
            );
        } elseif ($this->milestoneExists($project, $category)) {
            abort_unless(
                ProjectPlanAccess::canAddActivity($project, $actor, $category),
                403,
                'You can only add activities to a milestone you own.'
            );
        } else {
            // A category nothing uses yet is a brand-new milestone. Whoever starts
            // one owns it, unless a manager did — managers assign an owner instead.
            abort_unless(
                ProjectPlanAccess::canAddMilestone($project, $actor),
                403,
                'You do not have permission to add milestones to this project.'
            );

            $startsNewMilestone = true;
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

        if ($startsNewMilestone) {
            $this->ensureMilestoneRecord(
                $project,
                $category,
                $project->isManagedBy($actor) ? null : $actor->id,
                $actor
            );
        }

        // Inserting a row shifts everything chained after it — re-chain the whole plan.
        $changedTaskIds = $this->rescheduleProjectTasks($project);

        $this->projectTaskBoards->syncProjectTaskChanges(
            $project,
            collect($changedTaskIds)->push($task->id),
            $request->user(),
            $request->boolean('auto_create_monthly_boards')
        );

        // Notify the assignee + project team that a new activity/sub-task was added.
        $kind = $task->parent_task_id ? 'sub-task' : 'activity';
        $this->notifications->notifyProjectTask(
            $task,
            'created',
            'New ' . $kind . ' added',
            ($task->project?->name ? $task->project->name . ': ' : '') . "new {$kind} \"" . \Illuminate\Support\Str::limit($task->name, 50) . '"',
            $request->user()->id
        );

        if ($request->wantsJson()) {
            return $this->ganttSaveResponse($project, 'Task added successfully.', 201);
        }

        return $this->backToTab($request, $project->id, 'success', 'Task added successfully.');
    }

    public function update(Request $request, ProjectTask $projects_task)
    {
        // A project manager may edit any row, a milestone owner every row in
        // their milestone, an activity assignee their activity and its sub-tasks,
        // a sub-task assignee their own row. See App\Support\ProjectPlanAccess.
        abort_unless($projects_task->isEditableBy($request->user()), 403, 'You can only edit rows assigned to you.');

        $this->normaliseManualStatus($request);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|nullable|string|max:255',
            'milestone_order' => 'sometimes|nullable|integer|min:0',
            'parent_task_id' => 'sometimes|nullable|exists:project_tasks,id',
            'depends_on_task_id' => 'sometimes|nullable|exists:project_tasks,id',
            'can_run_parallel' => 'sometimes|nullable|boolean',
            'status' => 'sometimes|required|string',
            'manual_status' => 'sometimes|nullable|string|in:' . implode(',', ProjectTask::manualStatuses()),
            'progress' => 'sometimes|integer|min:0|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'lead_time_days' => 'sometimes|nullable|integer|min:1',
            'assigned_to' => 'nullable',
            'support_by' => 'nullable',
            'order' => 'sometimes|numeric',
        ]);

        // A finished row is not blocked or awaiting approval — clear the manual
        // state rather than leaving a stale "Blocked" pill on a 100% activity.
        if (($validated['progress'] ?? null) !== null && (int) $validated['progress'] >= 100) {
            $validated['manual_status'] = null;
        }

        // Retyping the Milestone field either renames the milestone or moves the
        // row into another one. Either way both ends must belong to the editor —
        // otherwise a row could be pushed into a milestone they have no part in.
        $milestoneMove = null;

        if (array_key_exists('category', $validated)) {
            $fromMilestone = ProjectMilestone::normaliseCategory($projects_task->category);
            $toMilestone = ProjectMilestone::normaliseCategory($validated['category']);

            if ($fromMilestone !== $toMilestone) {
                abort_unless(
                    ProjectPlanAccess::canMoveTaskToMilestone($projects_task, $request->user(), $toMilestone),
                    403,
                    'You can only move a row between milestones you own.'
                );

                $milestoneMove = [$fromMilestone, $toMilestone];
            }
        }

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

        if (array_key_exists('depends_on_task_id', $validated)) {
            $validated['depends_on_task_id'] = $validated['depends_on_task_id'] ?: null;

            if ($validated['depends_on_task_id']) {
                $requisite = ProjectTask::findOrFail($validated['depends_on_task_id']);

                if ((int) $requisite->id === (int) $projects_task->id) {
                    throw ValidationException::withMessages([
                        'depends_on_task_id' => 'A task cannot be its own requisite.',
                    ]);
                }

                if ((int) $requisite->project_id !== (int) $projects_task->project_id) {
                    throw ValidationException::withMessages([
                        'depends_on_task_id' => 'The requisite task does not belong to this project.',
                    ]);
                }
            }
        }

        if (array_key_exists('can_run_parallel', $validated)) {
            $validated['can_run_parallel'] = (bool) $validated['can_run_parallel'];
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
        $oldLeadTime = $projects_task->lead_time_days;

        // An activity with sub-tasks derives its lead time, progress and span
        // from them — drop those keys so a stale form can't overwrite the
        // rollup that syncParentRollups() is about to recompute.
        $isRolledUpActivity = ! $projects_task->parent_task_id && $projects_task->subTasks()->exists();

        if ($isRolledUpActivity
            && array_key_exists('progress', $validated)
            && (int) $validated['progress'] !== (int) $projects_task->progress) {
            throw ValidationException::withMessages([
                'progress' => 'This activity progress is calculated from its sub-tasks. Update the sub-task percentages instead.',
            ]);
        }

        if ($isRolledUpActivity) {
            unset($validated['lead_time_days'], $validated['progress'], $validated['status'], $validated['start_date'], $validated['end_date']);
        }

        // The form posts the whole row every time, so only treat a date as
        // hand-edited when it differs from what is stored.
        $startChanged = array_key_exists('start_date', $validated)
            && ($validated['start_date'] ?: null) !== $projects_task->start_date?->toDateString();

        $datesChanged = $startChanged
            || (array_key_exists('end_date', $validated) && ($validated['end_date'] ?: null) !== $projects_task->end_date?->toDateString());

        // A hand-picked Start Date pins the row: the re-chain below starts it
        // there and carries every following row along. Clearing the field
        // unpins it and hands the row back to the chain.
        if ($startChanged) {
            $validated['start_anchor_date'] = $validated['start_date'] ?: null;
        }

        if ($request->boolean('unpin_start')) {
            $validated['start_anchor_date'] = null;
        }

        $leadTimeChanged = array_key_exists('lead_time_days', $validated)
            && (int) ($validated['lead_time_days'] ?? 1) !== (int) ($oldLeadTime ?? 1);

        $movedInChain = (array_key_exists('order', $validated) && (float) $validated['order'] !== (float) $projects_task->order)
            || (array_key_exists('milestone_order', $validated) && (int) $validated['milestone_order'] !== (int) $projects_task->milestone_order)
            || (array_key_exists('parent_task_id', $validated) && (int) $validated['parent_task_id'] !== (int) $projects_task->parent_task_id)
            // Repointing a requisite or flipping Can Run Parallel re-derives the
            // whole plan, not just this row.
            || (array_key_exists('depends_on_task_id', $validated) && (int) $validated['depends_on_task_id'] !== (int) $projects_task->depends_on_task_id)
            || (array_key_exists('can_run_parallel', $validated) && (bool) $validated['can_run_parallel'] !== (bool) $projects_task->can_run_parallel);

        // Dates are always derived from Day 1 + each row's lead time, so a
        // hand-edited timeline is folded back into the lead time first; the
        // re-chain below then carries the change through every following row.
        if ($datesChanged && !$leadTimeChanged && !empty($validated['start_date']) && !empty($validated['end_date'])) {
            $validated['lead_time_days'] = $this->calculatorFor($projects_task->project)->daysBetween(
                \Carbon\Carbon::parse($validated['start_date']),
                \Carbon\Carbon::parse($validated['end_date'])
            );
        }

        $validated['updated_by'] = $request->user()->id;

        $projects_task->update($validated);

        if ($milestoneMove) {
            $this->syncMilestoneRecordsAfterMove($projects_task->project, $milestoneMove[0], $milestoneMove[1], $request->user());
        }

        // Anything that moves a row in the chain — its lead time, its hand-edited
        // dates, or its position — re-derives every row's Start/End Date from
        // Day 1 so all succeeding rows shift with it. A sub-task's progress only
        // needs the parent rollup refreshed.
        $progressChanged = (int) $projects_task->progress !== $oldProgress;

        $changedTaskIds = [];

        if ($leadTimeChanged || $datesChanged || $movedInChain || array_key_exists('start_anchor_date', $validated)) {
            $changedTaskIds = $this->rescheduleProjectTasks($projects_task->project);
        } elseif ($progressChanged && $projects_task->parent_task_id) {
            $changedTaskIds = $this->syncParentRollups($projects_task->project);
        }

        $this->projectTaskBoards->syncProjectTaskChanges(
            $projects_task->project,
            collect($changedTaskIds)->push($projects_task->id),
            $request->user(),
            $request->boolean('auto_create_monthly_boards')
        );

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
            return $this->ganttSaveResponse($projects_task->project, 'Task updated successfully.');
        }

        return $this->backToTab($request, $projects_task->project_id, 'success', 'Task updated successfully.');
    }

    /**
     * Assign one internal project-team member to many Gantt rows in one action.
     * The client supplies explicit row ids for project, milestone, activity, or
     * sub-task scope; every id is re-scoped to this project on the server.
     */
    public function bulkAssign(Request $request, Project $project)
    {
        abort_unless($project->isManagedBy($request->user()), 403, 'You do not have permission to assign project tasks.');

        $validated = $request->validate([
            'task_ids' => 'required|array|min:1|max:2000',
            'task_ids.*' => 'required|integer|distinct',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'only_unassigned' => 'sometimes|boolean',
        ]);

        $taskIds = collect($validated['task_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $projectTaskIds = $project->tasks()->whereIn('id', $taskIds)->pluck('id');

        if ($projectTaskIds->count() !== $taskIds->count()) {
            throw ValidationException::withMessages([
                'task_ids' => 'One or more selected tasks do not belong to this project.',
            ]);
        }

        $assigneeId = $validated['assigned_to'] ?? null;
        if ($assigneeId && ! $project->teamMembers()->where('user_id', $assigneeId)->exists()) {
            throw ValidationException::withMessages([
                'assigned_to' => 'The selected user must be an internal member of this project team.',
            ]);
        }

        $query = $project->tasks()->whereIn('id', $projectTaskIds);
        if ($request->boolean('only_unassigned')) {
            $query->whereNull('assigned_to')->whereNull('external_assignment');
        }

        $changedIds = (clone $query)
            ->where(function ($changed) use ($assigneeId) {
                if ($assigneeId) {
                    $changed->whereNull('assigned_to')
                        ->orWhere('assigned_to', '!=', $assigneeId)
                        ->orWhereNotNull('external_assignment');
                } else {
                    $changed->whereNotNull('assigned_to')->orWhereNotNull('external_assignment');
                }
            })
            ->pluck('id');

        if ($changedIds->isNotEmpty()) {
            ProjectTask::whereIn('id', $changedIds)->update([
                'assigned_to' => $assigneeId,
                'external_assignment' => null,
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);

            // Assignment-only changes do not need a complete rebuild of every
            // monthly/manual board. Update the already-linked items in one query.
            $this->projectTaskBoards->syncTaskAssignments($changedIds, $assigneeId);

            if ($assigneeId) {
                $this->notifications->dispatch([$assigneeId], $request->user()->id, [
                    'domain' => 'project_task',
                    'event' => 'assignment',
                    'title' => 'Assigned to project tasks',
                    'message' => "You were assigned to {$changedIds->count()} task(s) in {$project->name}",
                    'subject' => 'project:' . $project->id,
                    'url' => route('projects.show', ['project' => $project->id, 'tab' => 'gantt'], false),
                ]);
            }
        }

        $assignee = $assigneeId
            ? \App\Models\User::find($assigneeId, ['id', 'name', 'department', 'org_path'])
            : null;

        return response()->json([
            'updated' => $changedIds->count(),
            'task_ids' => $changedIds->values(),
            'assigned_to' => $assigneeId,
            'assignee' => $assignee,
        ]);
    }

    public function destroy(Request $request, ProjectTask $projects_task)
    {
        $project = $projects_task->project;

        // Deleting a row (and its sub-tasks) follows the same branch rule as
        // editing it — see App\Support\ProjectPlanAccess.
        abort_unless(
            $project && ProjectPlanAccess::canDeleteTask($projects_task, $request->user()),
            403,
            'You do not have permission to delete this row.'
        );

        $taskIds = $projects_task->subTasks()->pluck('id')->push($projects_task->id);
        $this->projectTaskBoards->archiveProjectTaskCards($taskIds, $request->user());
        $this->projectTaskBoards->removeBoardItemsForProjectTasks($taskIds);

        // Rows queued behind these lose their requisite and fall back to
        // following whatever now sits above them.
        $this->releaseRequisitePointers($taskIds);

        $projects_task->subTasks()->delete();
        $projects_task->delete();

        if ($project) {
            // Removing a row shifts everything chained after it — re-chain the whole plan.
            $this->rescheduleProjectTasks($project);
            $this->projectTaskBoards->syncProject($project->fresh(['teamMembers.user', 'tasks']), $request->user(), null, $request->boolean('auto_create_monthly_boards'));
        }

        return $this->backToTab($request, $project?->id, 'success', 'Task deleted successfully.');
    }

    public function destroyMilestone(Request $request, Project $project)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:255',
        ]);

        $category = $validated['category'] ?: 'General';

        // A milestone is deleted by the project manager or by the person who owns
        // that milestone — never by the owner of a different one.
        abort_unless(
            ProjectPlanAccess::canManageMilestone($project, $request->user(), $category),
            403,
            'You do not have permission to delete milestones in this project.'
        );

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
            $this->releaseRequisitePointers($taskIds);

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

        // The milestone no longer exists, so neither should its ownership record.
        ProjectMilestone::where('project_id', $project->id)
            ->where('category', ProjectMilestone::normaliseCategory($category))
            ->delete();

        return $this->backToTab($request, $project->id, 'success', 'Milestone deleted successfully.');
    }

    /**
     * Set (or clear) who owns a milestone. The owner may add, edit and delete
     * everything inside it — see App\Support\ProjectPlanAccess. Only the project
     * manager or the milestone's current owner may hand it over.
     */
    public function updateMilestoneOwner(Request $request, Project $project)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:255',
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        $category = ProjectMilestone::normaliseCategory($validated['category']);

        abort_unless(
            ProjectPlanAccess::canManageMilestone($project, $request->user(), $category),
            403,
            'You do not have permission to change the owner of this milestone.'
        );

        $this->ensureMilestoneRecord($project, $category, $validated['assigned_to'] ?? null, $request->user());

        return $this->backToTab($request, $project->id, 'success', 'Milestone owner updated.');
    }

    /** Whether $category is already a milestone of $project — has rows, or has an owner. */
    private function milestoneExists(Project $project, string $category): bool
    {
        return $this->milestoneHasRows($project, $category)
            || ProjectMilestone::where('project_id', $project->id)->where('category', $category)->exists();
    }

    /** Upsert the ownership record for one milestone. */
    private function ensureMilestoneRecord(Project $project, string $category, ?int $ownerId, ?\App\Models\User $actor): ProjectMilestone
    {
        $milestone = ProjectMilestone::firstOrNew([
            'project_id' => $project->id,
            'category' => $category,
        ]);

        if (! $milestone->exists) {
            $milestone->created_by = $actor?->id;
        }

        $milestone->assigned_to = $ownerId;
        $milestone->updated_by = $actor?->id;
        $milestone->save();

        return $milestone;
    }

    /**
     * A row changed milestone. If nothing is left under the old name the move was
     * really a rename, so the owner travels with it; otherwise the old milestone
     * stands and only the destination needs to exist.
     */
    private function syncMilestoneRecordsAfterMove(?Project $project, string $from, string $to, ?\App\Models\User $actor): void
    {
        if (! $project) {
            return;
        }

        $source = ProjectMilestone::where('project_id', $project->id)->where('category', $from)->first();
        $target = ProjectMilestone::where('project_id', $project->id)->where('category', $to)->first();

        $sourceEmptied = ! $this->milestoneHasRows($project, $from);
        $ownerId = $target?->assigned_to;

        // Rename: carry the owner over rather than leaving an unowned milestone.
        if ($sourceEmptied && $source && $ownerId === null) {
            $ownerId = $source->assigned_to;
        }

        $this->ensureMilestoneRecord($project, $to, $ownerId, $actor);

        if ($sourceEmptied && $source) {
            $source->delete();
        }
    }

    /** Whether any top-level row still sits under $category. */
    private function milestoneHasRows(Project $project, string $category): bool
    {
        return ProjectTask::query()
            ->where('project_id', $project->id)
            ->whereNull('parent_task_id')
            ->where(function ($query) use ($category) {
                $query->where('category', $category);

                if ($category === 'General') {
                    $query->orWhereNull('category')->orWhere('category', '');
                }
            })
            ->exists();
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
            'tasks.*.order' => 'nullable|numeric|min:0',
        ]);

        // Reordering / bulk timeline edits span the whole plan — a management action.
        // Every task in the batch must belong to a project the user manages.
        $batch = ProjectTask::whereIn('id', collect($validated['tasks'])->pluck('id'))
            ->with('project')
            ->get();

        $projects = $batch->pluck('project')->filter()->unique('id');

        foreach ($projects as $project) {
            abort_unless($project->isManagedBy($request->user()), 403, 'You do not have permission to reorder tasks in this project.');
        }

        // A resized bar is read back in the project's own counting mode, so a
        // calendar-day project doesn't get its drag silently rounded off.
        $projectByTaskId = $batch->mapWithKeys(fn (ProjectTask $task) => [$task->id => $task->project]);

        $progressChanges = [];
        $reordered = false;

        foreach ($validated['tasks'] as $taskData) {
            $updates = [];

            if (array_key_exists('start_date', $taskData)) {
                $updates['start_date'] = $taskData['start_date'];
            }
            if (array_key_exists('end_date', $taskData)) {
                $updates['end_date'] = $taskData['end_date'];
            }

            // A dragged/resized bar is a lead-time edit, and where it was dropped
            // pins the row's start. The re-chain below then moves every later row.
            if (!empty($updates['start_date']) && !empty($updates['end_date'])) {
                $updates['lead_time_days'] = $this->calculatorFor($projectByTaskId[$taskData['id']] ?? null)->daysBetween(
                    \Carbon\Carbon::parse($updates['start_date']),
                    \Carbon\Carbon::parse($updates['end_date'])
                );
                $updates['start_anchor_date'] = $updates['start_date'];
                $reordered = true;
            }
            if (array_key_exists('progress', $taskData)) {
                $updates['progress'] = $taskData['progress'] ?? 0;
            }
            if (array_key_exists('milestone_order', $taskData)) {
                $updates['milestone_order'] = $taskData['milestone_order'];
                $reordered = true;
            }
            if (array_key_exists('order', $taskData)) {
                $updates['order'] = $taskData['order'];
                $reordered = true;
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

        // Reordering rows shifts the whole chain — re-derive every date from Day 1.
        // A bare progress drag still needs the parent rollups refreshed.
        $rescheduledTaskIds = [];

        foreach ($projects as $project) {
            if ($reordered) {
                $rescheduledTaskIds[$project->id] = $this->rescheduleProjectTasks($project);
            } elseif (!empty($progressChanges)) {
                $rescheduledTaskIds[$project->id] = $this->syncParentRollups($project);
            }
        }

        // A sort only changes a small sibling set. Sync those rows plus any rows
        // whose dates moved during re-chaining instead of rebuilding every board.
        foreach ($projects as $project) {
            $submittedIds = $batch
                ->where('project_id', $project->id)
                ->pluck('id');

            $this->projectTaskBoards->syncProjectTaskChanges(
                $project,
                $submittedIds->concat($rescheduledTaskIds[$project->id] ?? [])->unique(),
                $request->user(),
                $request->boolean('auto_create_monthly_boards')
            );
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
