<?php

namespace App\Http\Controllers;

use App\Models\{Category, Department, Item, SubCategory};
use App\Support\{CompanyContext, DepartmentReferences, EntityReferenceScope};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves one catalogue row (cluster, category, sub-category, item) to a service
 * department. Department tags use each reference module's existing edit
 * permission; stores and vendors have no tag — they are shared by every
 * department of their entity.
 */
class ReferenceDepartmentController extends Controller
{
    public function update(Request $request, string $type, int $id)
    {
        $class = DepartmentReferences::MODELS[$type] ?? null;
        abort_unless($class, 404);
        $permission = $type === 'sub_categories' ? 'subcategories' : $type;
        abort_unless($request->user()->can($permission.'.edit'), 403);
        $data = $request->validate(['department_id' => 'required|integer|exists:departments,id']);
        $department = Department::findOrFail($data['department_id']);
        abort_unless($department->is_active && (int) $department->company_id === CompanyContext::activeCompanyId(), 403);
        // A desk may claim rows for itself; only a departments administrator
        // may hand a reference to another desk.
        abort_unless(DepartmentReferences::canManage((int) $department->id), 403);

        DB::transaction(function () use ($class, $id, $type, $department) {
            $row = $class::whereKey($id)->lockForUpdate()->firstOrFail();
            EntityReferenceScope::ensureOwned($row);
            // Taking a row away from the department that owns it needs the same
            // right as managing that department.
            abort_unless(DepartmentReferences::canRetag($row), 403);
            if ($row->company_id && (int) $row->company_id !== (int) $department->company_id) {
                abort(403);
            }
            if ((int) $row->department_id === (int) $department->id) {
                return;
            }
            if ($type === 'items') {
                $this->alignClassification($row, $department);
            }
            $duplicate = $class::where('company_id', $department->company_id)->where('department_id', $department->id)
                ->where('name', $row->name)->whereKeyNot($row->id);
            if ($type === 'items') {
                foreach (['category_id', 'sub_category_id', 'concern_type'] as $field) {
                    $duplicate->where($field, $row->$field);
                }
            }
            if ($duplicate->exists() || ($type === 'clusters' && $class::where('company_id', $department->company_id)
                ->where('department_id', $department->id)->where('code', $row->code)->whereKeyNot($row->id)->exists())) {
                throw ValidationException::withMessages(['department_id' => 'This department already has a reference with that identity.']);
            }
            $row->company_id = $department->company_id;
            $row->department_id = $department->id;
            $row->save();
        });

        return back()->with('success', 'Service department updated.');
    }

    /**
     * A tagged item must use a category and sub-category of its own department.
     * Point it at the department's same-named reference, or bring an untagged one
     * of the same entity along with it; anything else is owned by another desk.
     */
    private function alignClassification(Item $item, Department $department): void
    {
        foreach (['category_id' => [Category::class, 'category'], 'sub_category_id' => [SubCategory::class, 'sub-category']] as $field => [$class, $label]) {
            $reference = $item->$field ? $class::find($item->$field) : null;
            if (! $reference || ((int) $reference->department_id === (int) $department->id
                && (int) $reference->company_id === (int) $department->company_id)) {
                continue;
            }
            $match = $class::where('company_id', $department->company_id)->where('department_id', $department->id)
                ->where('name', $reference->name)->value('id');
            if ($match) {
                $item->$field = $match;
            } elseif (! $reference->department_id
                && (! $reference->company_id || (int) $reference->company_id === (int) $department->company_id)) {
                $reference->company_id = $department->company_id;
                $reference->department_id = $department->id;
                $reference->save();
            } else {
                throw ValidationException::withMessages(['department_id' => "The item's {$label} \"{$reference->name}\" belongs to another department or entity. Create or tag a {$label} with that name for {$department->name} first."]);
            }
        }
    }
}
