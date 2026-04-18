<?php

namespace App\Http\Controllers;

use App\Models\FormDefinition;
use App\Models\FormRecord;
use App\Models\FormRecordApproval;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DynamicFormController extends Controller
{
    public function index(Request $request, $slug)
    {
        $form = FormDefinition::where('slug', $slug)->firstOrFail();
        
        $query = FormRecord::where('form_definition_id', $form->id);
        
        // Basic search in the JSON data column
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('data', 'like', "%{$search}%");
        }
        
        $records = $query->with(['creator', 'updator'])
                        ->latest()
                        ->paginate($request->get('per_page', 10))
                        ->withQueryString();
        
        return Inertia::render('DynamicForm/Index', [
            'form' => $form,
            'records' => $records,
        ]);
    }

    public function show($slug, $id)
    {
        $form = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::with(['creator', 'updator', 'approvals.user', 'definition'])
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
        $form = FormDefinition::where('slug', $slug)->firstOrFail();
        
        $data = $request->only(['form_data', 'items']);
        $data = $this->storeFileUploads($form, $data);
        
        // Final structure to save in 'data' column
        $saveData = array_merge($data['form_data'] ?? [], [
            'items' => $data['items'] ?? []
        ]);

        $status = $form->approval_levels > 0 ? 'Open' : 'Approved';
        $currentLevel = $form->approval_levels > 0 ? 1 : 0;

        FormRecord::create([
            'form_definition_id' => $form->id,
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
        $form = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::where('form_definition_id', $form->id)->findOrFail($id);

        $request->validate([
            'remarks' => 'nullable|string',
            'approver_data' => 'nullable|array',
        ]);

        DB::transaction(function () use ($form, $record, $request) {
            $currentLevel = $record->current_approval_level;
            
            // Log approval
            FormRecordApproval::create([
                'form_record_id' => $record->id,
                'user_id' => Auth::id(),
                'level' => $currentLevel,
                'remarks' => $request->remarks,
                'approver_data' => $request->approver_data,
            ]);

            $nextLevel = $currentLevel + 1;
            if ($nextLevel > $form->approval_levels) {
                $record->update([
                    'status' => 'Approved',
                    'current_approval_level' => 0,
                ]);
            } else {
                $record->update([
                    'status' => "Approved Level {$currentLevel}",
                    'current_approval_level' => $nextLevel,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Stage approved successfully');
    }

    private function storeFileUploads(FormDefinition $form, array $data): array
    {
        $schema = $form->form_schema;
        if (!$schema) return $data;

        // Process main fields
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
                                'path' => $f->store('form-records/attachments', 'public'),
                                'name' => $f->getClientOriginalName(),
                            ];
                        } else {
                            $paths[] = $f;
                        }
                    }
                    $data['form_data'][$key] = $paths;
                } elseif ($val instanceof \Illuminate\Http\UploadedFile) {
                    $data['form_data'][$key] = [
                        'path' => $val->store('form-records/attachments', 'public'),
                        'name' => $val->getClientOriginalName(),
                    ];
                }
            }
        }

        // Process line items
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $idx => $item) {
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
                                        'path' => $f->store('form-records/attachments', 'public'),
                                        'name' => $f->getClientOriginalName(),
                                    ];
                                } else {
                                    $paths[] = $f;
                                }
                            }
                            $data['items'][$idx][$key] = $paths;
                        } elseif ($val instanceof \Illuminate\Http\UploadedFile) {
                            $data['items'][$idx][$key] = [
                                'path' => $val->store('form-records/attachments', 'public'),
                                'name' => $val->getClientOriginalName(),
                            ];
                        }
                    }
                }
            }
        }

        return $data;
    }

    public function destroy($slug, $id)
    {
        $form = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::where('form_definition_id', $form->id)->findOrFail($id);
        
        $record->delete();

        return redirect()->back()->with('success', 'Record deleted successfully');
    }
}
