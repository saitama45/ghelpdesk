<?php

namespace App\Support;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\User;

/**
 * Who may change what on a project plan (/projects/{id}?tab=gantt).
 *
 * One rule, in one place, consumed by ProjectTaskController, ProjectController
 * (which ships the resolved flags to Inertia) and ProjectGantt.vue. The plan has
 * three levels — milestone (a shared project_tasks.category), activity (a
 * top-level row) and sub-task (a child row) — and access is granted per branch:
 *
 *  - project manager (creator / Admin / Solutions Admin): everything, as before;
 *  - milestone owner: everything inside THAT milestone — add, edit and delete its
 *    activities and their sub-tasks, rename or delete the milestone itself, and
 *    start a new milestone (which they then own). Not another owner's milestone;
 *  - activity assignee: edit and delete THAT activity, and add / edit / delete
 *    its sub-tasks. Not a sibling activity;
 *  - sub-task assignee: edit and delete THAT sub-task. Nothing may be added under
 *    a sub-task — it is the last level of the plan.
 */
class ProjectPlanAccess
{
    /** Full run of the plan: the creator, or an Admin / Solutions Admin. */
    public static function managesProject(?Project $project, ?User $user): bool
    {
        return (bool) $project?->isManagedBy($user);
    }

    /**
     * The milestones of $project this user owns, normalised. Managers are not
     * listed here — they are handled by managesProject and own nothing per se.
     */
    public static function ownedMilestones(?Project $project, ?User $user): array
    {
        if (! $project || ! $user) {
            return [];
        }

        return ProjectMilestone::query()
            ->where('project_id', $project->id)
            ->where('assigned_to', $user->id)
            ->pluck('category')
            ->map(fn ($category) => ProjectMilestone::normaliseCategory($category))
            ->unique()
            ->values()
            ->all();
    }

    /** Owner of this one milestone (or a manager). */
    public static function ownsMilestone(?Project $project, ?User $user, ?string $category): bool
    {
        if (self::managesProject($project, $user)) {
            return true;
        }

        if (! $project || ! $user) {
            return false;
        }

        return ProjectMilestone::query()
            ->where('project_id', $project->id)
            ->where('category', ProjectMilestone::normaliseCategory($category))
            ->where('assigned_to', $user->id)
            ->exists();
    }

    /**
     * Starting a brand-new milestone. Managers always may; a user who already
     * owns a milestone here may start their own, and owns whatever they start.
     * Being assigned an activity does not earn this — that is the level below.
     */
    public static function canAddMilestone(?Project $project, ?User $user): bool
    {
        return self::managesProject($project, $user)
            || self::ownedMilestones($project, $user) !== [];
    }

    /** Renaming or deleting an existing milestone, and everything under it. */
    public static function canManageMilestone(?Project $project, ?User $user, ?string $category): bool
    {
        return self::ownsMilestone($project, $user, $category);
    }

    /** Adding an activity to a milestone — the milestone's owner only. */
    public static function canAddActivity(?Project $project, ?User $user, ?string $category): bool
    {
        return self::ownsMilestone($project, $user, $category);
    }

    /**
     * Adding a sub-task under $activity: the milestone owner, or the person the
     * activity is assigned to. A sub-task itself can never take children.
     */
    public static function canAddSubTask(?ProjectTask $activity, ?User $user): bool
    {
        if (! $activity || ! $user || $activity->parent_task_id) {
            return false;
        }

        return self::ownsMilestone($activity->project, $user, $activity->category)
            || self::isAssignedTo($activity, $user);
    }

    /**
     * Editing one row. Its assignee may always edit it; above that, a sub-task is
     * also editable by its activity's assignee, and every row by its milestone's
     * owner.
     */
    public static function canEditTask(?ProjectTask $task, ?User $user): bool
    {
        if (! $task || ! $user) {
            return false;
        }

        if (self::ownsMilestone($task->project, $user, $task->category)) {
            return true;
        }

        if (self::isAssignedTo($task, $user)) {
            return true;
        }

        // A sub-task belongs to whoever is running the activity above it.
        return $task->parent_task_id !== null
            && self::isAssignedTo($task->parentTask, $user);
    }

    /** Deleting one row follows exactly the same branch rule as editing it. */
    public static function canDeleteTask(?ProjectTask $task, ?User $user): bool
    {
        return self::canEditTask($task, $user);
    }

    /**
     * Moving a row between milestones. Only meaningful for someone who owns both
     * ends — otherwise a row could be pushed into a milestone the user has no
     * business in, or pulled out of one they do not own.
     */
    public static function canMoveTaskToMilestone(?ProjectTask $task, ?User $user, ?string $targetCategory): bool
    {
        return self::ownsMilestone($task?->project, $user, $task?->category)
            && self::ownsMilestone($task?->project, $user, $targetCategory);
    }

    private static function isAssignedTo(?ProjectTask $task, ?User $user): bool
    {
        return $task
            && $user
            && $task->assigned_to !== null
            && (int) $task->assigned_to === (int) $user->id;
    }
}
