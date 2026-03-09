<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\Setting;
use App\Services\SlaService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoCloseResolvedTickets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickets:auto-close';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically close resolved tickets after a configured period of inactivity, respecting SLA business hours.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hours = (int) Setting::get('auto_close_resolved_hours', 72);
        
        if ($hours <= 0) {
            $this->info('Auto-close is disabled (hours set to 0).');
            return;
        }

        $resolvedTickets = Ticket::where('status', 'resolved')->get();
        $this->info("Checking " . $resolvedTickets->count() . " resolved tickets...");

        $now = Carbon::now();
        $closedCount = 0;

        foreach ($resolvedTickets as $ticket) {
            $metric = $ticket->slaMetric;
            
            // 1. Get the base time: Start with resolution time or update time
            $baseTime = ($metric && $metric->resolved_at) 
                ? Carbon::parse($metric->resolved_at) 
                : $ticket->updated_at;

            // 2. Check for comments after resolution
            $latestComment = $ticket->comments()->latest()->first();
            if ($latestComment) {
                $commentTime = Carbon::parse($latestComment->created_at);
                if ($commentTime->greaterThan($baseTime)) {
                    $baseTime = $commentTime;
                }
            }

            // 3. Calculate when it should close based on business hours
            $closeDeadline = SlaService::addSecondsRespectingBusinessHours($baseTime, $hours * 3600);

            if ($now->greaterThanOrEqualTo($closeDeadline)) {
                $ticket->update(['status' => 'closed']);
                
                // Also add a history record for transparency
                \App\Models\TicketHistory::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => null, // System action
                    'column_changed' => 'status',
                    'old_value' => 'resolved',
                    'new_value' => 'closed',
                    'changed_at' => now('Asia/Manila'),
                    'remarks' => 'Automatically closed after ' . $hours . ' hours of inactivity.'
                ]);

                $closedCount++;
            }
        }

        $this->info("Auto-close process complete. Closed {$closedCount} tickets.");
    }
}
