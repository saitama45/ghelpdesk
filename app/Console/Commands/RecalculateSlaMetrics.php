<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\User;
use App\Services\SlaService;
use Illuminate\Console\Command;

class RecalculateSlaMetrics extends Command
{
    protected $signature = 'sla:recalculate
                            {--ticket= : Filter to a single ticket by ticket_key (e.g. GH-42) or DB id}
                            {--dry-run : Preview changes without saving}';

    protected $description = 'Recalculate SLA response/resolution targets and breach flags for all tickets based on their current item priority and SLA settings.';

    public function handle(): int
    {
        $dryRun   = $this->option('dry-run');
        $ticketRef = $this->option('ticket');

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be saved.');
        }

        $query = Ticket::with(['slaMetric'])->whereHas('slaMetric');

        if ($ticketRef) {
            $query->where(function ($q) use ($ticketRef) {
                $q->where('ticket_key', $ticketRef)->orWhere('id', $ticketRef);
            });
        }

        $total   = 0;
        $updated = 0;
        $noItem  = 0;
        $noChange = 0;

        // Use chunk() instead of chunkById() for compatibility with UUID primary keys
        $query->chunk(100, function ($tickets) use ($dryRun, &$total, &$updated, &$noItem, &$noChange) {
            foreach ($tickets as $ticket) {
                $total++;
                $metric = $ticket->slaMetric;

                if (!$metric) {
                    continue;
                }

                $subUnit = $ticket->assignee_id
                    ? User::find($ticket->assignee_id)?->org_path
                    : null;

                // SlaService handles null item_id by defaulting to 'medium' priority
                $newResponseTarget   = SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'response', $subUnit);
                $newResolutionTarget = SlaService::calculateTarget($ticket->created_at, $ticket->item_id, 'resolution', $subUnit);

                // Preserve historical paused seconds by pushing targets forward
                $pausedSeconds = (int) $metric->total_paused_seconds;
                if ($pausedSeconds > 0) {
                    $newResponseTarget   = SlaService::addSecondsRespectingBusinessHours($newResponseTarget, $pausedSeconds, null, $subUnit);
                    $newResolutionTarget = SlaService::addSecondsRespectingBusinessHours($newResolutionTarget, $pausedSeconds, null, $subUnit);
                }

                $changes = [
                    'response_target_at'   => $newResponseTarget,
                    'resolution_target_at' => $newResolutionTarget,
                ];

                // Always re-evaluate breach flags:
                // - If first_response_at exists: compare it against new target
                // - If not: ensure flag is cleared (it can't be breached without a response)
                if ($metric->first_response_at) {
                    $changes['is_response_breached'] = $metric->first_response_at->gt($newResponseTarget);
                } else {
                    $changes['is_response_breached'] = false;
                }

                if ($metric->resolved_at) {
                    $changes['is_resolution_breached'] = $metric->resolved_at->gt($newResolutionTarget);
                } else {
                    $changes['is_resolution_breached'] = false;
                }

                $responseBreachedLabel   = $changes['is_response_breached']   ? 'BREACHED' : 'ok';
                $resolutionBreachedLabel = $changes['is_resolution_breached'] ? 'BREACHED' : 'ok';

                if (!$ticket->item_id) {
                    $noItem++;
                }

                $this->line(sprintf(
                    '  %s | item=%s | response_target: %s → %s [%s] | resolution_target: %s → %s [%s]',
                    $ticket->ticket_key ?? $ticket->id,
                    $ticket->item_id ? ($ticket->item?->name ?? $ticket->item_id) : 'none (default medium)',
                    optional($metric->response_target_at)->format('Y-m-d H:i:s') ?? 'null',
                    $newResponseTarget->format('Y-m-d H:i:s'),
                    $responseBreachedLabel,
                    optional($metric->resolution_target_at)->format('Y-m-d H:i:s') ?? 'null',
                    $newResolutionTarget->format('Y-m-d H:i:s'),
                    $resolutionBreachedLabel,
                ));

                if (!$dryRun) {
                    $metric->update($changes);
                }

                $updated++;
            }
        });

        $this->newLine();
        $this->info(sprintf(
            '%sProcessed %d ticket(s): %d updated (%d without item, used medium default).',
            $dryRun ? '[DRY RUN] ' : '',
            $total,
            $updated,
            $noItem,
        ));

        return self::SUCCESS;
    }
}
