<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\TicketSlaMetric;
use App\Models\User;
use App\Services\SlaService;
use Illuminate\Console\Command;

class RecalculateSlaMetrics extends Command
{
    protected $signature = 'sla:recalculate
                            {--ticket= : Recalculate a single ticket by ID}
                            {--dry-run : Preview changes without saving}';

    protected $description = 'Recalculate SLA response/resolution targets and breach flags for tickets based on their current item priority and SLA settings.';

    public function handle(): int
    {
        $dryRun  = $this->option('dry-run');
        $ticketId = $this->option('ticket');

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be saved.');
        }

        $query = Ticket::with(['slaMetric'])
            ->whereHas('slaMetric')
            ->whereNotNull('item_id');

        if ($ticketId) {
            $query->where('id', $ticketId);
        }

        $total   = 0;
        $updated = 0;
        $skipped = 0;

        $query->chunkById(100, function ($tickets) use ($dryRun, &$total, &$updated, &$skipped) {
            foreach ($tickets as $ticket) {
                $total++;
                $metric = $ticket->slaMetric;

                if (!$metric) {
                    $skipped++;
                    continue;
                }

                $subUnit = $ticket->assignee_id
                    ? User::find($ticket->assignee_id)?->org_path
                    : null;

                // Calculate base targets from creation date and current item priority
                $newResponseTarget   = SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'response', $subUnit);
                $newResolutionTarget = SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'resolution', $subUnit);

                // Push targets forward by historical paused seconds
                $pausedSeconds = (int) $metric->total_paused_seconds;
                if ($pausedSeconds > 0) {
                    $newResponseTarget   = SlaService::addSecondsRespectingBusinessHours($newResponseTarget, $pausedSeconds, null, $subUnit);
                    $newResolutionTarget = SlaService::addSecondsRespectingBusinessHours($newResolutionTarget, $pausedSeconds, null, $subUnit);
                }

                $changes = [
                    'response_target_at'   => $newResponseTarget,
                    'resolution_target_at' => $newResolutionTarget,
                ];

                if ($metric->first_response_at) {
                    $changes['is_response_breached'] = $metric->first_response_at->gt($newResponseTarget);
                }

                if ($metric->resolved_at) {
                    $changes['is_resolution_breached'] = $metric->resolved_at->gt($newResolutionTarget);
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '  Ticket %s: response_target %s → %s | resolution_target %s → %s%s%s',
                        $ticket->ticket_key ?? $ticket->id,
                        optional($metric->response_target_at)->format('Y-m-d H:i'),
                        $newResponseTarget->format('Y-m-d H:i'),
                        optional($metric->resolution_target_at)->format('Y-m-d H:i'),
                        $newResolutionTarget->format('Y-m-d H:i'),
                        isset($changes['is_response_breached']) ? ' | resp_breached=' . ($changes['is_response_breached'] ? 'true' : 'false') : '',
                        isset($changes['is_resolution_breached']) ? ' | resol_breached=' . ($changes['is_resolution_breached'] ? 'true' : 'false') : ''
                    ));
                } else {
                    $metric->update($changes);
                }

                $updated++;
            }
        });

        $this->info(sprintf(
            '%sProcessed %d ticket(s): %d recalculated, %d skipped (no metric).',
            $dryRun ? '[DRY RUN] ' : '',
            $total,
            $updated,
            $skipped
        ));

        return self::SUCCESS;
    }
}
