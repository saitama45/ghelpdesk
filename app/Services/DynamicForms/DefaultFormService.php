<?php

namespace App\Services\DynamicForms;

use App\Models\FormDefinition;
use App\Models\FormRecord;
use App\Models\FormRecordApproval;
use App\Models\RequestType;
use App\Services\DynamicForms\Contracts\FormServiceContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DefaultFormService implements FormServiceContract
{
    public function store(Request $request, FormDefinition $formDefinition): FormRecord
    {
        $request->validate([
            'request_type_id' => 'nullable|exists:request_types,id',
            'form_data' => 'required|array',
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

        $approvalLevels = $requestType ? $requestType->approval_levels : $formDefinition->approval_levels;

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
            
            $totalLevels = $record->requestType ? $record->requestType->approval_levels : $formDefinition->approval_levels;

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
}
