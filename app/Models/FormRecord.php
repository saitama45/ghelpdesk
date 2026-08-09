<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormRecord extends Model
{
    // Archiving = soft delete. The delete button still destroys the row outright
    // (forceDelete), so the two actions stay distinct.
    use SoftDeletes;

    protected $fillable = [
        'form_definition_id',
        'request_type_id',
        'submission_token',
        'ticket_id',
        'data',
        'status',
        'current_approval_level',
        'created_by',
        'updated_by',
        'archived_at',
        'archived_by',
    ];

    protected $casts = [
        'form_definition_id' => 'integer',
        'request_type_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'archived_by' => 'integer',
        'archived_at' => 'datetime',
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

    public function archiver()
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function approvals()
    {
        return $this->hasMany(FormRecordApproval::class);
    }
}
