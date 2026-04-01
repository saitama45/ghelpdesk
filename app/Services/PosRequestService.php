<?php

namespace App\Services;

use App\Models\PosRequest;
use App\Models\RequestType;
use App\Models\Ticket;
use App\Mail\PosRequestNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PosRequestService
{
    /**
     * Create a new POS Request and process it if approved.
     *
     * @param array $data
     * @param int|null $userId Null for public requests
     * @return PosRequest
     */
    public function createRequest(array $data, ?int $userId = null): PosRequest
    {
        return DB::transaction(function () use ($data, $userId) {
            $requestType = RequestType::findOrFail($data['request_type_id']);
            
            $posRequest = PosRequest::create([
                'company_id' => $data['company_id'],
                'request_type_id' => $data['request_type_id'],
                'user_id' => $userId,
                'requester_name' => isset($data['requester_name']) ? $data['requester_name'] : null,
                'requester_email' => isset($data['requester_email']) ? $data['requester_email'] : null,
                'launch_date' => $data['launch_date'],
                'stores_covered' => $data['stores_covered'],
                'status' => $requestType->approval_levels == 0 ? 'Approved' : 'Open',
                'current_approval_level' => $requestType->approval_levels == 0 ? 0 : 1,
            ]);

            foreach ($data['details'] as $detail) {
                $posRequest->details()->create($detail);
            }

            if ($posRequest->status === 'Approved') {
                $this->processApprovedRequest($posRequest);
            }

            // Send notification to CC emails
            $this->notifyCcEmails($posRequest, 'created');

            return $posRequest;
        });
    }

    /**
     * Update an existing POS Request.
     */
    public function updateRequest(PosRequest $posRequest, array $data): PosRequest
    {
        return DB::transaction(function () use ($posRequest, $data) {
            $posRequest->update([
                'company_id' => $data['company_id'],
                'request_type_id' => $data['request_type_id'],
                'launch_date' => $data['launch_date'],
                'stores_covered' => $data['stores_covered'],
            ]);

            // Sync Details: simplest way is delete and recreate for this type of record
            $posRequest->details()->delete();
            foreach ($data['details'] as $detail) {
                $posRequest->details()->create($detail);
            }

            // Send notification to CC emails
            $this->notifyCcEmails($posRequest, 'updated');

            return $posRequest;
        });
    }

    /**
     * Notify CC emails about POS Request action.
     */
    protected function notifyCcEmails(PosRequest $posRequest, string $action)
    {
        $ccEmails = $posRequest->requestType->cc_emails;
        if (!$ccEmails) return;

        $emails = array_map('trim', explode("\n", $ccEmails));
        $emails = array_filter($emails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));

        if (empty($emails)) return;

        try {
            Mail::to($emails)->send(new PosRequestNotification($posRequest, $action));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to send POS Request notification: " . $e->getMessage());
        }
    }

    /**
     * Process an approved POS Request (e.g., create tickets).
     */
    public function processApprovedRequest(PosRequest $posRequest)
    {
        // 1. Generate Ticket Key (Format: COMPANYCODE-NUMBER)
        $company = $posRequest->company;
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
        $ticketKey = "{$companyCode}-{$nextNumber}";

        // 2. Build Detailed Description from Line Items
        $storeCodes = in_array('all', $posRequest->stores_covered) 
            ? 'All Stores' 
            : implode(', ', $posRequest->stores_covered);

        $subject = "POS Request - {$posRequest->requestType->name} to {$storeCodes}";
        $detailsContent = "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $detailsContent .= "   📋 LINE ITEM DETAILS\n";
        $detailsContent .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        foreach ($posRequest->details as $index => $detail) {
            $num = $index + 1;
            $mealStatus = ($detail->mgr_meal === 'Yes' || $detail->mgr_meal === true || $detail->mgr_meal == 1) ? 'YES' : 'NO';

            $detailsContent .= "【 PRODUCT #{$num} 】\n";
            $detailsContent .= " • Name: {$detail->product_name}\n";
            $detailsContent .= " • POS Alias: {$detail->pos_name}\n";
            $detailsContent .= " • Pricing: {$detail->price_type} (₱" . number_format($detail->price_amount, 2) . ")\n";
            $detailsContent .= " • Classification: " . ($detail->category ?? 'N/A') . " ➔ " . ($detail->sub_category ?? 'N/A') . "\n";
            $detailsContent .= " • SKU/Code: " . ($detail->item_code ?? 'N/A') . " | Printer: " . ($detail->printer ?? 'N/A') . "\n";
            $detailsContent .= " • Validity: " . ($detail->validity_date ? $detail->validity_date->format('Y-m-d') : 'ASAP') . "\n";

            if ($detail->remarks_mechanics) {
                $detailsContent .= " • Remarks: {$detail->remarks_mechanics}\n";
            }

            $detailsContent .= " • Technicals: SC: {$detail->sc} | Tax: {$detail->local_tax}% | Mgr's Meal: {$mealStatus}\n";
            $detailsContent .= "────────────────────────────────────────\n";
        }

        $fullDescription = "🆔 POS Request: #{$posRequest->id}\n" .
                          "👤 Requester: " . ($posRequest->user ? $posRequest->user->name : ($posRequest->requester_name ?? 'N/A')) . " (" . ($posRequest->user ? $posRequest->user->email : ($posRequest->requester_email ?? 'N/A')) . ")\n" .
                          "📅 Launch Date: {$posRequest->launch_date->format('Y-m-d')}\n" .
                          "🏪 Stores: {$storeCodes}" .
                          $detailsContent;

        // 3. Create Ticket with Key and Full Details
        $ticket = Ticket::create([
            'ticket_key' => $ticketKey,
            'title' => $subject,
            'description' => $fullDescription,
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'reporter_id' => $posRequest->user_id,
            'sender_name' => $posRequest->user ? $posRequest->user->name : $posRequest->requester_name,
            'sender_email' => $posRequest->user ? $posRequest->user->email : $posRequest->requester_email,
            'company_id' => $posRequest->company_id,
            'type' => 'feature',
            'created_at' => now('Asia/Manila'),
        ]);

        $posRequest->update(['ticket_id' => $ticket->id]);

        // 4. Notify CC Emails
        $ccEmails = $posRequest->requestType->cc_emails;
        if ($ccEmails) {
            $emails = array_map('trim', explode("\n", $ccEmails));
            $emails = array_filter($emails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
            
            if (!empty($emails)) {
                // Logic to send email notifications would go here
            }
        }
    }
}
