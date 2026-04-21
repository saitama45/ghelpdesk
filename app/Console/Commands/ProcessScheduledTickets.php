<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessScheduledTickets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickets:process-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Transition tickets from "for_schedule" to "in_progress" when their scheduled start time arrives.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now('Asia/Manila');

        // Find all tickets in 'for_schedule' status that have a linked schedule
        $tickets = Ticket::where('status', 'for_schedule')
            ->whereHas('schedule', function ($query) use ($now) {
                $query->where('start_time', '<=', $now);
            })
            ->with('schedule')
            ->get();

        if ($tickets->isEmpty()) {
            return;
        }

        foreach ($tickets as $ticket) {
            $this->info("Transitioning ticket #{$ticket->ticket_key} to in_progress (Scheduled start: {$ticket->schedule->start_time})");
            
            $ticket->update([
                'status' => 'in_progress'
            ]);

            // The TicketObserver will handle resuming the SLA clock
            // because 'for_schedule' is in the pausing list.
        }

        $this->info("Processed {$tickets->count()} scheduled tickets.");
    }
}
