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
        'holiday_count',
        'status',
        'completed_at',
        'redeemed_at',
        'created_by',
        'updated_by',
        'cashier_vendor_id',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'stamp_program_id' => 'integer',
        'store_id' => 'integer',
        'stamps_count' => 'integer',
        'holiday_count' => 'integer',
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

    /*
     | Slot arithmetic. Written so a program edited mid-campaign still adds
     | up: holiday stamps beyond the holiday slots occupy regular slots, and
     | regular stamps beyond the regular slots occupy holiday ones, so the free
     | slots always total `stamps_required - stamps_count`.
     */

    public function regularCount(): int
    {
        return max(0, (int) $this->stamps_count - (int) $this->holiday_count);
    }

    public function holidayFree(StampProgram $program): int
    {
        $overflow = max(0, $this->regularCount() - $program->regularSlots());

        return max(0, $program->holidaySlots() - (int) $this->holiday_count - $overflow);
    }

    public function regularFree(StampProgram $program): int
    {
        $overflow = max(0, (int) $this->holiday_count - $program->holidaySlots());

        return max(0, $program->regularSlots() - $this->regularCount() - $overflow);
    }

    public function holidayFilled(StampProgram $program): int
    {
        return $program->holidaySlots() - $this->holidayFree($program);
    }

    public function regularFilled(StampProgram $program): int
    {
        return $program->regularSlots() - $this->regularFree($program);
    }


    /** Set instead of the user columns when the record came from the vendor portal. */
    public function cashierVendor()
    {
        return $this->belongsTo(Vendor::class, 'cashier_vendor_id');
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
