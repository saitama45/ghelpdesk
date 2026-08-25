<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StampProgram extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'year',
        'description',
        'emoji',
        'tag',
        'stamps_required',
        'auto_stamp_amount',
        'eligible_items_description',
        'reward_description',
        'terms_and_conditions',
        'starts_at',
        'ends_at',
        'display_order',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'stamps_required' => 'integer',
        'auto_stamp_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'display_order' => 'integer',
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function stampCards()
    {
        return $this->hasMany(StampCard::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
