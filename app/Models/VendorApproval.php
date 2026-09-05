<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One approve/reject/suspend/reactivate decision on a vendor-portal account.
 * Append-only: rows are never edited, so the account's history stays readable.
 */
class VendorApproval extends Model
{
    protected $fillable = [
        'vendor_id',
        'action',
        'status_before',
        'status_after',
        'remarks',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
