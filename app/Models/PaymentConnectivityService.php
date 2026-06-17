<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentConnectivityService extends Model
{
    protected $fillable = [
        'store_id',
        'role',
        'vendor_id',
        'telco',
        'account_no',
        'service_id',
        'bandwidth',
        'install_type',
        'installation_date',
        'billing_day',
        'mrc',
        'currency',
        'status',
        'assignee_id',
        'cc_emails',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'vendor_id' => 'integer',
        'installation_date' => 'date:Y-m-d',
        'billing_day' => 'integer',
        'mrc' => 'decimal:2',
        'assignee_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    protected static function booted()
    {
        static::saving(function (PaymentConnectivityService $service) {
            // Default the billing day from the installation date when not set explicitly.
            if (! $service->billing_day && $service->installation_date) {
                $service->billing_day = (int) \Carbon\Carbon::parse($service->installation_date)->day;
            }
        });
    }
}
