<?php

namespace App\Services;

use App\Models\Department;
use App\Models\DepartmentSubUnit;
use App\Models\User;

class OrganizationReferenceService
{
    public function tree(bool $activeOnly = false): array
    {
        $departments = Department::query()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->with([
                'sections' => fn ($query) => $query
                    ->when($activeOnly, fn ($q) => $q->where('is_active', true))
                    ->reorder()->orderBy('sort_order')->orderBy('name'),
                'sections.units' => fn ($query) => $query
                    ->when($activeOnly, fn ($q) => $q->where('is_active', true))
                    ->reorder()->orderBy('sort_order')->orderBy('name'),
                'sections.units.subUnits' => fn ($query) => $query
                    ->when($activeOnly, fn ($q) => $q->where('is_active', true))
                    ->reorder()->orderBy('sort_order')->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        return $departments->map(fn (Department $department) => [
            'id' => $department->id,
            'name' => $department->name,
            'code' => $department->code,
            'description' => $department->description,
            'is_active' => $department->is_active,
            'sections' => $department->sections->map(fn ($section) => [
                'id' => $section->id,
                'department_id' => $section->department_id,
                'name' => $section->name,
                'code' => $section->code,
                'description' => $section->description,
                'is_active' => $section->is_active,
                'sort_order' => $section->sort_order,
                'units' => $section->units->map(fn ($unit) => [
                    'id' => $unit->id,
                    'department_section_id' => $unit->department_section_id,
                    'name' => $unit->name,
                    'code' => $unit->code,
                    'description' => $unit->description,
                    'is_active' => $unit->is_active,
                    'sort_order' => $unit->sort_order,
                    'sub_units' => $unit->subUnits->map(fn ($subUnit) => [
                        'id' => $subUnit->id,
                        'department_unit_id' => $subUnit->department_unit_id,
                        'name' => $subUnit->name,
                        'code' => $subUnit->code,
                        'description' => $subUnit->description,
                        'is_active' => $subUnit->is_active,
                        'sort_order' => $subUnit->sort_order,
                    ])->values(),
                ])->values(),
            ])->values(),
        ])->values()->all();
    }

    public function resolveSubUnitChain(
        ?int $departmentId,
        ?int $sectionId,
        ?int $unitId,
        ?int $subUnitId,
        bool $activeOnly = true
    ): ?DepartmentSubUnit {
        if (!$departmentId || !$sectionId || !$unitId || !$subUnitId) {
            return null;
        }

        $subUnit = DepartmentSubUnit::with('unit.section.department')->find($subUnitId);

        if (!$subUnit || !$subUnit->unit || !$subUnit->unit->section || !$subUnit->unit->section->department) {
            return null;
        }

        if (
            (int) $subUnit->department_unit_id !== (int) $unitId
            || (int) $subUnit->unit->department_section_id !== (int) $sectionId
            || (int) $subUnit->unit->section->department_id !== (int) $departmentId
        ) {
            return null;
        }

        if (
            $activeOnly
            && (!$subUnit->is_active || !$subUnit->unit->is_active || !$subUnit->unit->section->is_active || !$subUnit->unit->section->department->is_active)
        ) {
            return null;
        }

        return $subUnit;
    }

    public function payloadForSubUnit(DepartmentSubUnit $subUnit): array
    {
        $subUnit->loadMissing('unit.section.department');

        return [
            'department' => $subUnit->unit->section->department->name,
            'section' => $subUnit->unit->section->name,
            'unit' => $subUnit->unit->name,
            'sub_unit' => $subUnit->name,
            'department_id' => $subUnit->unit->section->department_id,
            'department_section_id' => $subUnit->unit->department_section_id,
            'department_unit_id' => $subUnit->department_unit_id,
            'department_sub_unit_id' => $subUnit->id,
        ];
    }

    public function clearPayload(): array
    {
        return [
            'department' => null,
            'section' => null,
            'unit' => null,
            'sub_unit' => null,
            'department_id' => null,
            'department_section_id' => null,
            'department_unit_id' => null,
            'department_sub_unit_id' => null,
        ];
    }

    public function payloadFromIds(
        ?int $departmentId,
        ?int $sectionId = null,
        ?int $unitId = null,
        ?int $subUnitId = null,
        bool $activeOnly = true
    ): array {
        if (!$departmentId) {
            return $this->clearPayload();
        }

        $payload = $this->clearPayload();

        $dept = Department::find($departmentId);
        if (!$dept || ($activeOnly && !$dept->is_active)) {
            return $payload;
        }
        $payload['department'] = $dept->name;
        $payload['department_id'] = $dept->id;

        if ($sectionId) {
            $section = \App\Models\DepartmentSection::find($sectionId);
            if (!$section || (int)$section->department_id !== (int)$departmentId || ($activeOnly && !$section->is_active)) {
                return $payload;
            }
            $payload['section'] = $section->name;
            $payload['department_section_id'] = $section->id;

            if ($unitId) {
                $unit = \App\Models\DepartmentUnit::find($unitId);
                if (!$unit || (int)$unit->department_section_id !== (int)$sectionId || ($activeOnly && !$unit->is_active)) {
                    return $payload;
                }
                $payload['unit'] = $unit->name;
                $payload['department_unit_id'] = $unit->id;

                if ($subUnitId) {
                    $subUnit = DepartmentSubUnit::find($subUnitId);
                    if (!$subUnit || (int)$subUnit->department_unit_id !== (int)$unitId || ($activeOnly && !$subUnit->is_active)) {
                        return $payload;
                    }
                    $payload['sub_unit'] = $subUnit->name;
                    $payload['department_sub_unit_id'] = $subUnit->id;
                }
            }
        }

        return $payload;
    }

    public function applySubUnitToUser(User $user, DepartmentSubUnit $subUnit): void
    {
        $user->forceFill($this->payloadForSubUnit($subUnit))->save();
    }

    public function clearUserOrganization(User $user): void
    {
        $user->forceFill($this->clearPayload())->save();
    }
}
