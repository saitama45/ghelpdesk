<?php

namespace App\Models\Concerns;

use App\Models\Department;
use App\Models\Item;
use App\Support\CompanyContext;
use App\Support\DepartmentReferences;

trait HasDepartmentReference
{
    public static function bootHasDepartmentReference(): void
    {
        static::saving(function ($model) {
            if (! $model->exists && auth()->check()) {
                $model->department_id ??= DepartmentReferences::viewedId();
                $model->company_id ??= CompanyContext::activeCompanyId();
                if ($model->department_id) {
                    abort_unless(DepartmentReferences::canManage((int) $model->department_id), 403);
                }
            }
            if ($model->department_id) {
                $department = Department::findOrFail($model->department_id);
                if ((int) $department->company_id !== (int) $model->company_id) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'department_id' => 'The reference and its service department must belong to the same entity.',
                    ]);
                }
            }
            if ($model instanceof Item) {
                DepartmentReferences::validateClassification($model);
            }
        });
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
