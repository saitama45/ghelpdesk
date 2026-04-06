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

        $requestTypeName = $sapRequest->requestType->name;
        $subject = "SAP Request - {$requestTypeName}";

        $formData = $sapRequest->form_data ?? [];
        $description = "🆔 SAP Request: #{$sapRequest->id}\n" .
            "📋 Type: {$requestTypeName}\n" .
            "👤 Requester: " . ($sapRequest->user ? $sapRequest->user->name : ($sapRequest->requester_name ?? 'N/A')) .
            " (" . ($sapRequest->user ? $sapRequest->user->email : ($sapRequest->requester_email ?? 'N/A')) . ")\n" .
            "🏢 Entity: {$sapRequest->company->name}\n\n" .
            "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
            "   📝 FORM DETAILS\n" .
            "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        foreach ($formData as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $label = ucwords(str_replace('_', ' ', $key));
            $description .= " • {$label}: {$value}\n";
        }

        if ($sapRequest->items->isNotEmpty()) {
            $description .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $description .= "   📦 ITEMS\n";
            $description .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            foreach ($sapRequest->items as $index => $item) {
                $description .= "【 ITEM #" . ($index + 1) . " 】\n";
                foreach ($item->item_data as $key => $value) {
                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }
                    $label = ucwords(str_replace('_', ' ', $key));
                    $description .= " • {$label}: {$value}\n";
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
}
