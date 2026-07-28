<?php

namespace App\Http\Controllers;

use App\Models\FormDefinition;
use App\Models\FormRecord;
use App\Models\User;
use App\Services\DynamicForms\FormServiceFactory;
use App\Support\Concerns\ResolvesLinkedTicket;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DynamicFormController extends Controller
{
    use ResolvesLinkedTicket;

    protected FormServiceFactory $serviceFactory;

    public function __construct(FormServiceFactory $serviceFactory)
    {
        $this->serviceFactory = $serviceFactory;
    }

    public function list(Request $request)
    {
        $query = FormRecord::query();

        // Cross-form list: show each form's records only where the viewer manages
        // that form; everything else narrows to their own submissions. Mirrors the
        // per-form rule in index() so /forms can't sidestep it.
        $managedFormIds = FormDefinition::where('is_active', true)
            ->get(['id', 'slug'])
            ->filter(fn (FormDefinition $f) => $request->user()->can($f->slug . '.view'))
            ->pluck('id')
            ->all();

        $query->where(function ($q) use ($managedFormIds, $request) {
            $q->whereIn('form_definition_id', $managedFormIds)
                ->orWhere('created_by', $request->user()->id);
        });

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

        // `ticket` hides archived + cross-entity tickets, which the list would otherwise
        // render as "Missing". Resolve them directly and tag each row's ticket_state.
        $this->annotateTicketState($records->getCollection());

        return Inertia::render('DynamicForm/List', [
            'records' => $records,
            'forms' => FormDefinition::where('is_active', true)->get(['id', 'name', 'slug', 'description', 'icon', 'approval_levels']),
            'filters' => $request->only(['search', 'status']),
            'copyTransferPayload' => session('copy_transfer_payload'),
        ]);
    }

    /**
     * Whether the viewer MANAGES this form's queue rather than merely using it.
     *
     * Anyone may submit a request to another department's service — that is the
     * whole point of the internal-customer view — but only the holder of the
     * form's {slug}.view permission may see everybody's records. Without it the
     * viewer is a customer and sees only what they submitted themselves.
     */
    private function managesForm(Request $request, FormDefinition $form): bool
    {
        return (bool) $request->user()?->can($form->slug . '.view');
    }

    public function index(Request $request, $slug)
    {
        $form = FormDefinition::where('slug', $slug)->with(['requestTypes', 'department:id,name,code'])->firstOrFail();

        $manages = $this->managesForm($request, $form);

        $query = FormRecord::where('form_definition_id', $form->id);

        // Customer mode: your own submissions only, never the department's queue.
        if (! $manages) {
            $query->where('created_by', $request->user()->id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('data', 'like', "%{$search}%");
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('onboarding_date_from')) {
            $query->where('data->onboarding_date', '>=', $request->onboarding_date_from);
        }

        if ($request->filled('onboarding_date_to')) {
            $query->where('data->onboarding_date', '<=', $request->onboarding_date_to);
        }

        $records = $query->with(['creator', 'updator', 'requestType', 'approvals'])
                        ->latest()
                        ->paginate($request->get('per_page', 10))
                        ->withQueryString();

        // See list(): the scoped relation can't distinguish archived from never-created.
        $this->annotateTicketState($records->getCollection());

        return Inertia::render('DynamicForm/Index', [
            'form' => $form,
            'records' => $records,
            'filters' => $request->only(['search', 'status', 'onboarding_date_from', 'onboarding_date_to']),
            'copyTransferPayload' => session('copy_transfer_payload'),
            // Drives the provider-vs-customer framing on the page: managers work
            // the whole queue, customers see and track only their own requests.
            'accessView' => $manages ? 'provider' : 'customer',
        ]);
    }

    public function show(Request $request, $slug, $id)
    {
        $form = FormDefinition::where('slug', $slug)->firstOrFail();
        $record = FormRecord::with(['creator', 'updator', 'approvals.user', 'definition', 'requestType', 'ticket.slaMetric'])
            ->where('form_definition_id', $form->id)
            ->findOrFail($id);

        // Customers may open their own submission and nothing else — index() hides
        // other people's records, so a direct id must not be a way around it.
        // Approvers are exempt: they have to read what they are asked to approve.
        $manages = $this->managesForm($request, $form);
        $isOwner = (int) $record->created_by === (int) $request->user()->id;
        $isApprover = $record->approvals()->where('user_id', $request->user()->id)->exists();
        abort_unless($manages || $isOwner || $isApprover, 403);

        // A live ticket in another entity loads as null through the scoped relation;
        // re-attach it so the page shows the ticket instead of claiming it's missing.
        $resolved = $this->resolveTicket($record->ticket_id);

        if (!$record->ticket && $resolved && !$resolved->trashed()) {
            $record->setRelation('ticket', $resolved->load('slaMetric'));
        }

        return Inertia::render('DynamicForm/Show', [
            'form' => $form,
            'record' => $record,
            'ticketState' => $this->ticketStateOf($resolved),
            'archivedTicket' => $this->archivedTicketPayload($resolved),
            'users' => User::active()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request, $slug)
    {
        $formDefinition = FormDefinition::where('slug', $slug)->firstOrFail();

        $service = $this->serviceFactory->make($slug);
        $record = $service->store($request, $formDefinition);

        if ($record->wasRecentlyCreated && method_exists($service, 'notifyCurrentApprovers')) {
            $service->notifyCurrentApprovers($formDefinition, $record);
        }

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
            $level = $request->filled('level') ? (int) $request->input('level') : null;
            $service->notifyCurrentApprovers($formDefinition, $record, $level);
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
