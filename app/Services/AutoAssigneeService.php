<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutoAssigneeService
{
    public function resolveAssignee(string $senderEmail): ?int
    {
        $senderEmail = strtolower(trim($senderEmail));
        if ($senderEmail === '') {
            return null;
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

            return $this->pickRoundRobin("rule:{$ruleEmail}", $ids);
        }

        $defaultsRaw = Setting::get('auto_assignee_defaults', '[]');
        $defaultIds = is_array($defaultsRaw) ? $defaultsRaw : (json_decode($defaultsRaw, true) ?? []);
        $defaultIds = array_values(array_filter(array_map('intval', $defaultIds)));

        if (empty($defaultIds)) {
            return null;
        }

        return $this->pickRoundRobin('defaults', $defaultIds);
    }

    private function pickRoundRobin(string $key, array $ids): int
    {
        $counter = Cache::increment("auto_assignee_counter:{$key}");
        return $ids[($counter - 1) % count($ids)];
    }
}
