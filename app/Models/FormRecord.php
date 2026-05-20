<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormRecord extends Model
{
    protected $fillable = [
        'form_definition_id',
        'request_type_id',
        'ticket_id',
        'data',
        'status',
        'current_approval_level',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'form_definition_id' => 'integer',
        'request_type_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'data' => 'array',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function definition()
    {
        return $this->belongsTo(FormDefinition::class, 'form_definition_id');
    }

    public function requestType()
    {
        return $this->belongsTo(RequestType::class, 'request_type_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updator()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approvals()
    {
        return $this->hasMany(FormRecordApproval::class);
    }
}
