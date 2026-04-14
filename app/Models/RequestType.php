<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'request_for',
        'approval_levels',
        'approver_matrix',
        'cc_emails',
        'is_active',
        'form_schema',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'request_for' => 'array',
        'approver_matrix' => 'array',
        'form_schema' => 'array',
    ];
}
