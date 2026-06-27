<?php

namespace App\Services;

use App\Models\DepartmentNode;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Computes the live queue as a view over active tickets.
 *
 * A ticket's lane is derived from its assignee's DepartmentNode (the configured
 * SO / CS lanes); tickets with no resolvable lane fall into "triage". Position
 * within a lane is by SLA resolution target (soonest first, nulls last) — never
 * stored, always recomputed. ETA shown everywhere is the ticket's existing
 * slaMetric.resolution_target_at.
 */
class QueueService
{
    /** Waiting "in line" — counted for position. */
    public const WAITING_STATUSES = ['open'];

    /** Currently being served ("now serving"). */
    public const SERVING_STATUSES = ['in_progress'];

    /** Active but not in line — shown as on-hold/scheduled, excluded from position. */
    public const HOLD_STATUSES = ['for_schedule', 'waiting_service_provider', 'waiting_client_feedback'];

    public const TERMINAL_STATUSES = ['resolved', 'closed'];

    public const TRIAGE_KEY = 'triage';

    public const ASSIGNED_NO_DEPARTMENT_KEY = 'assigned:no-department';

    private const DYNAMIC_NODE_PREFIX = 'node:';

    /**
     * DepartmentNode codes that act as queue lanes (configurable).
     *
     * @return array<int,string>
     */
    public function laneCodes(): array
    {
        $raw = Setting::get('queue_lane_nodes', '["SO","CS"]');
        $codes = is_array($raw) ? $raw : (json_decode($raw, true) ?: ['SO', 'CS']);

        return array_values(array_filter(array_map('strval', $codes)));
    }

    /**
     * Lane definitions keyed by lane key. Configured lanes are followed by
     * direct department-node lanes needed by active tickets and fallback lanes.
     */
    public function lanes(?int $companyId = null, bool $directDepartmentLanes = false): Collection
    {
        $nodes = $directDepartmentLanes
            ? collect()
            : DepartmentNode::whereIn('code', $this->laneCodes())
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

        $lanes = collect();

        foreach ($nodes as $node) {
            $ids = array_merge([$node->id], DepartmentNode::getAllDescendantIds($node->id));
            $lanes->put($node->code, [
                'key' => $node->code,
                'name' => $node->name,
                'code' => $node->code,
                'node_id' => $node->id,
                'node_ids' => $ids,
                'is_triage' => false,
                'is_no_department' => false,
                'is_root' => $node->parent_id === null,
            ]);
        }

        $configuredNodeIds = $lanes
            ->pluck('node_ids')
            ->flatten()
            ->unique()
            ->values()
            ->all();
        $configuredNodeMap = array_fill_keys($configuredNodeIds, true);
        $dynamicNodeIds = [];

        $activeTickets = $this->activeTicketQuery($companyId)
            ->whereNull('deleted_at')
            ->whereIn('status', array_merge(
                self::WAITING_STATUSES,
                self::SERVING_STATUSES,
                self::HOLD_STATUSES
            ))
            ->whereNotNull('assignee_id')
            ->with('assignee:id,department_node_id')
            ->get(['id', 'assignee_id', 'status', 'queue_called_lane']);

        foreach ($activeTickets as $ticket) {
            $calledNodeId = $this->dynamicNodeIdFromLaneKey($ticket->queue_called_lane);
            if ($calledNodeId) {
                $dynamicNodeIds[$calledNodeId] = true;
            }

            $nodeId = $ticket->assignee?->department_node_id;
            if (!$nodeId || isset($configuredNodeMap[$nodeId])) {
                continue;
            }

            $dynamicNodeIds[(int) $nodeId] = true;
        }

        $dynamicNodes = DepartmentNode::whereIn('id', array_keys($dynamicNodeIds))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        foreach ($dynamicNodes as $node) {
            $key = self::DYNAMIC_NODE_PREFIX . $node->id;
            $ids = array_values(array_diff(
                [$node->id],
                $configuredNodeIds
            ));

            if (empty($ids)) {
                continue;
            }

            $lanes->put($key, [
                'key' => $key,
                'name' => $node->name,
                'code' => $node->code,
                'node_id' => $node->id,
                'node_ids' => $ids,
                'is_triage' => false,
                'is_no_department' => false,
                'is_root' => $node->parent_id === null,
            ]);
        }

        $lanes->put(self::ASSIGNED_NO_DEPARTMENT_KEY, [
            'key' => self::ASSIGNED_NO_DEPARTMENT_KEY,
            'name' => 'Assigned / No Department',
            'code' => null,
            'node_id' => null,
            'node_ids' => [],
            'is_triage' => false,
            'is_no_department' => true,
            'is_root' => false,
        ]);

        $lanes->put(self::TRIAGE_KEY, [
            'key' => self::TRIAGE_KEY,
            'name' => 'Triage / Unassigned',
            'code' => null,
            'node_id' => null,
            'node_ids' => [],
            'is_triage' => true,
            'is_no_department' => false,
            'is_root' => false,
        ]);

        return $lanes;
    }

