<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    // Deleting a customer archives the row (Settings → Account Archive), and
    // takes their linked mobile-app login with it — see AccountArchiveService.
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'is_active',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'deleted_by' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function stampCards()
    {
        return $this->hasMany(StampCard::class);
    }

    /** The mobile-app login this CRM record belongs to, if they've registered. */
    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function redemptions()
    {
        return $this->hasMany(StampRedemption::class);
    }

    public function voucherRedemptions()
    {
        return $this->hasMany(VoucherRedemption::class);
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
