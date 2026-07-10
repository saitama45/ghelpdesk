<?php

namespace App\Console\Commands;

use App\Models\FormRecord;
use App\Services\DynamicForms\FormServiceFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairDynamicFormTickets extends Command
{
    protected $signature = 'dynamic-forms:repair-tickets
        {ids* : Dynamic form record IDs to inspect}
        {--finalize-stuck : Finalize records whose approvals already satisfy their configured workflow}
        {--dry-run : Show what would happen without writing changes}';

    protected $description = 'Create missing tickets for supplied dynamic form records that are already final-approved';

    public function handle(FormServiceFactory $serviceFactory): int
    {
        $ids = collect($this->argument('ids'))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->error('Provide at least one form record ID.');

            return self::FAILURE;
        }

        $records = FormRecord::with(['definition', 'requestType', 'approvals', 'ticket'])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        $missingIds = $ids->diff($records->pluck('id')->map(fn ($id) => (int) $id));
        foreach ($missingIds as $missingId) {
            $this->warn("Record #{$missingId} was not found.");
        }

        $changed = 0;
        $dryRun = (bool) $this->option('dry-run');
        $finalizeStuck = (bool) $this->option('finalize-stuck');

        foreach ($records as $record) {
            if ($record->ticket_id && $record->ticket) {
                $this->line("Record #{$record->id}: skipped, already linked to {$record->ticket->ticket_key}.");

                continue;
            }

            if ($record->ticket_id && ! $record->ticket) {
                $this->warn("Record #{$record->id}: ticket {$record->ticket_id} was deleted — regenerating.");
            }

            $shouldFinalize = false;

            if ($record->status === 'Approved') {
                $shouldFinalize = true;
            } elseif ($finalizeStuck && $this->isApprovedButNotFinalized($record)) {
                $shouldFinalize = true;
            }

            if (! $shouldFinalize) {
                $this->warn("Record #{$record->id}: skipped, status {$record->status} is not final-approved.");

                continue;
            }

            if ($dryRun) {
                $this->info("Record #{$record->id}: would mark Approved/current level 0 if needed and create a linked ticket.");
                $changed++;

                continue;
            }

            DB::transaction(function () use ($record, $serviceFactory): void {
                $record->update([
                    'status' => 'Approved',
                    'current_approval_level' => 0,
                ]);

                $record->refresh();
                $serviceFactory->make($record->definition->slug)
                    ->processApprovedRequest($record->definition, $record);
            });

            $record->refresh()->load('ticket');
            $this->info("Record #{$record->id}: linked to {$record->ticket?->ticket_key}.");
            $changed++;
        }

        $this->info(($dryRun ? 'Dry run complete.' : 'Repair complete.') . " {$changed} record(s) processed.");

        return self::SUCCESS;
    }

    private function isApprovedButNotFinalized(FormRecord $record): bool
    {
        $totalLevels = $this->totalApprovalLevels($record);

        if ($totalLevels <= 0) {
            return false;
        }

        $approvedLevels = $record->approvals
            ->pluck('level')
            ->map(fn ($level) => (int) $level)
            ->filter()
            ->unique()
            ->count();

        return $approvedLevels >= $totalLevels;
    }

    private function totalApprovalLevels(FormRecord $record): int
    {
        if ($record->definition?->workflow_type === 'checklist') {
            $tasks = $record->data['_checklist_tasks'] ?? null;

            if (is_array($tasks)) {
                return count($tasks);
            }
        }

        if ($record->requestType) {
            return (int) ($record->requestType->approval_levels ?? 0);
        }

        return (int) ($record->definition?->approval_levels ?? 0);
    }
}