    /** Map every lane node id → lane key for quick assignment resolution. */
    private function nodeLaneMap(Collection $lanes): array
    {
        $map = [];
        foreach ($lanes as $lane) {
            foreach ($lane['node_ids'] as $id) {
                $map[$id] ??= $lane['key'];
            }
        }

        return $map;
    }

    public function laneKeyForTicket(
        Ticket $ticket,
        array $nodeLaneMap,
        bool $preserveCalledLane = true
    ): string
    {
        if (
            $preserveCalledLane
            && in_array($ticket->status, self::SERVING_STATUSES, true)
            && $ticket->queue_called_lane
        ) {
            return (string) $ticket->queue_called_lane;
        }

        $nodeId = $ticket->assignee?->department_node_id;

        if ($nodeId && isset($nodeLaneMap[$nodeId])) {
            return $nodeLaneMap[$nodeId];
        }

        return $ticket->assignee_id
            ? self::ASSIGNED_NO_DEPARTMENT_KEY
            : self::TRIAGE_KEY;
    }

    /**
     * Build the full board: each lane with now-serving, waiting (positioned),
     * on-hold and summary counts.
     */
    public function board(?int $companyId = null, bool $directDepartmentLanes = false): array
    {
        $lanes = $this->lanes($companyId, $directDepartmentLanes);
        $nodeLaneMap = $this->nodeLaneMap($lanes);

        $tickets = $this->activeTicketQuery($companyId)
            ->whereNull('deleted_at')
            ->whereIn('status', array_merge(self::WAITING_STATUSES, self::SERVING_STATUSES, self::HOLD_STATUSES))
            ->with([
                'assignee:id,name,profile_photo,department_node_id',
                'reporter:id,name',
                'slaMetric',
                'item:id,name',
            ])
            ->get();

        $buckets = [];
        foreach ($lanes as $laneKey => $lane) {
            $buckets[$laneKey] = ['waiting' => collect(), 'serving' => collect(), 'hold' => collect()];
        }

        foreach ($tickets as $ticket) {
            $laneKey = $this->laneKeyForTicket($ticket, $nodeLaneMap);
            if ($directDepartmentLanes && !isset($buckets[$laneKey])) {
                $laneKey = $this->laneKeyForTicket($ticket, $nodeLaneMap, preserveCalledLane: false);
            }

            if (!isset($buckets[$laneKey])) {
                $laneKey = $ticket->assignee_id
                    ? self::ASSIGNED_NO_DEPARTMENT_KEY
                    : self::TRIAGE_KEY;
            }

            if (in_array($ticket->status, self::SERVING_STATUSES, true)) {
                $buckets[$laneKey]['serving']->push($ticket);
            } elseif (in_array($ticket->status, self::HOLD_STATUSES, true)) {
                $buckets[$laneKey]['hold']->push($ticket);
            } else {
                $buckets[$laneKey]['waiting']->push($ticket);
            }
        }

        $result = [];
        foreach ($lanes as $laneKey => $lane) {
            $waiting = $this->sortBySla($buckets[$laneKey]['waiting'])->values();
            $serving = $buckets[$laneKey]['serving']
                ->sortBy(fn ($t) => $t->called_at ?? $t->updated_at)
                ->values();
            $hold = $this->sortBySla($buckets[$laneKey]['hold'])->values();

            $waitingCards = $waiting->map(fn ($t, $i) => $this->serialize($t, $i + 1))->all();
            $avgWait = collect($waitingCards)->avg('waiting_minutes');

            $result[] = [
                'key' => $lane['key'],
                'name' => $lane['name'],
                'code' => $lane['code'],
                'is_triage' => $lane['is_triage'],
                'is_root' => $lane['is_root'],
                'now_serving' => $serving->map(fn ($t) => $this->serialize($t))->all(),
                'waiting' => $waitingCards,
                'on_hold' => $hold->map(fn ($t) => $this->serialize($t))->all(),
                'counts' => [
                    'waiting' => $waiting->count(),
                    'serving' => $serving->count(),
                    'on_hold' => $hold->count(),
                ],
                'avg_wait_minutes' => $avgWait !== null ? (int) round($avgWait) : null,
            ];
        }

        return [
            'lanes' => $result,
            'served_today' => $this->activeTicketQuery($companyId)
                ->whereNull('deleted_at')
                ->whereNotNull('called_at')
                ->whereDate('called_at', \Illuminate\Support\Carbon::today('Asia/Manila'))
                ->count(),
            'generated_at' => now('Asia/Manila')->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Board view using each assignee's exact non-root department node.
     */
    public function directDepartmentBoard(?int $companyId = null): array
    {
        $board = $this->board($companyId, directDepartmentLanes: true);
        $board['lanes'] = array_values(array_filter(
            $board['lanes'],
            fn (array $lane) => !$lane['is_triage']
                && $lane['key'] !== self::ASSIGNED_NO_DEPARTMENT_KEY
                && !$lane['is_root']
                && ($lane['counts']['waiting'] > 0 || $lane['counts']['serving'] > 0)
        ));

        return $board;
    }

    /**
     * Position / status info for a single ticket — for the public "Track my
     * ticket" page and the in-app requester list.
     */
    public function positionInfoFor(
        Ticket $ticket,
        ?int $companyId = null,
        bool $directDepartmentLanes = false
    ): array
    {
        $ticket->loadMissing('assignee:id,name,department_node_id', 'slaMetric');

        $eta = $ticket->slaMetric?->resolution_target_at;
        $etaPayload = [
            'eta' => $eta?->timezone('Asia/Manila')->format('Y-m-d H:i:s'),
            'eta_label' => $eta ? $eta->timezone('Asia/Manila')->format('M d, Y g:i A') : null,
            'eta_relative' => $eta ? $eta->diffForHumans() : null,
        ];

        if (in_array($ticket->status, self::TERMINAL_STATUSES, true)) {
            return array_merge($etaPayload, [
                'state' => 'done',
                'status' => $ticket->status,
                'status_label' => $this->statusLabel($ticket->status),
                'lane' => null,
                'position' => null,
                'total_waiting' => null,
            ]);
        }

        $lanes = $this->lanes($companyId, $directDepartmentLanes);
        $nodeLaneMap = $this->nodeLaneMap($lanes);
        $laneKey = $this->laneKeyForTicket($ticket, $nodeLaneMap);
        if ($directDepartmentLanes && !$lanes->has($laneKey)) {
            $laneKey = $this->laneKeyForTicket($ticket, $nodeLaneMap, preserveCalledLane: false);
        }

        $lane = $lanes->get($laneKey) ?? $lanes->get(self::TRIAGE_KEY);

        $state = 'waiting';
        if (in_array($ticket->status, self::SERVING_STATUSES, true)) {
            $state = 'serving';
        } elseif (in_array($ticket->status, self::HOLD_STATUSES, true)) {
            $state = 'hold';
        }

        $waiting = $this->waitingTicketsForLane($lane, $lanes, $companyId);
        $total = $waiting->count();
        $position = null;
        if ($state === 'waiting') {
            $idx = $waiting->search(fn ($t) => $t->id === $ticket->id);
            $position = $idx === false ? null : $idx + 1;
        }

        return array_merge($etaPayload, [
            'state' => $state,
            'status' => $ticket->status,
            'status_label' => $this->statusLabel($ticket->status),
            'lane' => $lane['name'],
            'position' => $position,
            'total_waiting' => $total,
        ]);
    }

    /**
     * The next waiting ticket an agent would be handed for a lane (top of the
     * SLA-ordered line). Used by "Call Next".
     */
    public function nextWaitingTicket(string $laneKey): ?Ticket
    {
        $lanes = $this->lanes();
        $lane = $lanes->get($laneKey);
        if (!$lane) {
            return null;
        }

        return $this->waitingTicketsForLane($lane, $lanes)->first();
    }

    /**
     * Atomically claim the next open ticket in a lane for the current agent.
     */
    public function claimNextWaitingTicket(
        string $laneKey,
        User $agent,
        bool $directDepartmentLanes = false
    ): ?Ticket
    {
        $lanes = $this->lanes(directDepartmentLanes: $directDepartmentLanes);
        $lane = $lanes->get($laneKey);
        if (!$lane) {
            return null;
        }

        foreach ($this->waitingTicketsForLane($lane, $lanes) as $candidate) {
            $claimed = DB::transaction(function () use ($candidate, $laneKey, $lanes, $agent) {
                $ticket = Ticket::query()
                    ->whereKey($candidate->id)
                    ->lockForUpdate()
                    ->with(['assignee:id,name,department_node_id'])
                    ->first();

                if (!$ticket || !in_array($ticket->status, self::WAITING_STATUSES, true)) {
                    return null;
                }

                if ($this->laneKeyForTicket($ticket, $this->nodeLaneMap($lanes)) !== $laneKey) {
                    return null;
                }

                $oldStatus = $ticket->status;
                $oldAssigneeId = $ticket->assignee_id;
                $changes = [
                    'status' => 'in_progress',
                    'called_at' => now('Asia/Manila'),
                    'queue_called_lane' => $laneKey,
                ];

                if ((int) $ticket->assignee_id !== (int) $agent->id) {
                    $changes['assignee_id'] = $agent->id;
                }

                $ticket->fill($changes)->save();

                TicketHistory::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $agent->id,
                    'column_changed' => 'status',
                    'old_value' => (string) $oldStatus,
                    'new_value' => 'in_progress',
                    'changed_at' => now('Asia/Manila'),
                ]);

                if (array_key_exists('assignee_id', $changes) && (int) $oldAssigneeId !== (int) $agent->id) {
                    TicketHistory::create([
                        'ticket_id' => $ticket->id,
                        'user_id' => $agent->id,
                        'column_changed' => 'assignee_id',
                        'old_value' => (string) (User::find($oldAssigneeId)?->name ?? $oldAssigneeId),
                        'new_value' => (string) $agent->name,
                        'changed_at' => now('Asia/Manila'),
                    ]);
                }

                return $ticket->fresh(['assignee:id,name,department_node_id']);
            });

            if ($claimed) {
                return $claimed;
            }
        }

        return null;
    }

