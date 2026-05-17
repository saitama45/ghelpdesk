<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWeeklyPlan extends Model
{
    protected $fillable = [
        'vendor_id',
        'project_label',
        'month',
        'week_no',
        'week_date',
        'amount',
        'category',
        'notes',
        'assignee_user_id',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'vendor_id' => 'integer',
        'week_no' => 'integer',
        'week_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'assignee_user_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }
}
