<?php

namespace App\Observers;

use App\Models\Ticket;
use App\Models\TicketSlaMetric;
use App\Models\Company;
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
        if (!$ticket->ticket_key) {
            $company = Company::find($ticket->company_id);
            if ($company) {
                $ticket->ticket_key = $this->nextTicketKey($company->code);
            }
        }
    }

    /**
     * Handle the Ticket "updating" event.
     */
    public function updating(Ticket $ticket): void
    {
        if ($ticket->isDirty('company_id')) {
            $company = Company::find($ticket->company_id);
            if ($company) {
                $ticket->ticket_key = $this->nextTicketKey($company->code);
            }
        }
    }

    private function nextTicketKey(string $prefix): string
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            $maxNumber = Ticket::withTrashed()
                ->withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
                ->where('ticket_key', 'LIKE', "{$prefix}-%")
                ->selectRaw(
                    'MAX(TRY_CAST(SUBSTRING(ticket_key, LEN(?) + 2, LEN(ticket_key)) AS INT)) as max_num',
                    [$prefix]
                )
                ->value('max_num');

            return "{$prefix}-" . (($maxNumber ?? 0) + 1);
        }

        $pattern = '/^' . preg_quote($prefix, '/') . '-(\d+)$/';

        $maxNumber = Ticket::withTrashed()
            ->withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
            ->where('ticket_key', 'LIKE', "{$prefix}-%")
            ->pluck('ticket_key')
            ->map(function ($ticketKey) use ($pattern) {
                return preg_match($pattern, (string) $ticketKey, $matches)
                    ? (int) $matches[1]
                    : 0;
            })
            ->max() ?? 0;

        return "{$prefix}-" . ($maxNumber + 1);
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
        if ($ticket->is_deleted) {
            $ticket->forceFill(['is_deleted' => false])->save();
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
