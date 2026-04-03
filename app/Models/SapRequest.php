<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SapRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'request_type_id',
        'ticket_id',
        'user_id',
        'requester_name',
        'requester_email',
        'status',
        'current_approval_level',
        'form_data',
    ];

    protected $casts = [
        'form_data' => 'array',
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

    public function items()
    {
        return $this->hasMany(SapRequestItem::class)->orderBy('sort_order');
    }

    public function approvals()
    {
        return $this->hasMany(SapRequestApproval::class);
    }
}
