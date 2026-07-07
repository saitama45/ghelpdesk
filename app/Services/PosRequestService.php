<?php

namespace App\Services;

use App\Models\PosRequest;
use App\Models\RequestType;
use App\Models\Ticket;
use App\Models\User;
use App\Mail\PosRequestNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
        $data = $this->storeFileUploads($data);

        $posRequest = DB::transaction(function () use ($data, $userId) {
            $requestType = RequestType::findOrFail($data['request_type_id']);
            $hasSchema = !empty($requestType->form_schema['fields'] ?? []) || !empty($requestType->form_schema['has_items']);

            // For schema-driven types, merge schema fields + items into form_data JSON
            $formData = null;
            if ($hasSchema) {
                $formData = $data['form_data'] ?? [];
                if (!empty($data['details'])) {
                    $formData['items'] = $data['details'];
                }
            }

            $posRequest = PosRequest::create([
                'company_id' => $data['company_id'],
                'request_type_id' => $data['request_type_id'],
                'user_id' => $userId,
                'requester_name' => $data['requester_name'] ?? null,
                'requester_email' => $data['requester_email'] ?? null,
                'launch_date' => $data['launch_date'],
                'stores_covered' => $data['stores_covered'],
                'form_data' => $formData,
                'status' => $requestType->approval_levels == 0 ? 'Approved' : 'Open',
                'current_approval_level' => $requestType->approval_levels == 0 ? 0 : 1,
            ]);

            // Only insert into pos_request_details for non-schema (hard-coded fallback) types
            if (!$hasSchema && !empty($data['details'])) {
                foreach ($data['details'] as $detail) {
                    $posRequest->details()->create($detail);
                }
            }

            return $posRequest;
        });

        // Auto-generate the ticket for zero-approver (auto-approved) requests AFTER
        // the request itself is committed. Keeping it outside the transaction means a
        // rare ticketing failure leaves a recoverable approved request (generate it
        // later via the manual "Generate Ticket" action) instead of a hard error or,
        // worse, a silent rollback.
        if ($posRequest->status === 'Approved') {
            try {
                $this->processApprovedRequest($posRequest);
            } catch (\Throwable $e) {
                Log::error("POS Request #{$posRequest->id}: automatic ticket generation failed — {$e->getMessage()}");
            }
        }

        // Refresh relationships to ensure mailable has access to company and requestType
        $posRequest->load(['company', 'requestType', 'user']);

        // Send notification to CC emails
        $this->notifyCcEmails($posRequest, 'created');

        // Send confirmation to requester
        $this->notifyRequester($posRequest, 'created');

        // Notify the approvers assigned to the current active approval level.
        $this->notifyCurrentApprovers($posRequest);

        return $posRequest;
    }

    /**
     * Update an existing POS Request.
     */
    public function updateRequest(PosRequest $posRequest, array $data): PosRequest
    {
        $data = $this->storeFileUploads($data);

        $posRequest = DB::transaction(function () use ($posRequest, $data) {
            $requestType = RequestType::findOrFail($data['request_type_id']);
            $hasSchema = !empty($requestType->form_schema['fields'] ?? []) || !empty($requestType->form_schema['has_items']);

            $formData = null;
            if ($hasSchema) {
                $formData = $data['form_data'] ?? [];
                if (!empty($data['details'])) {
                    $formData['items'] = $data['details'];
                }
            }

            $posRequest->update([
                'company_id' => $data['company_id'],
                'request_type_id' => $data['request_type_id'],
                'launch_date' => $data['launch_date'],
                'stores_covered' => $data['stores_covered'],
                'form_data' => $formData,
            ]);

            // Only sync pos_request_details for non-schema (hard-coded fallback) types
            $posRequest->details()->delete();
            if (!$hasSchema && !empty($data['details'])) {
                foreach ($data['details'] as $detail) {
                    $posRequest->details()->create($detail);
                }
            }

            return $posRequest;
        });

        // Refresh relationships
        $posRequest->load(['company', 'requestType', 'user']);

        // Send notification to CC emails
        $this->notifyCcEmails($posRequest, 'updated');
        $this->notifyRequester($posRequest, 'updated');

        return $posRequest;
    }

    /**
     * Notify requester about POS Request action.
     */
    protected function notifyRequester(PosRequest $posRequest, string $action)
    {
        $requesterEmail = $posRequest->user ? $posRequest->user->email : $posRequest->requester_email;
        
        if (!$requesterEmail || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) return;

        try {
            Mail::to($requesterEmail)->send(new PosRequestNotification($posRequest, $action, true));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to send POS Request confirmation to requester: " . $e->getMessage());
        }
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

    public function notifyCurrentApprovers(PosRequest $posRequest): void
    {
        $posRequest->loadMissing(['company', 'requestType', 'user', 'details']);

        $level = (int) $posRequest->current_approval_level;
        if ($level <= 0) {
            return;
        }

        $matrixEntry = collect($posRequest->requestType->approver_matrix ?? [])
            ->firstWhere('level', $level);
        $approverIds = $matrixEntry['user_ids'] ?? [];

        $normalizedApproverIds = collect($approverIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        // In-app bell for every active approver at this level.
        $bellRecipientIds = User::active()->whereIn('id', $normalizedApproverIds)->pluck('id')->all();
        if (!empty($bellRecipientIds)) {
            app(\App\Services\NotificationService::class)->notifyApproval(
                $bellRecipientIds,
                auth()->id(),
                'pending',
                'Approval needed: ' . ($posRequest->requestType->name ?? 'POS Request'),
                "POS Request #{$posRequest->id} is awaiting your approval (Stage {$level}).",
                route('pos-requests.show', $posRequest->id, false),
                'pos_request:' . $posRequest->id,
                'warning'
            );
        }

        $emails = User::active()
            ->whereIn('id', $normalizedApproverIds)
            ->pluck('email')
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique(fn ($email) => strtolower($email))
            ->values()
            ->all();

        if (empty($emails)) {
            return;
        }

        try {
            Mail::to($emails)->send(new PosRequestNotification(
                $posRequest,
                'approval_requested',
                false,
                true,
                $level
            ));
        } catch (\Exception $e) {
            Log::error('Failed to send POS Request approver notification: ' . $e->getMessage());
        }
    }

    /**
     * Process an approved POS Request (e.g., create tickets).
     *
     * Idempotent: if the request already has a live ticket, that ticket is
     * returned and no second one is created. This lets the flow be retried
     * (e.g. via the manual "Generate Ticket" recovery action) safely.
     */
    public function processApprovedRequest(PosRequest $posRequest): ?Ticket
    {
        // Idempotency guard — never create a duplicate ticket for the same request.
        if ($posRequest->ticket_id) {
            $existing = Ticket::withTrashed()
                ->withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
                ->find($posRequest->ticket_id);
            if ($existing) {
                return $existing;
            }
            // Dangling ticket_id (ticket hard-deleted) — fall through and regenerate.
        }

        // Build Detailed Description from Line Items
        $storeCodes = in_array('all', $posRequest->stores_covered) 
            ? 'All Stores' 
            : implode(', ', $posRequest->stores_covered);

        $requestType = $posRequest->requestType;
        $schema = $requestType->form_schema;

        $subject = $this->buildTicketTitle($requestType->name, $posRequest->stores_covered);
        $detailsContent = "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $detailsContent .= "   📋 LINE ITEM DETAILS\n";
        $detailsContent .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        $schemaItemCols = $schema['items_columns'] ?? [];
        $schemaItems    = $posRequest->form_data['items'] ?? [];
        $useSchema      = !empty($schema['has_items']) && !empty($schemaItemCols) && count($schemaItems) > 0;

        if ($useSchema) {
            foreach ($schemaItems as $index => $item) {
                $num = $index + 1;
                $detailsContent .= "【 ITEM #{$num} 】\n";
                foreach ($schemaItemCols as $col) {
                    $val = $item[$col['key']] ?? null;
                    $label = $this->getLabelFromSchema($schema, $col['key'], $val);
                    $detailsContent .= " • {$col['label']}: {$label}\n";
                }
                $detailsContent .= "────────────────────────────────────────\n";
            }
        } else {
            foreach ($posRequest->details as $index => $detail) {
                $num = $index + 1;
                $mealStatus = ($detail->mgr_meal === 'Yes' || $detail->mgr_meal === true || $detail->mgr_meal == 1) ? 'YES' : 'NO';

                $priceTypeLabel = $this->getLabelFromSchema($schema, 'price_type', $detail->price_type);
                $categoryLabel = $this->getLabelFromSchema($schema, 'category', $detail->category);

                $detailsContent .= "【 PRODUCT #{$num} 】\n";
                $detailsContent .= " • Name: {$detail->product_name}\n";
                $detailsContent .= " • POS Alias: {$detail->pos_name}\n";
                $detailsContent .= " • Pricing: {$priceTypeLabel} (₱" . number_format($detail->price_amount, 2) . ")\n";
                $detailsContent .= " • Classification: {$categoryLabel} ➔ " . ($detail->sub_category ?? 'N/A') . "\n";
                $detailsContent .= " • SKU/Code: " . ($detail->item_code ?? 'N/A') . " | Printer: " . ($detail->printer ?? 'N/A') . "\n";
                $detailsContent .= " • Validity: " . ($detail->validity_date ? $detail->validity_date->format('Y-m-d') : 'ASAP') . "\n";

                if ($detail->remarks_mechanics) {
                    $detailsContent .= " • Remarks: {$detail->remarks_mechanics}\n";
                }

                $detailsContent .= " • Technicals: SC: {$detail->sc} | Tax: {$detail->local_tax}% | Mgr's Meal: {$mealStatus}\n";
                $detailsContent .= "────────────────────────────────────────\n";
            }
        }

        $fullDescription = "🆔 POS Request: #{$posRequest->id}\n" .
                          "👤 Requester: " . ($posRequest->user ? $posRequest->user->name : ($posRequest->requester_name ?? 'N/A')) . " (" . ($posRequest->user ? $posRequest->user->email : ($posRequest->requester_email ?? 'N/A')) . ")\n" .
                          "📅 Launch Date: {$posRequest->launch_date->format('Y-m-d')}\n" .
                          "🏪 Stores: {$storeCodes}";

        // Add Approver Data to description
        $approverData = $posRequest->approver_data ?? [];
        if (!empty($approverData)) {
            $fullDescription .= "\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $fullDescription .= "   ✅ APPROVER DETAILS\n";
            $fullDescription .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            foreach ($approverData as $key => $value) {
                $label = ucwords(str_replace('_', ' ', $key));
                $displayValue = $this->getLabelFromSchema($schema, $key, $value);
                $fullDescription .= " • {$label}: {$displayValue}\n";
            }
        }

        $fullDescription .= $detailsContent;

        // Create the ticket. The ticket_key is assigned by TicketObserver (single
        // source of truth) and the insert is retried on a unique-key collision so
        // concurrent ticket creation can never leave the request without a ticket.
        $ticket = $this->createTicketWithRetry([
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

        return $ticket;
    }

    /**
     * Create a Ticket, retrying when the auto-generated ticket_key collides with a
     * concurrently-created ticket (UNIQUE violation on tickets.ticket_key). Each
     * retry re-runs TicketObserver::creating, which recomputes the next key.
     */
    private function createTicketWithRetry(array $attributes, int $attempts = 5): Ticket
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return Ticket::create($attributes);
            } catch (\Illuminate\Database\QueryException $e) {
                $isUniqueViolation = ($e->getCode() === '23000')
                    || stripos($e->getMessage(), 'ticket_key') !== false
                    || stripos($e->getMessage(), 'duplicate') !== false
                    || stripos($e->getMessage(), 'unique') !== false;

                if (!$isUniqueViolation || $attempt >= $attempts) {
                    throw $e;
                }

                // Brief jittered backoff before recomputing the key and retrying.
                usleep(random_int(10_000, 60_000));
            }
        }
    }

    /**
     * Detect UploadedFile instances inside $data['form_data'] and $data['details'],
     * store them to disk, and replace them with their public storage paths.
     */
    private function storeFileUploads(array $data): array
    {
        $requestType = RequestType::find($data['request_type_id'] ?? null);
        if (!$requestType) return $data;

        $schema = $requestType->form_schema ?? [];

        // Regular form_data fields
        foreach ($schema['fields'] ?? [] as $field) {
            if (($field['type'] ?? '') === 'file') {
                $key = $field['key'];
                $val = $data['form_data'][$key] ?? null;
                if (!$val) continue;

                if (is_array($val)) {
                    $paths = [];
                    foreach ($val as $f) {
                        if ($f instanceof \Illuminate\Http\UploadedFile) {
                            $paths[] = [
                                'path' => $f->store('pos-requests/attachments', 'public'),
                                'name' => $f->getClientOriginalName(),
                            ];
                        } else if (is_string($f) || is_array($f)) {
                            $paths[] = $f;
                        }
                    }
                    $data['form_data'][$key] = $paths;
                } elseif ($val instanceof \Illuminate\Http\UploadedFile) {
                    $data['form_data'][$key] = [
                        'path' => $val->store('pos-requests/attachments', 'public'),
                        'name' => $val->getClientOriginalName(),
                    ];
                }
            }
        }

        // Items line-item columns
        if (isset($data['details']) && is_array($data['details'])) {
            foreach ($data['details'] as $idx => $item) {
                foreach ($schema['items_columns'] ?? [] as $col) {
                    if (($col['type'] ?? '') === 'file') {
                        $key = $col['key'];
                        $val = $item[$key] ?? null;
                        if (!$val) continue;

                        if (is_array($val)) {
                            $paths = [];
                            foreach ($val as $f) {
                                if ($f instanceof \Illuminate\Http\UploadedFile) {
                                    $paths[] = [
                                        'path' => $f->store('pos-requests/attachments', 'public'),
                                        'name' => $f->getClientOriginalName(),
                                    ];
                                } else if (is_string($f) || is_array($f)) {
                                    $paths[] = $f;
                                }
                            }
                            $data['details'][$idx][$key] = $paths;
                        } elseif ($val instanceof \Illuminate\Http\UploadedFile) {
                            $data['details'][$idx][$key] = [
                                'path' => $val->store('pos-requests/attachments', 'public'),
                                'name' => $val->getClientOriginalName(),
                            ];
                        }
                    }
                }
            }
        }

        return $data;
    }

    private function getLabelFromSchema($schema, $key, $value): string
    {
        if ($value === null) return '—';
        if (is_bool($value)) return $value ? 'Yes' : 'No';

        // Check if it's a file object or array of file objects
        if (is_array($value)) {
            if (isset($value['path']) && isset($value['name'])) {
                $url = route('attachments.download', ['path' => $value['path'], 'name' => $value['name']]);
                return "[{$value['name']}]({$url})";
            }
            
            // Multiple files or checkbox group
            $names = [];
            $isFileArray = false;
            foreach ($value as $val) {
                if (is_array($val) && isset($val['name'])) {
                    $isFileArray = true;
                    if (isset($val['path'])) {
                        $url = route('attachments.download', ['path' => $val['path'], 'name' => $val['name']]);
                        $names[] = "[{$val['name']}]({$url})";
                    } else {
                        $names[] = $val['name'];
                    }
                } else {
                    $names[] = (string)$val;
                }
            }

            // If it's a checkbox group, we might want to map the values to labels
            if ($schema && !$isFileArray) {
                $fields = $schema['items_columns'] ?? [];
                $field = collect($fields)->firstWhere('key', $key);
                if ($field && isset($field['options'])) {
                    $options = collect($field['options']);
                    return $options->whereIn('value', $value)->pluck('label')->implode(', ');
                }
            }

            return implode(', ', $names);
        }

        if (!$schema) return (string)$value;

        // For POS requests, details are in items_columns
        $fields = $schema['items_columns'] ?? [];
        $field = collect($fields)->firstWhere('key', $key);

        if ($field && isset($field['options']) && !empty($field['options'])) {
            $options = collect($field['options']);
            $option = $options->firstWhere('value', $value);
            return $option ? $option['label'] : (string)$value;
        }

        return (string)$value;
    }

    private function buildTicketTitle(string $requestTypeName, array $storesCovered): string
    {
        if (in_array('all', $storesCovered, true)) {
            return Str::limit("POS Request - {$requestTypeName} to All Stores", 255, '...');
        }

        $stores = array_values(array_filter($storesCovered, fn ($store) => filled($store)));
        $storeCount = count($stores);

        if ($storeCount === 0) {
            return Str::limit("POS Request - {$requestTypeName}", 255, '...');
        }

        $previewStores = array_slice($stores, 0, 3);
        $storeSummary = implode(', ', $previewStores);

        if ($storeCount > 3) {
            $storeSummary .= ' +' . ($storeCount - 3) . ' more';
        }

        return Str::limit("POS Request - {$requestTypeName} to {$storeSummary}", 255, '...');
    }
}
