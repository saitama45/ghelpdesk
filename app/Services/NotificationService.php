<?php

namespace App\Services;

use App\Models\NpcStatus;
use App\Models\NpcStatusAttachment;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Store;
use App\Models\TaskCard;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Central choke point for in-app (bell) notifications across tickets, task
 * boards, and the project tracker.
 *
 * Each domain helper resolves the relevant recipients, drops the actor (no
 * self-notifications), and stores one database notification per recipient via
 * the generic {@see ActivityNotification}. Mentioned users are always added on
 * top of the normal recipient set.
 */
class NotificationService
{
    /**
     * Core dispatch: send a database notification to each distinct, active
     * recipient except the actor.
     *
     * @param  iterable<int>  $recipientIds
     */
    public function dispatch(iterable $recipientIds, ?int $actorId, array $payload): void
    {
        $ids = collect($recipientIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->reject(fn ($id) => $actorId && $id === (int) $actorId)
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $recipients = User::whereIn('id', $ids)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $actor = $actorId ? User::find($actorId) : null;

        $payload = array_merge([
            'actor_id' => $actorId,
            'actor_name' => $actor?->name,
            'severity' => 'info',
        ], $payload);

        Notification::send($recipients, new ActivityNotification($payload));
    }

    // ── Tickets ──────────────────────────────────────────────────────────────

    public function notifyTicket(Ticket $ticket, string $event, string $title, string $message, ?int $actorId, array $mentionIds = [], string $severity = 'info'): void
    {
        $recipients = $this->ticketRecipients($ticket)->merge($mentionIds);

        $this->dispatch($recipients, $actorId, [
            'domain' => 'ticket',
            'event' => $event,
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'subject' => 'ticket:' . $ticket->id,
            'url' => $this->relativeRoute('tickets.edit', $ticket->id),
        ]);
    }

    /**
     * Assignee + requester/reporter + CC'd users (those that map to a user).
     */
    protected function ticketRecipients(Ticket $ticket): Collection
    {
        $ids = collect([$ticket->assignee_id, $ticket->reporter_id]);

        if ($requester = $ticket->effectiveRequesterRecipient()) {
            $ids->push($requester['id'] ?? null);
        }

        $ccUserIds = $ticket->effectiveCcs()
            ->pluck('user_id')
            ->filter();

        return $ids->merge($ccUserIds)->filter()->unique()->values();
    }

    // ── Task board cards ─────────────────────────────────────────────────────

    public function notifyTaskCard(TaskCard $card, string $event, string $title, string $message, ?int $actorId, array $mentionIds = [], string $severity = 'info'): void
    {
        $recipients = $this->taskCardRecipients($card)->merge($mentionIds);

        $this->dispatch($recipients, $actorId, [
            'domain' => 'task_card',
            'event' => $event,
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'subject' => 'task_card:' . $card->id,
            'url' => $this->relativeRoute('task-boards.show', $card->task_board_id) . '?card=' . $card->id,
        ]);
    }

    /**
     * Card assignees + card watchers + board members + board watchers + creators.
     */
    protected function taskCardRecipients(TaskCard $card): Collection
    {
        $card->loadMissing(['assignees:id', 'watchers:id', 'board.members:id', 'board.watchers:id']);

        return collect()
            ->merge($card->assignees->pluck('id'))
            ->merge($card->watchers->pluck('id'))
            ->merge($card->board?->members->pluck('id') ?? [])
            ->merge($card->board?->watchers->pluck('id') ?? [])
            ->push($card->created_by)
            ->filter()
            ->unique()
            ->values();
    }

    // ── Project tracker tasks ────────────────────────────────────────────────

    public function notifyProjectTask(ProjectTask $task, string $event, string $title, string $message, ?int $actorId, array $mentionIds = [], string $severity = 'info'): void
    {
        $recipients = $this->projectTaskRecipients($task)->merge($mentionIds);

        $this->dispatch($recipients, $actorId, [
            'domain' => 'project_task',
            'event' => $event,
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'subject' => 'project_task:' . $task->id,
            'url' => $this->relativeRoute('projects.show', $task->project_id),
        ]);
    }

    /**
     * Task assigned/support users + project team members.
     */
    protected function projectTaskRecipients(ProjectTask $task): Collection
    {
        $task->loadMissing(['project.teamMembers:id,project_id,user_id']);

        return collect([$task->assigned_to, $task->support_by])
            ->merge($task->project?->teamMembers->pluck('user_id') ?? [])
            ->filter()
            ->unique()
            ->values();
    }

    // ── NPC Status seals ─────────────────────────────────────────────────────

    /**
     * A store downloaded one of the year's seals — notify the NPC page admins
     * so they can confirm receipt and mark the store as checked.
     */
    public function notifyNpcSealDownload(NpcStatus $npcStatus, Store $store, string $type, ?int $actorId): void
    {
        $label = NpcStatusAttachment::TYPE_LABELS[$type] ?? $type;
        $company = $npcStatus->company()->first();

        $recipients = User::permission(['npc_status.view', 'npc_status.edit'])->pluck('id');

        $this->dispatch($recipients, $actorId, [
            'domain' => 'npc_status',
            'event' => 'seal_downloaded',
            'title' => 'Store downloaded an NPC seal',
            'message' => "{$store->name} downloaded the {$label} for {$company?->name} ({$npcStatus->year}). Confirm receipt to mark the store as checked.",
            'severity' => 'info',
            'subject' => 'npc_status:' . $npcStatus->id,
            'url' => $this->relativeRoute('npc-statuses.index', []),
        ]);
    }

    // ── Approvals ─────────────────────────────────────────────────────────────

    /**
     * Generic approval bell notification, shared by every approval workflow
     * (dynamic forms, POS/SAP requests, payment records, schedule change
     * requests, service vehicle trips, Google registrations).
     *
     * Approvers are pinged when a transaction reaches their step; the requester
     * is pinged with the final decision. The specific workflow is conveyed
     * through the title/message/url — the bell renders them all under the
     * "approval" domain.
     *
     * @param  iterable<int>  $recipientIds
     */
    public function notifyApproval(
        iterable $recipientIds,
        ?int $actorId,
        string $event,
        string $title,
        string $message,
        string $url,
        string $subject,
        string $severity = 'info'
    ): void {
        $this->dispatch($recipientIds, $actorId, [
            'domain' => 'approval',
            'event' => $event,
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'subject' => $subject,
            'url' => $url,
        ]);
    }

    /**
     * Active user ids holding any of the given permission(s) — used to resolve
     * approvers for permission-gated workflows that have no explicit approver
     * matrix (e.g. service vehicle trips).
     *
     * @return array<int>
     */
    public function usersWithPermission(array|string $permissions): array
    {
        return User::permission($permissions)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Build a relative URL (path + query) for a named route so the bell can
     * navigate client-side without needing Ziggy params for every route.
     */
    public function relativeRoute(string $name, mixed $params): string
    {
        try {
            return route($name, $params, false);
        } catch (\Throwable $e) {
            return '/';
        }
    }
}
