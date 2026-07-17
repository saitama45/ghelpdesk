<?php

namespace App\Observers;

use App\Models\Ticket;
use App\Models\TicketKeyAlias;
use App\Models\TicketSlaMetric;
use App\Models\Company;
use App\Models\Store;
use App\Models\User;
use App\Services\SlaService;
use App\Services\LeadershipPointService;
use App\Models\PosRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TicketObserver
{
    /**
     * Handle the Ticket "creating" event.
     */
    public function creating(Ticket $ticket): void
    {
        // Every ticket must carry an owning entity: the entity-gated index
        // (whereIn company_id) can never match NULL, so an entity-less ticket
        // is invisible to every user. Derive it the way the key prefix does —
        // the store's owner first, then the creator's active entity, then the
        // TGI default — so unauthenticated pipelines (email fetch, schedulers,
        // kiosks) can never insert an invisible ticket.
        if (empty($ticket->company_id)) {
            $ticket->company_id = $this->fallbackCompanyId($ticket);
        }

        if (!$ticket->ticket_key) {
            $code = $this->keyCompanyCode($ticket);
            if ($code) {
                $ticket->ticket_key = $this->nextTicketKey($code);
            }
        }

        // Stable token for the public "Track my ticket" queue page.
        // Guarded so ticket creation still works before the queue migration runs.
        if (self::ticketsHaveQueueToken() && empty($ticket->queue_track_token)) {
            $ticket->queue_track_token = \Illuminate\Support\Str::random(40);
        }
    }

    private static ?bool $hasQueueToken = null;

    private static function ticketsHaveQueueToken(): bool
    {
        if (self::$hasQueueToken === null) {
            self::$hasQueueToken = \Illuminate\Support\Facades\Schema::hasColumn('tickets', 'queue_track_token');
        }

        return self::$hasQueueToken;
    }

    /**
     * The owning entity for a ticket whose creation path supplied none:
     * the store's owning company, else the creator's active entity, else the
     * TGI default entity, else the first company (empty table = stays null).
     */
    private function fallbackCompanyId(Ticket $ticket): ?int
    {
        if ($ticket->store_id) {
            $storeCompanyId = Store::whereKey($ticket->store_id)->value('company_id');
            if ($storeCompanyId) {
                return (int) $storeCompanyId;
            }
        }

        $activeId = \App\Support\CompanyContext::activeCompanyId();
        if ($activeId) {
            return $activeId;
        }

        $defaultId = Company::where('code', \App\Support\CompanyContext::DEFAULT_COMPANY_CODE)->value('id');
        if ($defaultId) {
            return (int) $defaultId;
        }

        return Company::query()->value('id');
    }

    /**
     * Handle the Ticket "updating" event.
     */
    public function updating(Ticket $ticket): void
    {
        // company_id is never cleared: a NULL owner would make the ticket
        // invisible to the entity-gated index for everyone. Any update trying
        // to blank it keeps the previous owner instead (and thereby also keeps
        // the ticket_key from being renumbered to EXT-*).
        if ($ticket->isDirty('company_id') && empty($ticket->company_id) && $ticket->getOriginal('company_id')) {
            $ticket->company_id = $ticket->getOriginal('company_id');
        }

        // Repair legacy tickets that were inserted before every creation path
        // guaranteed a key. Any ordinary save/touch now assigns the next safe
        // entity key, or EXT-* when the ticket has no resolvable company.
        if (!$ticket->ticket_key) {
            $ticket->ticket_key = $this->nextTicketKey($this->keyCompanyCode($ticket) ?? 'EXT');

            return;
        }

        // The key follows the STORE's owning company, so regenerate when either the
        // store or the (fallback) company changes — but only if the resolved prefix
        // actually differs, so we don't renumber the ticket needlessly.
        if ($ticket->isDirty('store_id') || $ticket->isDirty('company_id')) {
            $code = $this->keyCompanyCode($ticket);
            if ($code && !$this->keyHasPrefix($ticket->ticket_key, $code)) {
                $ticket->ticket_key = $this->nextTicketKey($code);
            }
        }
    }

    /**
     * The company code that drives a ticket's key: the owning company of the ticket's
     * STORE, falling back to the ticket's own company for store-less tickets.
     */
    private function keyCompanyCode(Ticket $ticket): ?string
    {
        if ($ticket->store_id) {
            $store = Store::find($ticket->store_id);
            if ($store && $store->company_id) {
                $code = Company::find($store->company_id)?->code;
                if ($code) {
                    return $code;
                }
            }
        }

        // SQL Server unique indexes allow only one NULL value. Always return a
        // stable fallback prefix so an unresolved company can never produce a
        // second NULL ticket_key and block ticket creation system-wide.
        return $ticket->company_id ? (Company::find($ticket->company_id)?->code ?: 'EXT') : 'EXT';
    }

    private function keyHasPrefix(?string $ticketKey, string $code): bool
    {
        return $ticketKey !== null && str_starts_with($ticketKey, $code . '-');
    }

    private function nextTicketKey(string $prefix): string
    {
        // The next number is one past the highest ever used for this prefix —
        // across live/trashed tickets AND retired keys (aliases), so a number that
        // was renumbered away is never handed back out to a different ticket.
        $maxNumber = $this->maxKeyNumberForPrefix('tickets', 'ticket_key', $prefix);

        if (self::ticketKeyAliasesTableExists()) {
            $maxNumber = max(
                $maxNumber,
                $this->maxKeyNumberForPrefix('ticket_key_aliases', 'ticket_key', $prefix)
            );
        }

        return "{$prefix}-" . ($maxNumber + 1);
    }

    /**
     * Highest numeric suffix of "{$prefix}-N" keys in the given table/column.
     * Uses a raw table query so it spans every row (soft-deleted included) and
     * ignores Eloquent global scopes — keys must be unique across all entities.
     */
    private function maxKeyNumberForPrefix(string $table, string $column, string $prefix): int
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            return (int) DB::table($table)
                ->where($column, 'LIKE', "{$prefix}-%")
                ->selectRaw(
                    "MAX(TRY_CAST(SUBSTRING({$column}, LEN(?) + 2, LEN({$column})) AS INT)) as max_num",
                    [$prefix]
                )
                ->value('max_num');
        }

        $pattern = '/^' . preg_quote($prefix, '/') . '-(\d+)$/';

        return (int) (DB::table($table)
            ->where($column, 'LIKE', "{$prefix}-%")
            ->pluck($column)
            ->map(function ($key) use ($pattern) {
                return preg_match($pattern, (string) $key, $matches)
                    ? (int) $matches[1]
                    : 0;
            })
            ->max() ?? 0);
    }

    private static ?bool $hasKeyAliases = null;

    private static function ticketKeyAliasesTableExists(): bool
    {
        if (self::$hasKeyAliases === null) {
            self::$hasKeyAliases = \Illuminate\Support\Facades\Schema::hasTable('ticket_key_aliases');
        }

        return self::$hasKeyAliases;
    }

    /**
     * When a ticket is renumbered (Company/Store change), remember the key it used
     * to carry so old email threads and links still resolve to it.
     */
    private function recordPreviousKeyAlias(Ticket $ticket): void
    {
        if (! self::ticketKeyAliasesTableExists()) {
            return;
        }

        $oldKey = (string) $ticket->getOriginal('ticket_key');

        if ($oldKey === '' || $oldKey === (string) $ticket->ticket_key) {
            return;
        }

        // Don't alias a key that some other ticket is currently using — the live
        // ticket owns that key, so an alias would only muddy the lookup.
        $keyIsLive = Ticket::withTrashed()
            ->withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
            ->where('ticket_key', $oldKey)
            ->exists();

        if ($keyIsLive) {
            return;
        }

        TicketKeyAlias::updateOrCreate(
            ['ticket_key' => $oldKey],
            ['ticket_id' => $ticket->id]
        );
    }

    /**
     * Handle the Ticket "created" event.
     */
    public function created(Ticket $ticket): void
    {
        $now = Carbon::now();
        $assignee = $ticket->assignee_id ? User::find($ticket->assignee_id) : null;

        $metric = TicketSlaMetric::create([
            'ticket_id' => $ticket->id,
            'response_target_at' => SlaService::calculateTarget($now, $ticket->item_id, 'response', $assignee?->org_path, $assignee?->department_id, $assignee?->department_node_id),
            'resolution_target_at' => SlaService::calculateTarget($now, $ticket->item_id, 'resolution', $assignee?->org_path, $assignee?->department_id, $assignee?->department_node_id),
        ]);

        if (in_array($ticket->status, ['waiting_service_provider', 'waiting_client_feedback', 'for_schedule'])) {
            $metric->update(['paused_at' => $now]);
        }
    }

    /**
     * Handle the Ticket "updated" event.
     */
    public function updated(Ticket $ticket): void
    {
        // Preserve the retired key whenever a Company/Store change renumbered it.
        if ($ticket->wasChanged('ticket_key')) {
            $this->recordPreviousKeyAlias($ticket);
        }

        $metric = $ticket->slaMetric;
        $assignee = $ticket->assignee_id ? User::find($ticket->assignee_id) : null;
        $subUnit = $assignee?->org_path;
        $departmentId = $assignee?->department_id;
        $departmentNodeId = $assignee?->department_node_id;

        if (!$metric) {
            // Try to create it if it doesn't exist
            $metric = TicketSlaMetric::create([
                'ticket_id' => $ticket->id,
                'response_target_at' => SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'response', $subUnit, $departmentId, $departmentNodeId),
                'resolution_target_at' => SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'resolution', $subUnit, $departmentId, $departmentNodeId),
            ]);
        }

        // Handle Resolution
        if ($ticket->wasChanged('status')) {
            $newStatus = $ticket->status;
            $oldStatus = $ticket->getOriginal('status');

            if (in_array($newStatus, ['resolved', 'closed']) && !$metric->resolved_at) {
                $metric->update([
                    'resolved_at' => Carbon::now(),
                    'is_resolution_breached' => $metric->resolution_target_at && Carbon::now()->gt($metric->resolution_target_at),
                ]);
            } 
            elseif (!in_array($newStatus, ['resolved', 'closed']) && in_array($oldStatus, ['resolved', 'closed'])) {
                $metric->update(['resolved_at' => null]);
            }

            // 3. Handle Pausing (Waiting factors)
            if (in_array($newStatus, ['waiting_service_provider', 'waiting_client_feedback', 'for_schedule'])) {
                $metric->update(['paused_at' => Carbon::now()]);
            } 
            // Resume SLA
            elseif (in_array($oldStatus, ['waiting_service_provider', 'waiting_client_feedback', 'for_schedule']) && $metric->paused_at) {
                $pausedSeconds = (int) $metric->paused_at->diffInSeconds(Carbon::now());
                
                $data = [
                    'total_paused_seconds' => (int) ($metric->total_paused_seconds + $pausedSeconds),
                    'paused_at' => null,
                ];

                // Push targets forward by the paused duration, respecting business hours
                if ($metric->response_target_at && !$metric->first_response_at) {
                    $data['response_target_at'] = SlaService::addSecondsRespectingBusinessHours(
                        $metric->response_target_at, 
                        $pausedSeconds,
                        null,
                        $subUnit,
                        $departmentId,
                        $departmentNodeId
                    );
                }
                
                if ($metric->resolution_target_at && !$metric->resolved_at) {
                    $data['resolution_target_at'] = SlaService::addSecondsRespectingBusinessHours(
                        $metric->resolution_target_at, 
                        $pausedSeconds,
                        null,
                        $subUnit,
                        $departmentId,
                        $departmentNodeId
                    );
                }

                $metric->update($data);
            }

            // --- POS Request Status Sync ---
            $posRequest = PosRequest::where('ticket_id', $ticket->id)->first();
            if ($posRequest) {
                $statusMap = [
                    'open' => 'Approved',
                    'for_schedule' => 'In Progress',
                    'in_progress' => 'In Progress',
                    'resolved' => 'Resolved',
                    'closed' => 'Resolved',
                    'waiting_service_provider' => 'In Progress',
                    'waiting_client_feedback' => 'In Progress',
                ];

                if (isset($statusMap[$newStatus])) {
                    $posRequest->update(['status' => $statusMap[$newStatus]]);
                }
            }

            // --- Parent Ticket Status Sync ---
            if ($ticket->parent_id) {
                $this->syncParentStatus($ticket->parent_id, $newStatus);
            }

            // --- Leadership Points Award ---
            if ($newStatus === 'closed' && $ticket->assignee_id) {
                app(LeadershipPointService::class)->awardPointsForClosedTicket($ticket);
            }
        }

        // Handle Assignment Change (Recalculate SLA targets for the new team's hours)
        if ($ticket->wasChanged('assignee_id') && $metric) {
            $updates = [];
            if (!$metric->first_response_at) {
                $updates['response_target_at'] = SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'response', $subUnit, $departmentId, $departmentNodeId);
            }
            if (!$metric->resolved_at) {
                $updates['resolution_target_at'] = SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'resolution', $subUnit, $departmentId, $departmentNodeId);
            }

            if (!empty($updates)) {
                $metric->update($updates);
            }
        }

        // Handle Item Change (Recalculate SLA targets based on the new item's priority)
        if ($ticket->wasChanged('item_id') && $metric) {
            $updates = [];

            $newResponseTarget = SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'response', $subUnit, $departmentId, $departmentNodeId);
            if ((int) $metric->total_paused_seconds > 0) {
                $newResponseTarget = SlaService::addSecondsRespectingBusinessHours($newResponseTarget, (int) $metric->total_paused_seconds, null, $subUnit, $departmentId, $departmentNodeId);
            }
            $updates['response_target_at'] = $newResponseTarget;

            // Re-evaluate breach status against new target if response was already recorded
            if ($metric->first_response_at) {
                $updates['is_response_breached'] = $metric->first_response_at->gt($newResponseTarget);
            }

            $newResolutionTarget = SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'resolution', $subUnit, $departmentId, $departmentNodeId);
            if ((int) $metric->total_paused_seconds > 0) {
                $newResolutionTarget = SlaService::addSecondsRespectingBusinessHours($newResolutionTarget, (int) $metric->total_paused_seconds, null, $subUnit, $departmentId, $departmentNodeId);
            }
            $updates['resolution_target_at'] = $newResolutionTarget;

            // Re-evaluate breach status against new target if resolution was already recorded
            if ($metric->resolved_at) {
                $updates['is_resolution_breached'] = $metric->resolved_at->gt($newResolutionTarget);
            }

            $metric->update($updates);
        }
    }

    /**
     * Handle the Ticket "deleted" event.
     */
    public function deleted(Ticket $ticket): void
    {
        if (!$ticket->is_deleted) {
            $ticket->forceFill(['is_deleted' => true])->save();
        }
    }

    /**
     * Handle the Ticket "restored" event.
     */
    public function restored(Ticket $ticket): void
    {
        if ($ticket->is_deleted || $ticket->deleted_by) {
            $ticket->forceFill(['is_deleted' => false, 'deleted_by' => null])->save();
        }
    }

    /**
     * Internal helper to sync parent status based on children.
     */
    private function syncParentStatus($parentId, $triggeredStatus)
    {
        $parent = Ticket::find($parentId);
        if (!$parent) return;

        $allChildren = Ticket::where('parent_id', $parentId)->get();
        
        if (in_array($triggeredStatus, ['resolved', 'closed'])) {
            // Check if ALL children are terminal (resolved or closed)
            $allDone = $allChildren->every(function($child) {
                return in_array($child->status, ['resolved', 'closed']);
            });

            if ($allDone) {
                // If all are terminal, set parent to the triggered status (resolved or closed)
                $parent->update(['status' => $triggeredStatus]);
            }
        } else {
            // If any child is updated to an active status, parent reflects it
            $parent->update(['status' => $triggeredStatus]);
        }
    }
}
