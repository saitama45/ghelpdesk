<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentService extends Model
{
    protected $fillable = [
        'department_id',
        'name',
        'description',
        'eta',
        'route_name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
