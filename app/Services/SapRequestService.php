<?php

namespace App\Services;

use App\Models\SapRequest;
use App\Models\RequestType;
use App\Models\Ticket;
use App\Mail\SapRequestNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SapRequestService
{
    public function createRequest(array $data, ?int $userId = null): SapRequest
    {
        return DB::transaction(function () use ($data, $userId) {
            $requestType = RequestType::findOrFail($data['request_type_id']);

            $sapRequest = SapRequest::create([
                'company_id'            => $data['company_id'],
                'request_type_id'       => $data['request_type_id'],
                'user_id'               => $userId,
                'requester_name'        => $data['requester_name'] ?? null,
                'requester_email'       => $data['requester_email'] ?? null,
                'status'                => $requestType->approval_levels == 0 ? 'Approved' : 'Open',
                'current_approval_level'=> $requestType->approval_levels == 0 ? 0 : 1,
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
        $subject = "SAP Request - {$requestTypeName}";

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
}
