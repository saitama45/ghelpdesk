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
        'cc_emails',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'request_for' => 'array',
    ];
}
