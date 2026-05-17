<?php

namespace App\Console\Commands;

use App\Mail\PaymentDueReminderMail;
use App\Models\PaymentInvoice;
use App\Models\PaymentReminderLog;
use App\Models\PaymentRenewal;
use App\Models\PaymentSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

class SendPaymentDueReminders extends Command
{
    protected $signature = 'payments:send-due-reminders {--pretend : Dry-run; do not send mail or write logs}';
    protected $description = 'Send due-date reminder emails for unpaid payment renewals and invoices';

    public function handle(): int
    {
        $settings = PaymentSetting::current();
        if (!$settings->reminders_enabled) {
            $this->info('Reminders disabled in settings — exiting.');
            return self::SUCCESS;
        }

        $today = Carbon::now()->startOfDay();
        $pretend = (bool) $this->option('pretend');
        $sentCount = 0;

        // CC recipients = users in CC role (if configured)
        $ccEmails = [];
        if ($settings->cc_role_id) {
            $role = Role::find($settings->cc_role_id);
            if ($role) {
                $ccEmails = $role->users()->pluck('email')->filter()->values()->all();
            }
        }
        $bcc = $settings->global_bcc ? [$settings->global_bcc] : [];

        // ----- INVOICES -----
        $invoices = PaymentInvoice::with('assignee:id,name,email', 'vendor:id,name')
            ->whereNotIn('status', ['Paid', 'Cancelled'])
            ->whereNotNull('due_date')
            ->get();

        foreach ($invoices as $inv) {
            $due = Carbon::parse($inv->due_date)->startOfDay();
            $reminderType = $this->resolveReminderType($today, $due);
            if (!$reminderType) continue;

            // Idempotency: 30d/7d/1d/due send once per window_date; overdue sends daily (window_date = today)
            $windowDate = $reminderType === 'overdue' ? $today->toDateString() : $due->toDateString();

            $already = PaymentReminderLog::where('payable_type', 'invoice')
                ->where('payable_id', $inv->id)
                ->where('reminder_type', $reminderType)
                ->where('window_date', $windowDate)
                ->exists();
            if ($already) continue;

            $payload = [
                'title' => trim(($inv->apv_no ? "APV {$inv->apv_no} " : '') . ($inv->si_number ? "SI {$inv->si_number} " : '') . ($inv->store_code ?? '')),
                'amount' => (float) $inv->outstanding_amount,
                'due_date' => $due->toDateString(),
            ];

            $toEmail = $inv->assignee?->email;
            $rowCc = $this->parseCcEmails($inv->cc_emails);
            $mergedCc = array_values(array_filter(array_unique(array_merge($ccEmails, $rowCc))));

            if (!$toEmail && empty($mergedCc) && empty($bcc)) {
                continue;
            }

            $this->dispatchReminder('invoice', $inv->id, $toEmail, $mergedCc, $bcc, $payload, $reminderType, $inv->vendor?->name, $windowDate, $pretend);
            $sentCount++;
        }

        // ----- RENEWALS -----
        $renewals = PaymentRenewal::with('assignee:id,name,email', 'vendor:id,name')
            ->where('status', 'active')
            ->whereNotNull('next_due_date')
            ->get();

        foreach ($renewals as $r) {
            $due = Carbon::parse($r->next_due_date)->startOfDay();
            $reminderType = $this->resolveReminderType($today, $due);
            if (!$reminderType) continue;

            $windowDate = $reminderType === 'overdue' ? $today->toDateString() : $due->toDateString();

            $already = PaymentReminderLog::where('payable_type', 'renewal')
                ->where('payable_id', $r->id)
                ->where('reminder_type', $reminderType)
                ->where('window_date', $windowDate)
                ->exists();
            if ($already) continue;

            $payload = [
                'title' => trim("{$r->service_type}" . ($r->sub_type ? " — {$r->sub_type}" : '') . ($r->purpose ? " ({$r->purpose})" : '')),
                'amount' => (float) $r->total_amount,
                'due_date' => $due->toDateString(),
            ];

            $toEmail = $r->assignee?->email;
            $rowCc = $this->parseCcEmails($r->cc_emails);
            $mergedCc = array_values(array_filter(array_unique(array_merge($ccEmails, $rowCc))));

            if (!$toEmail && empty($mergedCc) && empty($bcc)) {
                continue;
            }

            $this->dispatchReminder('renewal', $r->id, $toEmail, $mergedCc, $bcc, $payload, $reminderType, $r->vendor?->name, $windowDate, $pretend);
            $sentCount++;
        }

        $this->info(($pretend ? '[DRY-RUN] ' : '') . "Reminders queued: {$sentCount}");
        return self::SUCCESS;
    }

    protected function parseCcEmails(?string $emails): array
    {
        if (!$emails) return [];
        return array_filter(array_map('trim', explode(',', $emails)));
    }

    protected function resolveReminderType(Carbon $today, Carbon $due): ?string
    {
        $diff = (int) round($today->diffInDays($due, false)); // signed; negative if today > due
        if ($diff === 30) return '30d';
        if ($diff === 7) return '7d';
        if ($diff === 1) return '1d';
        if ($diff === 0) return 'due';
        if ($diff < 0) return 'overdue';
        return null;
    }

    protected function dispatchReminder(string $type, int $id, ?string $toEmail, array $cc, array $bcc, array $payload, string $reminderType, ?string $vendorName, string $windowDate, bool $pretend): void
    {
        $recipients = array_values(array_filter(array_unique(array_merge([$toEmail], $cc, $bcc))));
        $logLine = sprintf(
            '[Reminder] %s#%d type=%s window=%s to=%s cc=%d bcc=%d',
            $type, $id, $reminderType, $windowDate, $toEmail ?? 'NONE', count($cc), count($bcc)
        );
        $this->line(($pretend ? '[DRY-RUN] ' : '') . $logLine);
        if ($pretend) return;

        try {
            $mailable = new PaymentDueReminderMail($type, $payload, $reminderType, $vendorName);
            $pending = $toEmail ? Mail::to($toEmail) : Mail::to($cc[0] ?? $bcc[0]);
            if (!empty($cc)) $pending->cc($cc);
            if (!empty($bcc)) $pending->bcc($bcc);
            $pending->send($mailable);

            PaymentReminderLog::create([
                'payable_type' => $type,
                'payable_id' => $id,
                'reminder_type' => $reminderType,
                'window_date' => $windowDate,
                'sent_at' => now(),
                'recipients' => $recipients,
            ]);
        } catch (\Throwable $e) {
            Log::error('payments:send-due-reminders failed', [
                'type' => $type, 'id' => $id, 'reminder' => $reminderType, 'error' => $e->getMessage(),
            ]);
        }
    }
}
