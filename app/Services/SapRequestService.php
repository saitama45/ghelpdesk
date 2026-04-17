<?php

namespace App\Services;

use App\Models\SapRequest;
use App\Models\RequestType;
use App\Models\Ticket;
use App\Mail\SapRequestNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SapRequestService
{
    public function createRequest(array $data, ?int $userId = null): SapRequest
    {
        return DB::transaction(function () use ($data, $userId) {
            $requestType = RequestType::findOrFail($data['request_type_id']);
            $effectiveApprovalLevels = $this->getEffectiveApprovalLevels($requestType, $data['form_data'] ?? []);

            $sapRequest = SapRequest::create([
                'company_id'            => $data['company_id'],
                'request_type_id'       => $data['request_type_id'],
                'user_id'               => $userId,
                'requester_name'        => $data['requester_name'] ?? null,
                'requester_email'       => $data['requester_email'] ?? null,
                'status'                => $effectiveApprovalLevels === 0 ? 'Approved' : 'Open',
                'current_approval_level'=> $effectiveApprovalLevels === 0 ? 0 : 1,
                'form_data'             => $data['form_data'] ?? [],
            ]);

            // Store tabular items (New Item Request / New BOM)
            Log::info('Processing items for SAP Request', ['items_count' => count($data['items'] ?? [])]);
            if (!empty($data['items'])) {
                foreach ($data['items'] as $index => $itemData) {
                    Log::info('Creating item', ['index' => $index, 'data' => $itemData]);
                    $sapRequest->items()->create([
                        'item_data'  => $itemData,
                        'sort_order' => $index,
                    ]);
                }
            }

            if ($sapRequest->status === 'Approved') {
                $this->processApprovedRequest($sapRequest);
            }

            // Refresh relationships to ensure mailable has access to company, requestType and items
            $sapRequest->load(['company', 'requestType', 'items']);

            $this->notifyCcEmails($sapRequest, 'created');
            
            // Send confirmation to requester
            $this->notifyRequester($sapRequest, 'created');

            return $sapRequest;
        });
    }

    public function updateRequest(SapRequest $sapRequest, array $data): SapRequest
    {
        return DB::transaction(function () use ($sapRequest, $data) {
            $sapRequest->update([
                'company_id'      => $data['company_id'],
                'request_type_id' => $data['request_type_id'],
                'form_data'       => $data['form_data'] ?? [],
            ]);

            $sapRequest->items()->delete();
            Log::info('Updating items for SAP Request', ['items_count' => count($data['items'] ?? [])]);
            if (!empty($data['items'])) {
                foreach ($data['items'] as $index => $itemData) {
                    Log::info('Re-creating item', ['index' => $index, 'data' => $itemData]);
                    $sapRequest->items()->create([
                        'item_data'  => $itemData,
                        'sort_order' => $index,
                    ]);
                }
            }

            // Refresh relationships
            $sapRequest->load(['company', 'requestType', 'items']);

            $this->notifyCcEmails($sapRequest, 'updated');

            return $sapRequest;
        });
    }

    /**
     * Notify requester about SAP Request action.
     */
    protected function notifyRequester(SapRequest $sapRequest, string $action): void
    {
        $requesterEmail = $sapRequest->user ? $sapRequest->user->email : $sapRequest->requester_email;
        
        if (!$requesterEmail || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) return;

        try {
            Mail::to($requesterEmail)->send(new SapRequestNotification($sapRequest, $action, true));
        } catch (\Exception $e) {
            Log::error('Failed to send SAP Request confirmation to requester: ' . $e->getMessage());
        }
    }

    protected function notifyCcEmails(SapRequest $sapRequest, string $action): void
    {
        $ccEmails = $sapRequest->requestType->cc_emails;
        if (!$ccEmails) return;

        $emails = array_map('trim', explode("\n", $ccEmails));
        $emails = array_filter($emails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));

        if (empty($emails)) return;

        try {
            Mail::to($emails)->send(new SapRequestNotification($sapRequest, $action));
        } catch (\Exception $e) {
            Log::error('Failed to send SAP Request notification: ' . $e->getMessage());
        }
    }

    public function processApprovedRequest(SapRequest $sapRequest): void
    {
        $company = $sapRequest->company;
        $companyCode = $company->code;

        $maxNumber = Ticket::withTrashed()
            ->where('ticket_key', 'LIKE', "{$companyCode}-%")
            ->get(['ticket_key'])
            ->map(function ($t) {
                if (preg_match('/-(\d+)$/', $t->ticket_key, $matches)) {
                    return (int) $matches[1];
                }
                return 0;
            })
            ->max();

        $nextNumber = ($maxNumber ?? 0) + 1;
        $ticketKey  = "{$companyCode}-{$nextNumber}";

        $requestType = $sapRequest->requestType;
        $requestTypeName = $requestType->name;
        $subject = $this->buildTicketTitle($requestTypeName, $sapRequest->company?->name);

        $formData = $sapRequest->form_data ?? [];
        $schema = $requestType->form_schema;

        $description = "🆔 SAP Request: #{$sapRequest->id}\n" .
            "📋 Type: {$requestTypeName}\n" .
            "👤 Requester: " . ($sapRequest->user ? $sapRequest->user->name : ($sapRequest->requester_name ?? 'N/A')) .
            " (" . ($sapRequest->user ? $sapRequest->user->email : ($sapRequest->requester_email ?? 'N/A')) . ")\n" .
            "🏢 Entity: {$sapRequest->company->name}\n\n" .
            "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
            "   📝 FORM DETAILS\n" .
            "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        foreach ($formData as $key => $value) {
            $label = ucwords(str_replace('_', ' ', $key));
            $displayValue = $this->getLabelFromSchema($schema, $key, $value, false);
            $description .= " • {$label}: {$displayValue}\n";
        }

        if ($sapRequest->items->isNotEmpty()) {
            $description .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $description .= "   📦 ITEMS\n";
            $description .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            foreach ($sapRequest->items as $index => $item) {
                $description .= "【 ITEM #" . ($index + 1) . " 】\n";
                foreach ($item->item_data as $key => $value) {
                    $label = ucwords(str_replace('_', ' ', $key));
                    $displayValue = $this->getLabelFromSchema($schema, $key, $value, true);
                    $description .= " • {$label}: {$displayValue}\n";
                }
                $description .= "────────────────────────────────────────\n";
            }
        }

        $ticket = Ticket::create([
            'ticket_key'   => $ticketKey,
            'title'        => $subject,
            'description'  => $description,
            'status'       => 'open',
            'priority'     => 'medium',
            'severity'     => 'minor',
            'reporter_id'  => $sapRequest->user_id,
            'sender_name'  => $sapRequest->user ? $sapRequest->user->name : $sapRequest->requester_name,
            'sender_email' => $sapRequest->user ? $sapRequest->user->email : $sapRequest->requester_email,
            'company_id'   => $sapRequest->company_id,
            'type'         => 'task',
            'created_at'   => now('Asia/Manila'),
        ]);

        $sapRequest->update(['ticket_id' => $ticket->id]);
    }

    private function getLabelFromSchema($schema, $key, $value, $isItem = false): string
    {
        if ($value === null) return '—';
        if (is_bool($value)) return $value ? 'Yes' : 'No';

        if (!$schema) {
            return is_array($value) ? implode(', ', $value) : (string)$value;
        }

        $fields = $isItem ? ($schema['items_columns'] ?? []) : ($schema['fields'] ?? []);
        $field = collect($fields)->firstWhere('key', $key);

        if ($field && isset($field['options']) && !empty($field['options'])) {
            $options = collect($field['options']);
            if (is_array($value)) {
                return $options->whereIn('value', $value)->pluck('label')->implode(', ');
            }
            $option = $options->firstWhere('value', $value);
            return $option ? $option['label'] : (string)$value;
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn($v) => is_array($v) ? json_encode($v) : (string)$v, $value));
        }

        return (string)$value;
    }

    private function buildTicketTitle(string $requestTypeName, ?string $companyName = null): string
    {
        $title = "SAP Request - {$requestTypeName}";

        if (filled($companyName)) {
            $title .= " for {$companyName}";
        }

        return Str::limit($title, 255, '...');
    }

    public function getEffectiveApprovalLevels(RequestType $requestType, array $formData): int
    {
        return count($this->resolveEffectiveApproverMatrix($requestType, $formData));
    }

    public function getApproverIdsForLevel(RequestType $requestType, array $formData, int $level): \Illuminate\Support\Collection
    {
        $entry = collect($this->resolveEffectiveApproverMatrix($requestType, $formData))
            ->firstWhere('level', $level);

        return collect($entry['user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
    }

    public function resolveEffectiveApproverMatrix(RequestType $requestType, array $formData): array
    {
        $baseMatrix = $this->normalizeApproverMatrix(
            $requestType->approver_matrix ?? [],
            (int) ($requestType->approval_levels ?? 0)
        );
        $dynamicMatrix = $this->getDynamicCheckboxApproverMatrix($requestType, $formData);
        $dynamicLevels = collect($dynamicMatrix)->pluck('level')->map(fn ($level) => (int) $level)->filter()->max() ?? 0;
        $totalLevels = max(count($baseMatrix), $dynamicLevels);

        if ($totalLevels <= 0) {
            return [];
        }

        return collect(range(1, $totalLevels))
            ->map(function (int $level) use ($baseMatrix, $dynamicMatrix) {
                $baseEntry = collect($baseMatrix)->firstWhere('level', $level);
                $dynamicEntry = collect($dynamicMatrix)->firstWhere('level', $level);
                $dynamicUserIds = collect($dynamicEntry['user_ids'] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'level' => $level,
                    'user_ids' => !empty($dynamicUserIds)
                        ? $dynamicUserIds
                        : ($baseEntry['user_ids'] ?? []),
                ];
            })
            ->values()
            ->all();
    }

    private function getDynamicCheckboxApproverMatrix(RequestType $requestType, array $formData): array
    {
        $levelMap = collect($requestType->form_schema['fields'] ?? [])
            ->filter(function (array $field) {
                return ($field['type'] ?? null) === 'checkbox_group'
                    && !empty($field['has_option_approvers'])
                    && !empty($field['key']);
            })
            ->flatMap(function (array $field) use ($formData) {
                $selectedValues = $formData[$field['key']] ?? [];
                if (!is_array($selectedValues) || empty($selectedValues)) {
                    return [];
                }

                return collect($field['options'] ?? [])
                    ->filter(fn (array $option) => in_array($option['value'] ?? null, $selectedValues, true))
                    ->map(function (array $option) {
                        $legacyApprovers = collect($option['approver_user_ids'] ?? [])
                            ->map(fn ($id) => (int) $id)
                            ->filter()
                            ->unique()
                            ->values()
                            ->all();

                        if (!empty($option['approval_matrix']) && is_array($option['approval_matrix'])) {
                            return $this->normalizeApproverMatrix(
                                $option['approval_matrix'],
                                (int) ($option['approval_levels'] ?? count($option['approval_matrix']))
                            );
                        }

                        if (!empty($legacyApprovers)) {
                            return [[
                                'level' => 1,
                                'user_ids' => $legacyApprovers,
                            ]];
                        }

                        return [];
                    });
            })
            ->flatten(1)
            ->reduce(function (array $carry, array $entry) {
                $level = (int) ($entry['level'] ?? 0);
                if ($level <= 0) {
                    return $carry;
                }

                $carry[$level] = array_values(array_unique(array_merge(
                    $carry[$level] ?? [],
                    collect($entry['user_ids'] ?? [])
                        ->map(fn ($id) => (int) $id)
                        ->filter()
                        ->values()
                        ->all()
                )));

                return $carry;
            }, []);

        return collect($levelMap)
            ->map(fn (array $userIds, int $level) => [
                'level' => (int) $level,
                'user_ids' => array_values(array_unique(array_map('intval', $userIds))),
            ])
            ->sortBy('level')
            ->values()
            ->all();
    }

    private function normalizeApproverMatrix(array $matrix, int $levels): array
    {
        if ($levels <= 0) {
            return [];
        }

        return collect(range(1, $levels))
            ->map(function (int $level) use ($matrix) {
                $match = collect($matrix)->firstWhere('level', $level);

                return [
                    'level' => $level,
                    'user_ids' => collect($match['user_ids'] ?? [])
                        ->map(fn ($id) => (int) $id)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }
}
