<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $fillable = [
        'code',
        'name',
        'vendor_type',
        'contact_person',
        'email',
        'phone',
        'address',
        'is_active',
        'default_payment_mode',
        'default_payment_split',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'default_payment_split' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
