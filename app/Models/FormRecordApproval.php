<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormRecordApproval extends Model
{
    protected $fillable = [
        'form_record_id',
        'user_id',
        'level',
        'remarks',
        'approver_data',
    ];

    protected $casts = [
        'approver_data' => 'array',
    ];

    public function record()
    {
        return $this->belongsTo(FormRecord::class, 'form_record_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
