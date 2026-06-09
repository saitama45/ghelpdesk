<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StampProgram extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'year',
        'description',
        'stamps_required',
        'auto_stamp_amount',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'stamps_required' => 'integer',
        'auto_stamp_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function stampCards()
    {
        return $this->hasMany(StampCard::class);
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
