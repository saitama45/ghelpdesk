<?php

namespace App\Services\DynamicForms;

use App\Models\FormDefinition;
use App\Models\FormRecord;
use App\Models\FormRecordApproval;
use App\Models\RequestType;
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

        return FormRecord::create([
            'form_definition_id' => $formDefinition->id,
            'request_type_id' => $request->request_type_id,
            'data' => $saveData,
            'status' => $status,
            'current_approval_level' => $currentLevel,
            'created_by' => Auth::id(),
        ]);
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
            $isChecklist = $formDefinition->workflow_type === 'checklist';
            $levelToApprove = $request->force_level ?? $record->current_approval_level;
            
            $totalLevels = $record->requestType
                ? $this->getEffectiveApprovalLevels($record->requestType, $record->data ?? [])
                : (int) $formDefinition->approval_levels;

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
                }
            } else {
                $nextLevel = $levelToApprove + 1;
                if ($nextLevel > $totalLevels) {
                    $record->update([
                        'status' => 'Approved',
                        'current_approval_level' => 0,
                    ]);
                } else {
                    $record->update([
                        'current_approval_level' => $nextLevel,
                    ]);
                }
            }
        });

        $record->refresh();
        $this->notifyCurrentApprovers($formDefinition, $record);
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

        $approvers = User::active()
            ->whereIn('id', $approverIds->map(fn ($id) => (int) $id)->filter()->unique()->values())
            ->get(['id', 'name', 'email'])
            ->filter(fn (User $user) => filter_var($user->email, FILTER_VALIDATE_EMAIL))
            ->unique(fn (User $user) => strtolower($user->email));

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
}