    /**
     * Open tickets that belong to a lane, ordered by SLA target (soonest first).
     */
    private function waitingTicketsForLane(array $lane, Collection $lanes, ?int $companyId = null): Collection
    {
        $query = $this->activeTicketQuery($companyId)
            ->whereNull('deleted_at')
            ->whereIn('status', self::WAITING_STATUSES)
            ->with(['slaMetric', 'assignee:id,name,department_node_id']);

        if ($lane['is_triage']) {
            $query->whereNull('assignee_id');
        } elseif ($lane['is_no_department']) {
            $laneNodeIds = collect($lanes)
                ->where('is_triage', false)
                ->where('is_no_department', false)
                ->pluck('node_ids')
                ->flatten()
                ->unique()
                ->values()
                ->all();

            $query->whereNotNull('assignee_id')
                ->where(function ($outer) use ($laneNodeIds) {
                    $outer->whereDoesntHave('assignee')
                        ->orWhereHas('assignee', function ($assignee) use ($laneNodeIds) {
                            $assignee->whereNull('department_node_id');

                            if (!empty($laneNodeIds)) {
                                $assignee->orWhereNotIn('department_node_id', $laneNodeIds);
                            }
                        });
                });
        } else {
            $query->whereHas('assignee', fn ($a) => $a->whereIn('department_node_id', $lane['node_ids']));
        }

        return $this->sortBySla($query->get())->values();
    }

