<?php

namespace App\Http\Controllers;

use App\Models\FormDefinition;
use App\Models\FormRecord;
use App\Models\User;
use App\Services\DynamicForms\FormServiceFactory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DynamicFormController extends Controller
{
    protected FormServiceFactory $serviceFactory;

    public function __construct(FormServiceFactory $serviceFactory)
    {
        $this->serviceFactory = $serviceFactory;
    }

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
        $record = FormRecord::with(['creator', 'updator', 'approvals.user', 'definition', 'requestType', 'ticket.slaMetric'])
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
        
        $service = $this->serviceFactory->make($slug);
        $service->store($request, $formDefinition);

        return redirect()->back()->with('success', 'Record created successfully');
    }

    public function update(Request $request, $slug, $id)
    {
        $formDefinition = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::where('form_definition_id', $formDefinition->id)->findOrFail($id);
        
        $service = $this->serviceFactory->make($slug);
        $service->update($request, $formDefinition, $record);

        return redirect()->back()->with('success', 'Record updated successfully');
    }

    public function approve(Request $request, $slug, $id)
    {
        $formDefinition = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::where('form_definition_id', $formDefinition->id)->findOrFail($id);

        $service = $this->serviceFactory->make($slug);
        $service->approve($request, $formDefinition, $record);

        return redirect()->back()->with('success', 'Record approved successfully');
    }

    public function reject(Request $request, $slug, $id)
    {
        $formDefinition = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::where('form_definition_id', $formDefinition->id)->findOrFail($id);

        $service = $this->serviceFactory->make($slug);
        $service->reject($request, $formDefinition, $record);

        return redirect()->back()->with('success', 'Record rejected successfully');
    }

    public function remind(Request $request, $slug, $id)
    {
        $formDefinition = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::where('form_definition_id', $formDefinition->id)->findOrFail($id);

        $service = $this->serviceFactory->make($slug);
        if (method_exists($service, 'notifyCurrentApprovers')) {
            $service->notifyCurrentApprovers($formDefinition, $record);
        }

        return redirect()->back()->with('success', 'Reminder sent successfully.');
    }

    public function destroy($slug, $id)
    {
        $form = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::where('form_definition_id', $form->id)->findOrFail($id);
        
        $record->delete();

        return redirect()->back()->with('success', 'Record deleted successfully');
    }
}
