<?php

namespace App\Console\Commands;

use App\Models\QatCycle;
use App\Models\Ticket;
use App\Models\UatCycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the fixtures the browser QA suite creates, and nothing else.
 *
 * Two guards make this safe to keep in the app:
 *  - it refuses to run in production, and
 *  - it only ever matches rows whose title starts with the E2E- marker.
 *
 * There is deliberately no "delete the newest N" or "delete since <date>" mode.
 * A cleanup that can be pointed at unmarked data is one bad flag away from
 * deleting somebody's real test cycle.
 */
class QatE2ePurge extends Command
{
    protected $signature = 'qat:e2e-purge {--json : Print a machine-readable summary}';

    protected $description = 'Delete QAT/UAT browser-test fixtures marked with the E2E- prefix';

    /** Every fixture the suite creates carries this. */
    private const MARKER = 'E2E-';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $counts = ['qat_cycles' => 0, 'uat_cycles' => 0, 'tickets' => 0];

        DB::transaction(function () use (&$counts) {
            $qatCycles = QatCycle::where('title', 'like', self::MARKER.'%')->get();

            // Tickets are collected now but deleted LAST. qat_findings.ticket_id is
            // a real foreign key, so removing the ticket while its finding still
            // points at it is refused — the findings have to go first, which
            // cascadeDelete does.
            $ticketIds = collect();

            foreach ($qatCycles as $cycle) {
                $ticketIds = $ticketIds->merge(
                    $cycle->findings()->whereNotNull('ticket_id')->pluck('ticket_id')->filter()
                );

                // A promoted UAT cycle is a fixture too — it exists only because
                // this run promoted the QAT cycle that made it.
                if ($cycle->promoted_uat_cycle_id) {
                    $uat = UatCycle::find($cycle->promoted_uat_cycle_id);

                    if ($uat && str_starts_with((string) $uat->title, self::MARKER)) {
                        Storage::disk('public')->deleteDirectory("signatures/uat/{$uat->id}");
                        Storage::disk('public')->deleteDirectory("uat/{$uat->id}");
                        $uat->cascadeDelete();
                        $counts['uat_cycles']++;
                    }
                }

                // Signature images live on disk, so cascadeDelete cannot reach them.
                Storage::disk('public')->deleteDirectory("signatures/qat/{$cycle->id}");
                Storage::disk('public')->deleteDirectory("qat/{$cycle->id}");

                $cycle->cascadeDelete();
                $counts['qat_cycles']++;
            }

            foreach ($ticketIds->unique() as $ticketId) {
                $ticket = Ticket::withoutGlobalScopes()->find($ticketId);

                // Only tickets this suite raised: the converter always titles them
                // "[F-001] ...", so an unbracketed title is somebody else's row.
                if (! $ticket || ! str_starts_with((string) $ticket->title, '[')) {
                    continue;
                }

                DB::table('ticket_attachments')->where('ticket_id', $ticketId)->delete();
                DB::table('ticket_sla_metrics')->where('ticket_id', $ticketId)->delete();
                DB::table('uat_findings')->where('ticket_id', $ticketId)->update(['ticket_id' => null]);
                $ticket->forceDelete();
                $counts['tickets']++;
            }

            // A promotion that ran without its QAT parent surviving still leaves a
            // marked UAT cycle behind; sweep those too.
            foreach (UatCycle::where('title', 'like', self::MARKER.'%')->get() as $orphan) {
                Storage::disk('public')->deleteDirectory("signatures/uat/{$orphan->id}");
                Storage::disk('public')->deleteDirectory("uat/{$orphan->id}");
                $orphan->cascadeDelete();
                $counts['uat_cycles']++;
            }
        });

        if ($this->option('json')) {
            $this->line(json_encode($counts));
        } else {
            $this->info(sprintf(
                'Purged %d QAT cycle(s), %d UAT cycle(s), %d ticket(s).',
                $counts['qat_cycles'],
                $counts['uat_cycles'],
                $counts['tickets']
            ));
        }

        return self::SUCCESS;
    }
}
