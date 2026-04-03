<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapRequestItem extends Model
{
    protected $fillable = [
        'sap_request_id',
        'item_data',
        'sort_order',
    ];

    protected $casts = [
        'item_data' => 'array',
    ];

    public function sapRequest()
    {
        return $this->belongsTo(SapRequest::class);
    }
}
