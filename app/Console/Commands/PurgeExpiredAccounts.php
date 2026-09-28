<?php

namespace App\Console\Commands;

use App\Services\AccountArchiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Carries out the second stage of a member's account deletion: once an archived
 * loyalty customer has been kept for the retention window (Settings → Account
 * Retention), it is deleted for good, with its app login.
 *
 * Why this is not left to the Purge button: the App Store (5.1.1(v)) and Google
 * Play both reject a "deletion" that only deactivates an account, and
 * /account-deletion promises permanent deletion after the window. A purge that
 * waits for someone to remember it keeps the data indefinitely.
 *
 * A customer holding reward redemptions or voucher payments is anonymized
 * instead — those are financial records and stay, without the person.
 *
 * Archived STAFF logins (Account Archive → Users) are deliberately not touched:
 * purging one deletes its attendance and schedules, which stays a human decision.
 */
class PurgeExpiredAccounts extends Command
{
    protected $signature = 'accounts:purge-expired
                            {--dry-run : List what would be purged or anonymized without changing anything}';

    protected $description = 'Permanently delete archived loyalty customers (and their app logins) once the account retention period has passed; anonymize those holding financial records.';

    public function handle(AccountArchiveService $archive): int
    {
        $retention = $archive->retention();
        $dryRun = (bool) $this->option('dry-run');
        $expired = $archive->expiredArchivedCustomers($retention['cutoff']);

        $this->info(sprintf(
            '%d archived customer(s) older than %s (archived on or before %s).%s',
            $expired->count(),
            $retention['label'],
            $retention['cutoff']->format('Y-m-d H:i'),
            $dryRun ? ' Dry run — nothing will change.' : ''
        ));

        $done = ['purged' => 0, 'anonymized' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($expired as $customer) {
            $label = "#{$customer->id} {$customer->name}";
            $login = $archive->userFor($customer);

            // The pair is out of step (a restored login over an archived
            // customer). Restore or archive it by hand; never guess here.
            if ($login && ! $login->trashed()) {
                $this->warn("Skipped {$label}: its app login is still active.");
                $done['skipped']++;

                continue;
            }

            $anonymize = $archive->holdsFinancialRecords($customer);
            $action = $anonymize ? 'anonymize' : 'purge';

            if ($dryRun) {
                $this->line("Would {$action} {$label}".($login ? " and its app login #{$login->id}" : '').'.');

                continue;
            }

            try {
                $anonymize
                    ? $archive->anonymizeCustomer($customer, null)
                    : $archive->purgeCustomer($customer, null);

                $done[$anonymize ? 'anonymized' : 'purged']++;
                $this->line(($anonymize ? 'Anonymized' : 'Purged')." {$label}.");
            } catch (\Throwable $e) {
                // One account in the way must not hold back everyone after it.
                $done['failed']++;
                $this->error("Could not {$action} {$label}: {$e->getMessage()}");
                Log::error('accounts:purge-expired could not process an archived customer', [
                    'customer_id' => $customer->id,
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $dryRun) {
            $this->info("Purged {$done['purged']}, anonymized {$done['anonymized']}, skipped {$done['skipped']}, failed {$done['failed']}.");

            if ($expired->isNotEmpty()) {
                Log::info('accounts:purge-expired finished', $done + ['retention' => $retention['label']]);
            }
        }

        return $done['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
