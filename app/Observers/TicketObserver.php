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
        
        TicketSlaMetric::create([
            'ticket_id' => $ticket->id,
            'response_target_at' => SlaService::calculateTarget($now, $ticket->item_id, 'response'),
            'resolution_target_at' => SlaService::calculateTarget($now, $ticket->item_id, 'resolution'),
        ]);
    }

    /**
     * Handle the Ticket "updated" event.
     */
    public function updated(Ticket $ticket): void
    {
        $metric = $ticket->slaMetric;

        if (!$metric) {
            // Try to create it if it doesn't exist
            $metric = TicketSlaMetric::create([
                'ticket_id' => $ticket->id,
                'response_target_at' => SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'response'),
                'resolution_target_at' => SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'resolution'),
            ]);
        }

        // 1. Handle First Response
        // Removed assignment check. Basis for response time is now strictly commenting (handled in TicketController).

        // 2. Handle Resolution
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
                $pausedSeconds = $metric->paused_at->diffInSeconds(Carbon::now());
                
                $data = [
                    'total_paused_seconds' => $metric->total_paused_seconds + $pausedSeconds,
                    'paused_at' => null,
                ];

                // Push targets forward by the paused duration, respecting business hours
                if ($metric->response_target_at && !$metric->first_response_at) {
                    $data['response_target_at'] = SlaService::addSecondsRespectingBusinessHours(
                        $metric->response_target_at, 
                        $pausedSeconds
                    );
                }
                
                if ($metric->resolution_target_at && !$metric->resolved_at) {
                    $data['resolution_target_at'] = SlaService::addSecondsRespectingBusinessHours(
                        $metric->resolution_target_at, 
                        $pausedSeconds
                    );
                }

                $metric->update($data);
            }
        }
    }
}
