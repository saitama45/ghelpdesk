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

    protected $casts = [
        'pos_request_id' => 'integer',
        'user_id' => 'integer',
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
