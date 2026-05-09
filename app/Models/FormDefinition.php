<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormDefinition extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
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
    ];

    public function requestTypes()
    {
        return $this->belongsToMany(RequestType::class, 'form_definition_request_type');
    }

    public function records()
    {
        return $this->hasMany(FormRecord::class);
    }
}
