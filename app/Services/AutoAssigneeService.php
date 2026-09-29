<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutoAssigneeService
{
    public function __construct(private readonly TicketDutyAssigner $duty)
    {
    }

    /**
     * Resolve auto-assignee for the given sender email.
     *
     * Order: sender email rule → Ticket Duty roster (when switched on) → global
     * default agents (only while the roster is off). With the roster on, a ticket
     * nobody is on duty for goes to the next shift, or stays unassigned when
     * there is none — the default agents are not a fallback.
     *
     * $context: serving_department_id, store_id — used by the duty roster.
     *
     * Returns ['assignee_id' => int|null, 'company_id' => int|null, 'store_id' => int|null, 'reason' => string|null].
     * company_id / store_id are only set when the matched rule explicitly specifies them.
     * reason is set for duty-roster picks; pass the result to recordReason() once the ticket exists.
     */
    public function resolveAssignee(string $senderEmail, array $context = []): array
    {
        $none = ['assignee_id' => null, 'company_id' => null, 'store_id' => null, 'reason' => null];
        $senderEmail = strtolower(trim($senderEmail));

        $rulesRaw = $senderEmail === '' ? '[]' : Setting::get('auto_assignee_rules', '[]');
        $rules = is_array($rulesRaw) ? $rulesRaw : (json_decode($rulesRaw, true) ?? []);

        foreach ($rules as $rule) {
            $ruleEmail = strtolower(trim($rule['email'] ?? ''));
            if ($ruleEmail === '' || $ruleEmail !== $senderEmail) {
                continue;
            }

            $ids = array_values(array_filter(array_map('intval', $rule['assignee_ids'] ?? [])));
            if (empty($ids)) {
                continue;
            }

            $companyId = isset($rule['company_id']) && $rule['company_id'] ? (int) $rule['company_id'] : null;
            $storeId = isset($rule['store_id']) && $rule['store_id'] ? (int) $rule['store_id'] : null;

            return [
                'assignee_id' => $this->pickRoundRobin("rule:{$ruleEmail}", $ids),
                'company_id'  => $companyId,
                'store_id'    => $storeId,
                'reason'      => null,
            ];
        }

        if ($this->duty->enabled()) {
            return $this->resolveFromDuty($context);
        }

        // No sender, nothing to match defaults against (unchanged behaviour).
        if ($senderEmail === '') {
            return $none;
        }

        $defaultsRaw = Setting::get('auto_assignee_defaults', '[]');
        $defaultIds = is_array($defaultsRaw) ? $defaultsRaw : (json_decode($defaultsRaw, true) ?? []);
        $defaultIds = array_values(array_filter(array_map('intval', $defaultIds)));

        if (empty($defaultIds)) {
            return $none;
        }

        return [
            'assignee_id' => $this->pickRoundRobin('defaults', $defaultIds),
            'company_id'  => null,
            'store_id'    => null,
            'reason'      => null,
        ];
    }

    /**
     * Duty roster only — for intake paths that never applied sender rules or the
     * default agents (POS, SAP, dynamic forms). All nulls while the roster is off.
     */
    public function resolveFromDuty(array $context = []): array
    {
        $pick = $this->duty->pick(
            isset($context['serving_department_id']) ? (int) $context['serving_department_id'] : null,
            isset($context['store_id']) ? (int) $context['store_id'] : null,
        );

        return [
            'assignee_id' => $pick['assignee_id'] ?? null,
            'company_id'  => null,
            'store_id'    => null,
            'reason'      => $pick['reason'] ?? null,
        ];
    }

    /**
     * Write why the duty roster chose this assignee onto the ticket's activity,
     * so people can see the assignment was deliberate. No-op for rule/default
     * picks, or when something else changed the assignee before saving.
     */
    public function recordReason(Ticket $ticket, array $resolved): void
    {
        $reason = $resolved['reason'] ?? null;
        $assigneeId = (int) ($resolved['assignee_id'] ?? 0);

        if (! $reason || ! $assigneeId || (int) $ticket->assignee_id !== $assigneeId) {
            return;
        }

        try {
            TicketHistory::create([
                'ticket_id' => $ticket->id,
                'user_id' => null,
                'column_changed' => 'assignee_id',
                'old_value' => '',
                'new_value' => User::whereKey($assigneeId)->value('name')." (auto-assigned: {$reason})",
                'changed_at' => now('Asia/Manila'),
            ]);
        } catch (\Throwable $e) {
            // The assignment itself already stands; never fail intake over its note.
            Log::warning('Could not record ticket duty assignment reason.', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function pickRoundRobin(string $key, array $ids): int
    {
        $counter = Cache::increment("auto_assignee_counter:{$key}");
        return $ids[($counter - 1) % count($ids)];
    }
}
