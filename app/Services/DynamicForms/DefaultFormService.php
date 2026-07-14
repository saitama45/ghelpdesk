<?php

namespace App\Services\DynamicForms;

use App\Models\FormDefinition;
use App\Models\FormRecord;
use App\Models\FormRecordApproval;
use App\Models\RequestType;
use App\Models\Ticket;
use App\Models\User;
use App\Mail\DynamicFormApprovalReminder;
use App\Services\DynamicForms\Contracts\FormServiceContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class DefaultFormService implements FormServiceContract
{
    public function store(Request $request, FormDefinition $formDefinition): FormRecord
    {
        $request->validate([
            'request_type_id' => 'nullable|exists:request_types,id',
            'form_data' => 'nullable|array',
            'items' => 'nullable|array',
        ]);

        $requestType = null;
        if ($request->filled('request_type_id')) {
            $requestType = RequestType::find($request->request_type_id);
        }

        $data = $request->only(['form_data', 'items']);
        $data = $this->storeFileUploads($formDefinition, $data);
        
        $saveData = array_merge($data['form_data'] ?? [], [
            'items' => $data['items'] ?? []
        ]);

        $approvalLevels = $requestType
            ? $this->getEffectiveApprovalLevels($requestType, $data['form_data'] ?? [])
            : (int) $formDefinition->approval_levels;

        if ($formDefinition->workflow_type === 'checklist') {
            $formSchema = $formDefinition->form_schema ?? [];
            $dynamicTasks = $this->resolveDynamicChecklistTasks($formSchema, $data['form_data'] ?? []);
            if ($dynamicTasks !== null) {
                $approvalLevels = count($dynamicTasks);
                $saveData['_checklist_tasks'] = $dynamicTasks;
            }
        }

        $status = $approvalLevels > 0 ? 'Open' : 'Approved';
        $currentLevel = $approvalLevels > 0 ? 1 : 0;

        $record = FormRecord::create([
            'form_definition_id' => $formDefinition->id,
            'request_type_id' => $request->request_type_id,
            'data' => $saveData,
            'status' => $status,
            'current_approval_level' => $currentLevel,
            'created_by' => $request->attributes->get('created_by', Auth::id()),
        ]);

        if ($record->status === 'Approved') {
            $this->processApprovedRequest($formDefinition, $record);
        }

        return $record;
    }

    public function update(Request $request, FormDefinition $formDefinition, FormRecord $record): FormRecord
    {
        $data = $request->only(['form_data', 'items']);
        $data = $this->storeFileUploads($formDefinition, $data);
        
        $saveData = array_merge($data['form_data'] ?? [], [
            'items' => $data['items'] ?? []
        ]);

        $record->update([
            'data' => $saveData,
            'updated_by' => Auth::id(),
        ]);

        return $record;
    }

    public function approve(Request $request, FormDefinition $formDefinition, FormRecord $record): void
    {
        $request->validate([
            'remarks' => 'nullable|string',
            'approver_data' => 'nullable|array',
            'force_level' => 'nullable|integer',
        ]);

        DB::transaction(function () use ($formDefinition, $record, $request) {
            if (in_array($record->status, ['Approved', 'Rejected', 'Cancelled'], true) || $record->ticket_id) {
                return;
            }

            $isChecklist = $formDefinition->workflow_type === 'checklist';
            $levelToApprove = $request->force_level ?? $record->current_approval_level;
            
            $totalLevels = $this->getTotalApprovalLevels($formDefinition, $record);

            FormRecordApproval::create([
                'form_record_id' => $record->id,
                'user_id' => Auth::id(),
                'level' => $levelToApprove,
                'remarks' => $request->remarks,
                'approver_data' => $request->approver_data,
            ]);

            if ($isChecklist) {
                $approvedLevels = $record->approvals()->pluck('level')->push($levelToApprove)->unique();
                
                if ($approvedLevels->count() >= $totalLevels) {
                    $record->update([
                        'status' => 'Approved',
                        'current_approval_level' => 0,
                    ]);
                    $this->processApprovedRequest($formDefinition, $record);
                }
            } else {
                $nextLevel = $levelToApprove + 1;
                if ($nextLevel > $totalLevels) {
                    $record->update([
                        'status' => 'Approved',
                        'current_approval_level' => 0,
                    ]);
                    $this->processApprovedRequest($formDefinition, $record);
                } else {
                    $record->update([
                        'status' => 'Approved Level ' . $levelToApprove,
                        'current_approval_level' => $nextLevel,
                    ]);
                }
            }
        });

        $record->refresh();
        $this->notifyCurrentApprovers($formDefinition, $record);

        if ($record->status === 'Approved') {
            $this->notifyRequesterDecision($formDefinition, $record, 'approved');
        }
    }

    public function reject(Request $request, FormDefinition $formDefinition, FormRecord $record): void
    {
        $request->validate([
            'remarks' => 'required|string',
        ]);

        $record->update([
            'status' => 'Rejected',
            'current_approval_level' => 0,
        ]);

        FormRecordApproval::create([
            'form_record_id' => $record->id,
            'user_id' => Auth::id(),
            'level' => $record->current_approval_level,
            'remarks' => $request->remarks,
            'status' => 'Rejected',
        ]);

        $this->notifyRequesterDecision($formDefinition, $record, 'rejected');
    }

    /**
     * Bell the requester (form creator) with the final approval decision.
     */
    private function notifyRequesterDecision(FormDefinition $formDefinition, FormRecord $record, string $decision): void
    {
        if (!$record->created_by) {
            return;
        }

        app(\App\Services\NotificationService::class)->notifyApproval(
            [$record->created_by],
            Auth::id(),
            $decision,
            ($decision === 'approved' ? 'Request approved: ' : 'Request rejected: ') . $formDefinition->name,
            "Your request #{$record->id} has been {$decision}.",
            route('dynamic-form.show', ['slug' => $formDefinition->slug, 'id' => $record->id], false),
            'form_record:' . $record->id,
            $decision === 'approved' ? 'success' : 'warning'
        );
    }

    private function resolveDynamicChecklistTasks(array $formSchema, array $formData): ?array
    {
        $fields = $formSchema['fields'] ?? [];
        $sourceField = null;
        foreach ($fields as $field) {
            if (!empty($field['checklist_source'])) {
                $sourceField = $field;
                break;
            }
        }
        if (!$sourceField) return null;

        $fieldKey = $sourceField['key'];
        $assignees = $sourceField['checklist_assignees'] ?? [];
        $selectedValues = $formData[$fieldKey] ?? [];
        if (!is_array($selectedValues)) {
            $selectedValues = ($selectedValues !== null && $selectedValues !== '') ? [$selectedValues] : [];
        }

        $optionMap = [];
        foreach ($sourceField['options'] ?? [] as $opt) {
            $optionMap[$opt['value']] = $opt['label'];
        }

        $tasks = [];
        $level = 1;
        foreach ($selectedValues as $val) {
            $tasks[] = [
                'level'     => $level++,
                'name'      => $optionMap[$val] ?? $val,
                'assignees' => $assignees,
            ];
        }
        return $tasks ?: null;
    }

    private function storeFileUploads(FormDefinition $form, array $data): array
    {
        $schema = $form->form_schema ?? [];
        $fields = $schema['fields'] ?? [];
        
        foreach ($fields as $field) {
            if ($field['type'] === 'file' && isset($data['form_data'][$field['key']])) {
                $files = $data['form_data'][$field['key']];
                if (!is_array($files)) $files = [$files];
                
                $storedFiles = [];
                foreach ($files as $file) {
                    if ($file instanceof \Illuminate\Http\UploadedFile) {
                        $path = $file->store('dynamic-forms/' . $form->slug, 'public');
                        $storedFiles[] = [
                            'name' => $file->getClientOriginalName(),
                            'path' => $path,
                            'url' => Storage::url($path),
                            'mime' => $file->getMimeType(),
                            'size' => $file->getSize(),
                        ];
                    } else {
                        $storedFiles[] = $file;
                    }
                }
                $data['form_data'][$field['key']] = $storedFiles;
            }
        }
        return $data;
    }

    public function notifyCurrentApprovers(FormDefinition $formDefinition, FormRecord $record, ?int $level = null): void
    {
        $targetLevel = $level ?: (int) $record->current_approval_level;
        if ($targetLevel <= 0 || $record->status === 'Approved' || $record->status === 'Rejected') {
            return;
        }

        $approverIds = collect();

        if ($formDefinition->workflow_type === 'checklist') {
            $tasks = $record->data['_checklist_tasks'] ?? [];
            foreach ($tasks as $task) {
                if ((int) ($task['level'] ?? 0) === (int) $targetLevel) {
                    $assignees = $task['assignees'] ?? [];
                    $approverIds = $approverIds->merge($assignees);
                }
            }

            if ($approverIds->isEmpty()) {
                $matrix = $record->requestType
                    ? $this->resolveEffectiveApproverMatrix($record->requestType, $record->data ?? [])
                    : $this->normalizeApproverMatrix($formDefinition->approver_matrix ?? [], (int) $formDefinition->approval_levels);
                $levelData = collect($matrix)->firstWhere('level', (int) $targetLevel);
                $approverIds = $approverIds->merge($levelData['user_ids'] ?? []);
            }
        } else {
            $matrix = $record->requestType
                ? $this->resolveEffectiveApproverMatrix($record->requestType, $record->data ?? [])
                : $this->normalizeApproverMatrix($formDefinition->approver_matrix ?? [], (int) $formDefinition->approval_levels);
            $levelData = collect($matrix)->firstWhere('level', (int) $targetLevel);
            $approverIds = $approverIds->merge($levelData['user_ids'] ?? []);
        }

        $normalizedApproverIds = $approverIds->map(fn ($id) => (int) $id)->filter()->unique()->values();

        $approvers = User::active()
            ->whereIn('id', $normalizedApproverIds)
            ->get(['id', 'name', 'email'])
            ->filter(fn (User $user) => filter_var($user->email, FILTER_VALIDATE_EMAIL))
            ->unique(fn (User $user) => strtolower($user->email));

        // In-app bell for every active approver at this level (regardless of email).
        $bellRecipientIds = User::active()->whereIn('id', $normalizedApproverIds)->pluck('id')->all();
        if (!empty($bellRecipientIds)) {
            app(\App\Services\NotificationService::class)->notifyApproval(
                $bellRecipientIds,
                Auth::id(),
                'pending',
                'Approval needed: ' . $formDefinition->name,
                "Request #{$record->id} is awaiting your approval (Level {$targetLevel}).",
                route('dynamic-form.show', ['slug' => $formDefinition->slug, 'id' => $record->id], false),
                'form_record:' . $record->id,
                'warning'
            );
        }

        foreach ($approvers as $approver) {
            try {
                $path = route('dynamic-form.show', ['slug' => $formDefinition->slug, 'id' => $record->id], false);
                $viewUrl = request()->getSchemeAndHttpHost() . $path;
                Mail::to($approver->email)->send(new DynamicFormApprovalReminder($formDefinition, $record, $approver->name, $viewUrl));
            } catch (\Throwable $e) {
                Log::error('Failed to send dynamic form approver notification: ' . $e->getMessage(), [
                    'form_id' => $formDefinition->id,
                    'record_id' => $record->id,
                    'approver_id' => $approver->id,
                    'level' => $targetLevel,
                ]);
            }
        }
    }

    private function getEffectiveApprovalLevels(RequestType $requestType, array $formData): int
    {
        return count($this->resolveEffectiveApproverMatrix($requestType, $formData));
    }

    private function getTotalApprovalLevels(FormDefinition $formDefinition, FormRecord $record): int
    {
        if ($formDefinition->workflow_type === 'checklist') {
            $tasks = $record->data['_checklist_tasks'] ?? null;

            if (is_array($tasks)) {
                return count($tasks);
            }
        }

        return $record->requestType
            ? $this->getEffectiveApprovalLevels($record->requestType, $record->data ?? [])
            : (int) $formDefinition->approval_levels;
    }

    private function resolveEffectiveApproverMatrix(RequestType $requestType, array $formData): array
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

    /**
     * Process approved dynamic form request to create a ticket automatically.
     */
    public function processApprovedRequest(FormDefinition $formDefinition, FormRecord $record): void
    {
        // An existing ticket blocks generation, archived ones included: they are
        // recoverable by restoring, and a second ticket would duplicate on restore.
        // Only a hard-deleted (dangling) ticket_id falls through and regenerates.
        if ($record->ticket_id && Ticket::withTrashed()->whereKey($record->ticket_id)->exists()) {
            return;
        }

        $creator = User::with('company')->find($record->created_by);
        $company = null;
        
        // Try getting company from record data first
        $companyId = $record->data['company_id'] ?? null;
        if ($companyId) {
            $company = \App\Models\Company::find($companyId);
        }
        if (!$company && $creator) {
            $company = $creator->company;
        }
        
        $companyId = $company ? $company->id : null;

        // ticket_key is left for TicketObserver to derive so it follows the same
        // store-owning-company rule as every other channel. Dynamic-form records are
        // entity-level (no store), so the observer resolves the ticket's own company
        // code — matching the historical prefix. company resolves from the record data
        // or the creator, so the company-less (unkeyed) path is effectively unreachable.
        $requestTypeName = $record->requestType ? $record->requestType->name : $formDefinition->name;
        $subject = "{$formDefinition->name} - {$requestTypeName}";

        $schema = $record->requestType ? $record->requestType->form_schema : $formDefinition->form_schema;

        $description = "🆔 Request Record: #{$record->id}\n" .
            "📋 Type: {$requestTypeName}\n" .
            "👤 Requester: " . ($creator ? $creator->name : 'N/A') . " (" . ($creator ? $creator->email : 'N/A') . ")\n";
        
        if ($company) {
            $description .= "🏢 Company: {$company->name}\n";
        }
        
        $description .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
            "   📝 FORM DETAILS\n" .
            "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        $formData = $record->data ?? [];
        $excludeKeys = ['items', '_checklist_tasks', 'approver_data'];
        
        if ($schema && !empty($schema['fields'])) {
            foreach ($schema['fields'] as $field) {
                $key = $field['key'] ?? null;
                if (!$key || in_array($key, $excludeKeys)) continue;
                
                $value = $formData[$key] ?? null;
                $displayValue = $this->getLabelFromSchema($schema, $key, $value, false);
                $description .= " • {$field['label']}: {$displayValue}\n";
            }
        } else {
            foreach ($formData as $key => $value) {
                if (in_array($key, $excludeKeys)) continue;
                $label = ucwords(str_replace('_', ' ', $key));
                $description .= " • {$label}: " . (is_array($value) ? json_encode($value) : $value) . "\n";
            }
        }

        // Add items if any
        $items = $record->data['items'] ?? [];
        if (!empty($items) && is_array($items)) {
            $description .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                "   📦 ITEMS\n" .
                "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $itemCols = $schema['items_columns'] ?? [];
            foreach ($items as $index => $item) {
                $description .= "【 ITEM #" . ($index + 1) . " 】\n";
                if (!empty($itemCols)) {
                    foreach ($itemCols as $col) {
                        $val = $item[$col['key']] ?? null;
                        $displayValue = $this->getLabelFromSchema($schema, $col['key'], $val, true);
                        $description .= " • {$col['label']}: {$displayValue}\n";
                    }
                } else {
                    foreach ($item as $key => $value) {
                        $label = ucwords(str_replace('_', ' ', $key));
                        $description .= " • {$label}: " . (is_array($value) ? json_encode($value) : $value) . "\n";
                    }
                }
                $description .= "────────────────────────────────────────\n";
            }
        }

        // Add approvals/approver details
        $approvals = $record->approvals()->with('user')->get();
        if ($approvals->isNotEmpty()) {
            $description .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                "   ✅ APPROVER DETAILS\n" .
                "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            foreach ($approvals as $approval) {
                $approverName = $approval->user ? $approval->user->name : 'Unknown';
                $description .= " • Stage {$approval->level} Approved by: {$approverName}\n";
                if ($approval->remarks) {
                    $description .= "   Remarks: {$approval->remarks}\n";
                }
                if ($approval->approver_data) {
                    foreach ($approval->approver_data as $k => $v) {
                        $label = ucwords(str_replace('_', ' ', $k));
                        $displayValue = $this->getLabelFromSchema($schema, $k, $v, false);
                        $description .= "   ➔ {$label}: {$displayValue}\n";
                    }
                }
            }
        }

        $ticket = Ticket::create([
            'title'        => $subject,
            'description'  => $description,
            'status'       => 'open',
            'priority'     => 'medium',
            'severity'     => 'minor',
            'reporter_id'  => $record->created_by,
            'sender_name'  => $creator ? $creator->name : null,
            'sender_email' => $creator ? $creator->email : null,
            'company_id'   => $companyId,
            'type'         => 'task',
            'created_at'   => now('Asia/Manila'),
        ]);

        $record->update(['ticket_id' => $ticket->id]);
    }

    /**
     * Format raw field values from form schema into readable labels.
     */
    private function getLabelFromSchema($schema, $key, $value, $isItem = false): string
    {
        if ($value === null) return '—';
        if (is_bool($value)) return $value ? 'Yes' : 'No';

        // Check if it's a file object or array of file objects
        if (is_array($value)) {
            if (isset($value['path']) && isset($value['name'])) {
                $url = route('attachments.download', ['path' => $value['path'], 'name' => $value['name']]);
                return "[{$value['name']}]({$url})";
            }
            
            // Multiple files
            $names = [];
            foreach ($value as $val) {
                $names[] = $this->formatNestedValue($val);
            }
            return implode(', ', $names);
        }

        if (!$schema) {
            return (string)$value;
        }

        $fields = $isItem ? ($schema['items_columns'] ?? []) : ($schema['fields'] ?? []);
        $field = collect($fields)->firstWhere('key', $key);

        if ($field && isset($field['options']) && !empty($field['options'])) {
            $options = collect($field['options']);
            $option = $options->firstWhere('value', $value);
            return $option ? $option['label'] : (string)$value;
        }

        return (string)$value;
    }

    private function formatNestedValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'â€”';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            if (isset($value['path'], $value['name'])) {
                $url = route('attachments.download', ['path' => $value['path'], 'name' => $value['name']]);

                return "[{$value['name']}]({$url})";
            }

            if (isset($value['name'])) {
                return (string) $value['name'];
            }

            $parts = [];
            foreach ($value as $key => $nestedValue) {
                $formattedValue = $this->formatNestedValue($nestedValue);
                $parts[] = is_string($key)
                    ? ucwords(str_replace('_', ' ', $key)) . ': ' . $formattedValue
                    : $formattedValue;
            }

            return implode('; ', array_filter($parts, fn ($part) => $part !== ''));
        }

        return json_encode($value) ?: '';
    }
}