    private function activeTicketQuery(?int $companyId = null): \Illuminate\Database\Eloquent\Builder
    {
        $query = Ticket::query();

        if ($companyId) {
            $query->withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
                ->where('company_id', $companyId);
        }

        return $query;
    }

    private function dynamicNodeIdFromLaneKey(?string $laneKey): ?int
    {
        if (!$laneKey || !str_starts_with($laneKey, self::DYNAMIC_NODE_PREFIX)) {
            return null;
        }

        $nodeId = substr($laneKey, strlen(self::DYNAMIC_NODE_PREFIX));

        return ctype_digit($nodeId) ? (int) $nodeId : null;
    }

    private function sortBySla(Collection $tickets): Collection
    {
        return $tickets->sort(function ($a, $b) {
            $at = $a->slaMetric?->resolution_target_at;
            $bt = $b->slaMetric?->resolution_target_at;

            if ($at === null && $bt === null) {
                return $a->created_at <=> $b->created_at;
            }
            if ($at === null) {
                return 1; // nulls last
            }
            if ($bt === null) {
                return -1;
            }

            return $at <=> $bt;
        });
    }

    public function serialize(Ticket $t, ?int $position = null): array
    {
        $eta = $t->slaMetric?->resolution_target_at;

        return [
            'id' => $t->id,
            'ticket_key' => $t->ticket_key,
            'title' => $t->title,
            'status' => $t->status,
            'status_label' => $this->statusLabel($t->status),
            'priority' => $t->priority,
            'channel' => $t->channel,
            'queue_called_lane' => $t->queue_called_lane,
            'position' => $position,
            'assignee' => $t->assignee ? [
                'id' => $t->assignee->id,
                'name' => $t->assignee->name,
                'profile_photo' => $t->assignee->profile_photo,
            ] : null,
            'requester' => $t->reporter?->name ?? $t->sender_name,
            'item' => $t->item?->name,
            'eta' => $eta?->timezone('Asia/Manila')->format('Y-m-d H:i:s'),
            'eta_label' => $eta ? $eta->timezone('Asia/Manila')->format('M d, g:i A') : null,
            'eta_relative' => $eta ? $eta->diffForHumans() : null,
            'is_breached' => (bool) ($t->slaMetric?->is_resolution_breached || $t->slaMetric?->is_response_breached),
            'created_at' => $t->created_at?->timezone('Asia/Manila')->format('Y-m-d H:i:s'),
            'called_at' => $t->called_at?->timezone('Asia/Manila')->format('M d, g:i A'),
            'waiting_minutes' => $t->created_at ? (int) round($t->created_at->diffInMinutes(now())) : null,
        ];
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'In queue',
            'in_progress' => 'Now serving',
            'for_schedule' => 'Scheduled',
            'waiting_service_provider' => 'On hold — service provider',
            'waiting_client_feedback' => 'On hold — awaiting your reply',
            'resolved' => 'Resolved',
            'closed' => 'Completed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
