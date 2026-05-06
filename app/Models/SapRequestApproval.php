<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapRequestApproval extends Model
{
    protected $fillable = [
        'sap_request_id',
        'user_id',
        'level',
        'status',
        'remarks',
    ];

    protected $casts = [
        'sap_request_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function sapRequest()
    {
        return $this->belongsTo(SapRequest::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
