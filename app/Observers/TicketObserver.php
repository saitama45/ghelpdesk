<?php

namespace App\Observers;

use App\Models\Ticket;
use App\Models\TicketSlaMetric;
use App\Services\SlaService;
use Carbon\Carbon;

class TicketObserver
{
    /**
     * Handle the Ticket "created" event.
     */
    public function created(Ticket $ticket): void
    {
        $now = Carbon::now();
        $subUnit = $ticket->assignee_id ? \App\Models\User::find($ticket->assignee_id)?->sub_unit : null;
        
        TicketSlaMetric::create([
            'ticket_id' => $ticket->id,
            'response_target_at' => SlaService::calculateTarget($now, $ticket->item_id, 'response', $subUnit),
            'resolution_target_at' => SlaService::calculateTarget($now, $ticket->item_id, 'resolution', $subUnit),
        ]);
    }

    /**
     * Handle the Ticket "updated" event.
     */
    public function updated(Ticket $ticket): void
    {
        $metric = $ticket->slaMetric;
        $subUnit = $ticket->assignee_id ? \App\Models\User::find($ticket->assignee_id)?->sub_unit : null;

        if (!$metric) {
            // Try to create it if it doesn't exist
            $metric = TicketSlaMetric::create([
                'ticket_id' => $ticket->id,
                'response_target_at' => SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'response', $subUnit),
                'resolution_target_at' => SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'resolution', $subUnit),
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
            if ($newStatus === 'waiting') {
                $metric->update(['paused_at' => Carbon::now()]);
            } 
            // Resume SLA
            elseif ($oldStatus === 'waiting' && $metric->paused_at) {
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
                        $subUnit
                    );
                }
                
                if ($metric->resolution_target_at && !$metric->resolved_at) {
                    $data['resolution_target_at'] = SlaService::addSecondsRespectingBusinessHours(
                        $metric->resolution_target_at, 
                        $pausedSeconds,
                        null,
                        $subUnit
                    );
                }

                $metric->update($data);
            }
        }

        // Handle Assignment Change (Recalculate SLA targets for the new team's hours)
        if ($ticket->wasChanged('assignee_id') && $metric) {
            $updates = [];
            if (!$metric->first_response_at) {
                $updates['response_target_at'] = SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'response', $subUnit);
            }
            if (!$metric->resolved_at) {
                $updates['resolution_target_at'] = SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'resolution', $subUnit);
            }
            
            if (!empty($updates)) {
                $metric->update($updates);
            }
        }
    }
}
