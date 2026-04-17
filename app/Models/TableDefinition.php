<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TableDefinition extends Model
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
}
