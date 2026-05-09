<?php

namespace App\Http\Controllers;

use App\Models\FormDefinition;
use App\Models\FormRecord;
use App\Models\FormRecordApproval;
use App\Models\RequestType;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DynamicFormController extends Controller
{
    public function list(Request $request)
    {
        $query = FormRecord::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('data', 'like', "%{$search}%");
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $records = $query->with(['creator', 'definition', 'requestType'])
            ->latest()
            ->paginate($request->get('per_page', 10))
            ->withQueryString();

        return Inertia::render('DynamicForm/List', [
            'records' => $records,
            'forms' => FormDefinition::where('is_active', true)->get(['id', 'name', 'slug', 'description', 'icon', 'approval_levels']),
            'filters' => $request->only(['search', 'status']),
            'copyTransferPayload' => session('copy_transfer_payload'),
        ]);
    }

    public function index(Request $request, $slug)
    {
        $form = FormDefinition::where('slug', $slug)->with('requestTypes')->firstOrFail();
        
        $query = FormRecord::where('form_definition_id', $form->id);

        // Basic search in the JSON data column
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('data', 'like', "%{$search}%");
        }

        $records = $query->with(['creator', 'updator', 'requestType', 'approvals'])
                        ->latest()
                        ->paginate($request->get('per_page', 10))
                        ->withQueryString();

        return Inertia::render('DynamicForm/Index', [
            'form' => $form,
            'records' => $records,
            'copyTransferPayload' => session('copy_transfer_payload'),
        ]);
    }

    public function show($slug, $id)
    {
        $form = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::with(['creator', 'updator', 'approvals.user', 'definition', 'requestType'])
            ->where('form_definition_id', $form->id)
            ->findOrFail($id);

        return Inertia::render('DynamicForm/Show', [
            'form' => $form,
            'record' => $record,
            'users' => User::active()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request, $slug)
    {
        $formDefinition = FormDefinition::where('slug', $slug)->firstOrFail();
        
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
        
        // Final structure to save in 'data' column
        $saveData = array_merge($data['form_data'] ?? [], [
            'items' => $data['items'] ?? []
        ]);

        // Use RequestType approval levels if available, otherwise fallback to FormDefinition
        $approvalLevels = $requestType ? $requestType->approval_levels : $formDefinition->approval_levels;

        $status = $approvalLevels > 0 ? 'Open' : 'Approved';
        $currentLevel = $approvalLevels > 0 ? 1 : 0;

        FormRecord::create([
            'form_definition_id' => $formDefinition->id,
            'request_type_id' => $request->request_type_id,
            'data' => $saveData,
            'status' => $status,
            'current_approval_level' => $currentLevel,
            'created_by' => Auth::id(),
        ]);

        return redirect()->back()->with('success', 'Record created successfully');
    }

    public function update(Request $request, $slug, $id)
    {
        $form = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::where('form_definition_id', $form->id)->findOrFail($id);
        
        $data = $request->only(['form_data', 'items']);
        $data = $this->storeFileUploads($form, $data);
        
        $saveData = array_merge($data['form_data'] ?? [], [
            'items' => $data['items'] ?? []
        ]);

        $record->update([
            'data' => $saveData,
            'updated_by' => Auth::id(),
        ]);

        return redirect()->back()->with('success', 'Record updated successfully');
    }

    public function approve(Request $request, $slug, $id)
    {
        $formDefinition = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::with(['requestType', 'approvals'])->where('form_definition_id', $formDefinition->id)->findOrFail($id);

        $request->validate([
            'remarks' => 'nullable|string',
            'approver_data' => 'nullable|array',
            'force_level' => 'nullable|integer',
        ]);

        DB::transaction(function () use ($formDefinition, $record, $request) {
            $isChecklist = $formDefinition->workflow_type === 'checklist';
            $levelToApprove = $request->force_level ?? $record->current_approval_level;
            
            // Use RequestType approval levels if available
            $totalLevels = $record->requestType ? $record->requestType->approval_levels : $formDefinition->approval_levels;

            // Log approval
            FormRecordApproval::create([
                'form_record_id' => $record->id,
                'user_id' => Auth::id(),
                'level' => $levelToApprove,
                'remarks' => $request->remarks,
                'approver_data' => $request->approver_data,
            ]);

            if ($isChecklist) {
                // For checklists, we check if ALL levels now have at least one approval
                $approvedLevels = $record->approvals()->pluck('level')->push($levelToApprove)->unique();
                
                if ($approvedLevels->count() >= $totalLevels) {
                    $record->update([
                        'status' => 'Approved',
                        'current_approval_level' => 0,
                    ]);
                }
            } else {
                // Sequential logic
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

        return redirect()->back()->with('success', 'Record updated successfully');
    }

    public function reject(Request $request, $slug, $id)
    {
        $form = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::where('form_definition_id', $form->id)->findOrFail($id);

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

        return redirect()->back()->with('success', 'Record rejected successfully');
    }

    public function destroy($slug, $id)
    {
        $form = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::where('form_definition_id', $form->id)->findOrFail($id);
        
        $record->delete();

        return redirect()->back()->with('success', 'Record deleted successfully');
    }

    private function storeFileUploads($form, $data)
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
                        $storedFiles[] = $file; // Keep existing file data
                    }
                }
                $data['form_data'][$field['key']] = $storedFiles;
            }
        }
        
        return $data;
    }
}
