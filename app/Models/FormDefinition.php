<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormDefinition extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
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

    public function records()
    {
        return $this->hasMany(FormRecord::class);
    }
}
