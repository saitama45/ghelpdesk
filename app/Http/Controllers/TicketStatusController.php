<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\TicketStatus;
use App\Models\TicketStatusVisibility;
use App\Support\CompanyContext;
use App\Support\DepartmentContext;
use App\Support\DepartmentReferences;
use App\Support\TicketStatuses;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * References → Ticket Statuses: the status catalogue (add / rename) and which
 * statuses each department of the active entity may pick on its tickets.
 * Labels are global; visibility is per department. See TicketStatuses.
 */
class TicketStatusController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:ticket_statuses.view', only: ['index']),
            new Middleware('can:ticket_statuses.create', only: ['store']),
            new Middleware('can:ticket_statuses.edit', only: ['update', 'visibility']),
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $departments = $this->entityDepartments()->get(['id', 'name', 'code', 'is_active']);
        $canEdit = $user->can('ticket_statuses.edit');

        return Inertia::render('TicketStatuses/Index', [
            'statuses' => TicketStatuses::all()->map(fn ($status) => [
                'id' => $status->id,
                'key' => $status->key,
                'label' => $status->label,
                'color' => $status->color,
                'is_system' => (bool) $status->is_system,
                'behaves_like' => $status->behaves_like,
                'required' => in_array($status->key, TicketStatuses::REQUIRED, true),
            ])->values(),
            'departments' => $departments->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'code' => $department->code,
                'is_active' => $department->is_active,
                'hidden' => TicketStatuses::hiddenFor($department->id),
                'can_edit' => $canEdit && DepartmentReferences::canManage($department->id),
            ])->values(),
            'colors' => TicketStatuses::COLORS,
            'behaviors' => collect(TicketStatuses::BEHAVIORS)
                ->map(fn ($key) => ['value' => $key, 'label' => TicketStatuses::label($key)])->values(),
            // Pre-selected on "New Status"; null in Executive mode.
            'homeDepartmentId' => is_numeric($home = DepartmentContext::homeDepartmentId($user)) ? (int) $home : null,
            'can' => ['create' => $user->can('ticket_statuses.create'), 'edit' => $canEdit],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'color' => ['required', Rule::in(TicketStatuses::COLORS)],
            'behaves_like' => ['required', Rule::in(TicketStatuses::BEHAVIORS)],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer'],
        ]);
        $label = $this->assertUniqueLabel($validated['label']);

        $departmentIds = collect($validated['department_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $departments = $this->entityDepartments()->whereIn('id', $departmentIds)->get(['id', 'name']);
        if ($departments->count() !== $departmentIds->count()) {
            throw ValidationException::withMessages(['department_ids' => 'Choose departments of the active entity.']);
        }
        foreach ($departments as $department) {
            if (! DepartmentReferences::canManage($department->id)) {
                throw ValidationException::withMessages(['department_ids' => "You cannot turn statuses on for {$department->name}."]);
            }
        }

        DB::transaction(function () use ($validated, $label, $departments, $request) {
            $status = TicketStatus::create([
                'key' => $this->uniqueKey($label),
                'label' => $label,
                'color' => $validated['color'],
                'behaves_like' => $validated['behaves_like'],
                'is_system' => false,
                'sort_order' => (int) TicketStatus::max('sort_order') + 10,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            // A new status is hidden everywhere except the departments chosen here.
            foreach ($departments as $department) {
                TicketStatusVisibility::updateOrCreate(
                    ['department_id' => $department->id, 'status' => $status->key],
                    ['is_visible' => true, 'updated_by' => $request->user()->id],
                );
            }
        });

        return back()->with('success', "Status \"{$label}\" created.");
    }

    public function update(Request $request, TicketStatus $ticketStatus)
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'color' => ['required', Rule::in(TicketStatuses::COLORS)],
            // A system status IS its own behaviour; only custom ones can be re-pointed.
            'behaves_like' => [Rule::requiredIf(! $ticketStatus->is_system), 'nullable', Rule::in(TicketStatuses::BEHAVIORS)],
        ]);

        $ticketStatus->update([
            'label' => $this->assertUniqueLabel($validated['label'], $ticketStatus),
            'color' => $validated['color'],
            'behaves_like' => $ticketStatus->is_system ? null : $validated['behaves_like'],
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', "Status \"{$ticketStatus->label}\" updated.");
    }

    public function visibility(Request $request)
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer'],
            'status' => ['required', 'string', Rule::in(TicketStatuses::keys())],
            'is_visible' => ['required', 'boolean'],
        ]);

        $department = $this->entityDepartments()->whereKey($validated['department_id'])->firstOrFail();
        abort_unless(DepartmentReferences::canManage($department->id), 403);

        $label = TicketStatuses::label($validated['status']);
        if (in_array($validated['status'], TicketStatuses::REQUIRED, true) && ! $validated['is_visible']) {
            return back()->withErrors(['status' => "{$label} is part of every ticket's lifecycle and cannot be hidden."]);
        }

        TicketStatusVisibility::updateOrCreate(
            ['department_id' => $department->id, 'status' => $validated['status']],
            ['is_visible' => $validated['is_visible'], 'updated_by' => $request->user()->id],
        );

        return back()->with('success', sprintf('%s is now %s for %s.', $label, $validated['is_visible'] ? 'shown' : 'hidden', $department->name));
    }

    /** Departments of the active entity, as the department tabs show them. */
    private function entityDepartments()
    {
        return Department::where('company_id', CompanyContext::activeCompanyId())->orderBy('name');
    }

    private function assertUniqueLabel(string $label, ?TicketStatus $except = null): string
    {
        $label = trim(preg_replace('/\s+/', ' ', $label));
        $taken = TicketStatus::whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['label' => "A status named \"{$label}\" already exists."]);
        }

        return $label;
    }

    /** tickets.status value for a new status: snake_case of the label, never reused. */
    private function uniqueKey(string $label): string
    {
        $base = Str::limit(Str::slug($label, '_'), 40, '') ?: 'status';
        $key = $base;
        for ($i = 2; TicketStatus::where('key', $key)->exists(); $i++) {
            $key = "{$base}_{$i}";
        }

        return $key;
    }
}
