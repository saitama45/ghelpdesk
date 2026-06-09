<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StampCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'stamp_program_id',
        'store_id',
        'stamps_count',
        'status',
        'completed_at',
        'redeemed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'stamp_program_id' => 'integer',
        'store_id' => 'integer',
        'stamps_count' => 'integer',
        'completed_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function program()
    {
        return $this->belongsTo(StampProgram::class, 'stamp_program_id');
    }

    public function entries()
    {
        return $this->hasMany(StampEntry::class);
    }

    public function redemption()
    {
        return $this->hasOne(StampRedemption::class);
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
