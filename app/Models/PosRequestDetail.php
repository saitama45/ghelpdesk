<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosRequestDetail extends Model
{
    protected $fillable = [
        'pos_request_id',
        'product_name',
        'pos_name',
        'remarks_mechanics',
        'price_type',
        'price_amount',
        'category',
        'item_code',
        'sc',
        'local_tax',
        'mgr_meal',
        'printer',
    ];

    public function posRequest()
    {
        return $this->belongsTo(PosRequest::class);
    }
}
