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
        'sub_category',
        'validity_date',
        'item_code',
        'sc',
        'local_tax',
        'mgr_meal',
        'printer',
    ];

    protected $casts = [
        'validity_date' => 'date:Y-m-d',
    ];

    public function posRequest()
    {
        return $this->belongsTo(PosRequest::class);
    }
}
