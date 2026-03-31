<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosRequestApproval extends Model
{
    protected $fillable = [
        'pos_request_id',
        'user_id',
        'level',
        'remarks',
    ];

    public function posRequest()
    {
        return $this->belongsTo(PosRequest::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
