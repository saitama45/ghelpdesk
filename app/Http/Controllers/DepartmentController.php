<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentSection;
use App\Models\DepartmentSubUnit;
use App\Models\DepartmentUnit;
use App\Models\User;
use App\Services\OrganizationReferenceService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DepartmentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:departments.view', only: ['index']),
            new Middleware('can:departments.create', only: ['store', 'storeSection', 'storeUnit', 'storeSubUnit']),
            new Middleware('can:departments.edit', only: ['update', 'updateSection', 'updateUnit', 'updateSubUnit', 'updateUserPlacement', 'updateVacant', 'reorderStructure']),
            new Middleware('can:departments.delete', only: ['destroy', 'destroySection', 'destroyUnit', 'destroySubUnit', 'destroyVacant']),
            new Middleware('can:departments.create', only: ['storeVacant']),
        ];
    }

    public function __construct(private OrganizationReferenceService $organizationReferences)
    {
    }

    public function index()
    {
        $users = User::with(['roles:id,name', 'managers:id,name'])
            ->orderBy('org_sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'email',
                'position',
                'department',
                'section',
                'unit',
                'sub_unit',
                'department_id',
                'department_section_id',
                'department_unit_id',
                'department_sub_unit_id',
                'is_active',
                'is_manager',
                'is_vacant',
                'profile_photo',
                'org_sort_order',
            ]);

        return Inertia::render('Departments/Index', [
            'departments' => $this->organizationReferences->tree(),
            'activeDepartments' => $this->organizationReferences->tree(activeOnly: true),
            'users' => $users,
            'authUserDepartmentId' => auth()->user()->department_id,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        Department::create([
            'name' => trim($validated['name']),
            'code' => filled($validated['code'] ?? null) ? trim($validated['code']) : null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->back()->with('success', 'Department created successfully.');
    }

    public function update(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->ignore($department->id)],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $department->update([
            'name' => trim($validated['name']),
            'code' => filled($validated['code'] ?? null) ? trim($validated['code']) : null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', 'Department updated successfully.');
    }

    public function destroy(Department $department)
    {
        if ($department->sections()->exists() || User::where('department_id', $department->id)->exists()) {
            throw ValidationException::withMessages([
                'department' => 'Remove child sections and assigned users before deleting this department.',
            ]);
        }

        $department->delete();

        return redirect()->back()->with('success', 'Department deleted successfully.');
    }

    public function storeSection(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('department_sections', 'name')
                    ->where(fn ($query) => $query->where('department_id', $department->id)),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $department->sections()->create([
            'name' => trim($validated['name']),
            'code' => filled($validated['code'] ?? null) ? trim($validated['code']) : null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->back()->with('success', 'Section created successfully.');
    }

    public function updateSection(Request $request, DepartmentSection $departmentSection)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('department_sections', 'name')
                    ->where(fn ($query) => $query->where('department_id', $departmentSection->department_id))
                    ->ignore($departmentSection->id),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $departmentSection->update([
            'name' => trim($validated['name']),
            'code' => filled($validated['code'] ?? null) ? trim($validated['code']) : null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', 'Section updated successfully.');
    }

    public function destroySection(DepartmentSection $departmentSection)
    {
        if ($departmentSection->units()->exists() || User::where('department_section_id', $departmentSection->id)->exists()) {
            throw ValidationException::withMessages([
                'section' => 'Remove child units and assigned users before deleting this section.',
            ]);
        }

        $departmentSection->delete();

        return redirect()->back()->with('success', 'Section deleted successfully.');
    }

    public function storeUnit(Request $request, DepartmentSection $departmentSection)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('department_units', 'name')
                    ->where(fn ($query) => $query->where('department_section_id', $departmentSection->id)),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $departmentSection->units()->create([
            'name' => trim($validated['name']),
            'code' => filled($validated['code'] ?? null) ? trim($validated['code']) : null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->back()->with('success', 'Unit created successfully.');
    }

    public function updateUnit(Request $request, DepartmentUnit $departmentUnit)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('department_units', 'name')
                    ->where(fn ($query) => $query->where('department_section_id', $departmentUnit->department_section_id))
                    ->ignore($departmentUnit->id),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $departmentUnit->update([
            'name' => trim($validated['name']),
            'code' => filled($validated['code'] ?? null) ? trim($validated['code']) : null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', 'Unit updated successfully.');
    }

    public function destroyUnit(DepartmentUnit $departmentUnit)
    {
        if ($departmentUnit->subUnits()->exists() || User::where('department_unit_id', $departmentUnit->id)->exists()) {
            throw ValidationException::withMessages([
                'unit' => 'Remove child sub-units and assigned users before deleting this unit.',
            ]);
        }

        $departmentUnit->delete();

        return redirect()->back()->with('success', 'Unit deleted successfully.');
    }

    public function storeSubUnit(Request $request, DepartmentUnit $departmentUnit)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('department_sub_units', 'name')
                    ->where(fn ($query) => $query->where('department_unit_id', $departmentUnit->id)),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $departmentUnit->subUnits()->create([
            'name' => trim($validated['name']),
            'code' => filled($validated['code'] ?? null) ? trim($validated['code']) : null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->back()->with('success', 'Sub-Unit created successfully.');
    }

    public function updateSubUnit(Request $request, DepartmentSubUnit $departmentSubUnit)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('department_sub_units', 'name')
                    ->where(fn ($query) => $query->where('department_unit_id', $departmentSubUnit->department_unit_id))
                    ->ignore($departmentSubUnit->id),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $departmentSubUnit->update([
            'name' => trim($validated['name']),
            'code' => filled($validated['code'] ?? null) ? trim($validated['code']) : null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', 'Sub-Unit updated successfully.');
    }

    public function destroySubUnit(DepartmentSubUnit $departmentSubUnit)
    {
        if ($departmentSubUnit->users()->exists()) {
            throw ValidationException::withMessages([
                'sub_unit' => 'Remove assigned users before deleting this sub-unit.',
            ]);
        }

        $departmentSubUnit->delete();

        return redirect()->back()->with('success', 'Sub-Unit deleted successfully.');
    }

    public function updateUserPlacement(Request $request, User $user)
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'department_section_id' => ['nullable', 'integer', 'exists:department_sections,id'],
            'department_unit_id' => ['nullable', 'integer', 'exists:department_units,id'],
            'department_sub_unit_id' => ['nullable', 'integer', 'exists:department_sub_units,id'],
            'manager_ids' => ['nullable', 'array'],
            'manager_ids.*' => ['integer', 'exists:users,id'],
            'profile_photo' => ['nullable', 'image', 'max:2048'],
            'org_sort_order' => ['nullable', 'integer'],
        ]);

        $managerIds = collect($validated['manager_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($managerIds->contains((int) $user->id)) {
            throw ValidationException::withMessages([
                'manager_ids' => 'A user cannot report to themselves.',
            ]);
        }

        if ($managerIds->isNotEmpty()) {
            $validManagerCount = User::active()
                ->where('is_manager', true)
                ->whereIn('id', $managerIds->all())
                ->count();

            if ($validManagerCount !== $managerIds->count()) {
                throw ValidationException::withMessages([
                    'manager_ids' => 'Reports To users must be active users marked as managers.',
                ]);
            }
        }

        $orgIds = collect([
            $validated['department_id'] ?? null,
            $validated['department_section_id'] ?? null,
            $validated['department_unit_id'] ?? null,
            $validated['department_sub_unit_id'] ?? null,
        ])->filter(fn ($value) => filled($value));

        DB::transaction(function () use ($user, $validated, $managerIds, $orgIds, $request) {
            if ($request->hasFile('profile_photo')) {
                if ($user->profile_photo) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($user->profile_photo);
                }
                $path = $request->file('profile_photo')->store('profile-photos', 'public');
                $user->profile_photo = $path;
                $user->save();
            }

            if ($orgIds->isEmpty()) {
                $user->forceFill([
                    ...$this->organizationReferences->clearPayload(),
                    'org_sort_order' => (int) ($validated['org_sort_order'] ?? 0),
                    'updated_by' => auth()->id(),
                ])->save();
            } else {
                $payload = $this->organizationReferences->payloadFromIds(
                    $validated['department_id'] ? (int) $validated['department_id'] : null,
                    $validated['department_section_id'] ? (int) $validated['department_section_id'] : null,
                    $validated['department_unit_id'] ? (int) $validated['department_unit_id'] : null,
                    $validated['department_sub_unit_id'] ? (int) $validated['department_sub_unit_id'] : null
                );

                if ($validated['department_id'] && !filled($payload['department_id'])) {
                    throw ValidationException::withMessages([
                        'department_id' => 'Selected department is invalid or inactive.',
                    ]);
                }

                $user->forceFill([
                    ...$payload,
                    'org_sort_order' => (int) ($validated['org_sort_order'] ?? 0),
                    'updated_by' => auth()->id(),
                ])->save();
            }

            $user->managers()->sync($managerIds->all());
        });

        return redirect()->back()->with('success', 'User placement updated successfully.');
    }

    public function storeVacant(Request $request)
    {
        $validated = $request->validate([
            'title'                  => ['nullable', 'string', 'max:255'],
            'department_id'          => ['nullable', 'integer', 'exists:departments,id'],
            'department_section_id'  => ['nullable', 'integer', 'exists:department_sections,id'],
            'department_unit_id'     => ['nullable', 'integer', 'exists:department_units,id'],
            'department_sub_unit_id' => ['nullable', 'integer', 'exists:department_sub_units,id'],
            'manager_ids'            => ['nullable', 'array'],
            'manager_ids.*'          => ['integer', 'exists:users,id'],
            'org_sort_order'         => ['nullable', 'integer'],
        ]);

        $managerIds = collect($validated['manager_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();

        $payload = [];
        if ($validated['department_id'] ?? null) {
            $payload = $this->organizationReferences->payloadFromIds(
                (int) $validated['department_id'],
                $validated['department_section_id'] ? (int) $validated['department_section_id'] : null,
                $validated['department_unit_id']    ? (int) $validated['department_unit_id']    : null,
                $validated['department_sub_unit_id'] ? (int) $validated['department_sub_unit_id'] : null,
            );
        }

        DB::transaction(function () use ($validated, $payload, $managerIds) {
            $title = filled($validated['title'] ?? null) ? $validated['title'] : 'Vacant Position';
            $user = User::create([
                'name'       => $title,
                'email'      => 'vacant.' . uniqid() . '@placeholder.internal',
                'password'   => bcrypt(str()->random(32)),
                'is_vacant'  => true,
                'is_active'  => true,
                'is_manager' => false,
                'position'   => $title,
                ...$payload,
                'org_sort_order' => (int) ($validated['org_sort_order'] ?? 0),
                'created_by' => auth()->id(),
            ]);
            $user->managers()->sync($managerIds->all());
        });

        return redirect()->back()->with('success', 'Vacant position added to org chart.');
    }

    public function updateVacant(Request $request, User $user)
    {
        abort_if(! $user->is_vacant, 403);

        $validated = $request->validate([
            'title'                  => ['nullable', 'string', 'max:255'],
            'department_id'          => ['nullable', 'integer', 'exists:departments,id'],
            'department_section_id'  => ['nullable', 'integer', 'exists:department_sections,id'],
            'department_unit_id'     => ['nullable', 'integer', 'exists:department_units,id'],
            'department_sub_unit_id' => ['nullable', 'integer', 'exists:department_sub_units,id'],
            'manager_ids'            => ['nullable', 'array'],
            'manager_ids.*'          => ['integer', 'exists:users,id'],
            'org_sort_order'         => ['nullable', 'integer'],
        ]);

        $managerIds = collect($validated['manager_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();

        $orgIds = collect([
            $validated['department_id'] ?? null,
            $validated['department_section_id'] ?? null,
            $validated['department_unit_id'] ?? null,
            $validated['department_sub_unit_id'] ?? null,
        ])->filter(fn ($v) => filled($v));

        $payload = $orgIds->isEmpty()
            ? $this->organizationReferences->clearPayload()
            : $this->organizationReferences->payloadFromIds(
                $validated['department_id'] ? (int) $validated['department_id'] : null,
                $validated['department_section_id'] ? (int) $validated['department_section_id'] : null,
                $validated['department_unit_id']    ? (int) $validated['department_unit_id']    : null,
                $validated['department_sub_unit_id'] ? (int) $validated['department_sub_unit_id'] : null,
            );

        DB::transaction(function () use ($user, $validated, $payload, $managerIds) {
            $title = filled($validated['title'] ?? null) ? $validated['title'] : 'Vacant Position';
            $user->forceFill([
                'name'     => $title,
                'position' => $title,
                ...$payload,
                'org_sort_order' => (int) ($validated['org_sort_order'] ?? 0),
                'updated_by' => auth()->id(),
            ])->save();
            $user->managers()->sync($managerIds->all());
        });

        return redirect()->back()->with('success', 'Vacant position updated.');
    }

    public function destroyVacant(User $user)
    {
        abort_if(! $user->is_vacant, 403);
        $user->managers()->detach();
        $user->delete();
        return redirect()->back()->with('success', 'Vacant position removed from org chart.');
    }

    public function reorderUsers(Request $request)
    {
        $validated = $request->validate([
            'users' => ['required', 'array'],
            'users.*.id' => ['required', 'integer', 'exists:users,id'],
            'users.*.org_sort_order' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['users'] as $u) {
                User::where('id', $u['id'])->update(['org_sort_order' => $u['org_sort_order']]);
            }
        });

        return redirect()->back()->with('success', 'Organisation chart order updated.');
    }

    public function reorderStructure(Request $request)
    {
        $validated = $request->validate([
            'type'              => ['required', 'in:section,unit,sub_unit'],
            'items'             => ['required', 'array'],
            'items.*.id'        => ['required', 'integer'],
            'items.*.sort_order' => ['required', 'integer'],
        ]);

        $table = match ($validated['type']) {
            'section'  => 'department_sections',
            'unit'     => 'department_units',
            'sub_unit' => 'department_sub_units',
        };

        DB::transaction(function () use ($validated, $table) {
            foreach ($validated['items'] as $item) {
                DB::table($table)
                    ->where('id', (int) $item['id'])
                    ->update(['sort_order' => (int) $item['sort_order']]);
            }
        });

        return redirect()->back()->with('success', 'Structure order updated.');
    }
}
