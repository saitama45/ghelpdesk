<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormDefinition extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        // The department that OWNS this service. Drives the Services hub:
        // its own members see it as providers, everyone else as customers.
        'department_id',
        'workflow_type',
        'icon',
        'approval_levels',
        'approver_matrix',
        'cc_emails',
        'form_schema',
        'is_active',
    ];

    protected $casts = [
        'approver_matrix' => 'array',
        'form_schema' => 'array',
        'is_active' => 'boolean',
        'department_id' => 'integer',
    ];

    /** The department that offers this service to the organisation. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requestTypes()
    {
        return $this->belongsToMany(RequestType::class, 'form_definition_request_type');
    }

    public function records()
    {
        return $this->hasMany(FormRecord::class);
    }
}
