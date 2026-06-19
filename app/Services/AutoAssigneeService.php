<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutoAssigneeService
{
    /**
     * Resolve auto-assignee for the given sender email.
     *
     * Returns ['assignee_id' => int|null, 'company_id' => int|null].
     * company_id is only set when the matched rule explicitly specifies one.
     */
    public function resolveAssignee(string $senderEmail): array
    {
        $senderEmail = strtolower(trim($senderEmail));
        if ($senderEmail === '') {
            return ['assignee_id' => null, 'company_id' => null];
        }

        $rulesRaw = Setting::get('auto_assignee_rules', '[]');
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

            return [
                'assignee_id' => $this->pickRoundRobin("rule:{$ruleEmail}", $ids),
                'company_id'  => $companyId,
            ];
        }

        $defaultsRaw = Setting::get('auto_assignee_defaults', '[]');
        $defaultIds = is_array($defaultsRaw) ? $defaultsRaw : (json_decode($defaultsRaw, true) ?? []);
        $defaultIds = array_values(array_filter(array_map('intval', $defaultIds)));

        if (empty($defaultIds)) {
            return ['assignee_id' => null, 'company_id' => null];
        }

        return [
            'assignee_id' => $this->pickRoundRobin('defaults', $defaultIds),
            'company_id'  => null,
        ];
    }

    private function pickRoundRobin(string $key, array $ids): int
    {
        $counter = Cache::increment("auto_assignee_counter:{$key}");
        return $ids[($counter - 1) % count($ids)];
    }
}
