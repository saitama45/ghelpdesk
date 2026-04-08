<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'request_type_id',
        'ticket_id',
        'user_id',
        'requester_name',
        'requester_email',
        'launch_date',
        'effectivity_date',
        'stores_covered',
        'approver_data',
        'status',
        'current_approval_level',
    ];

    protected $casts = [
        'stores_covered' => 'array',
        'approver_data' => 'array',
        'launch_date' => 'date:Y-m-d',
        'effectivity_date' => 'date:Y-m-d',
        'current_approval_level' => 'integer',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function requestType()
    {
        return $this->belongsTo(RequestType::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function details()
    {
        return $this->hasMany(PosRequestDetail::class);
    }

    public function approvals()
    {
        return $this->hasMany(PosRequestApproval::class);
    }
}
