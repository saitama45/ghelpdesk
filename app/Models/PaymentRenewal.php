<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PaymentRenewal extends Model
{
    protected $fillable = [
        'vendor_id',
        'service_type',
        'sub_type',
        'purpose',
        'unit_cost',
        'qty',
        'total_amount',
        'currency',
        'cycle',
        'cycle_anchor_date',
        'next_due_date',
        'expiration_date',
        'payment_terms',
        'assignee_user_id',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'vendor_id' => 'integer',
        'qty' => 'integer',
        'unit_cost' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'cycle_anchor_date' => 'date:Y-m-d',
        'next_due_date' => 'date:Y-m-d',
        'expiration_date' => 'date:Y-m-d',
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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paymentRecords()
    {
        return $this->morphMany(PaymentRecord::class, 'payable', 'payable_type', 'payable_id')
            ->where('payable_type', 'renewal');
    }

    protected static function booted()
    {
        static::saving(function ($renewal) {
            if ($renewal->cycle_anchor_date && $renewal->cycle && !$renewal->next_due_date) {
                $renewal->next_due_date = self::computeNextDueDate(
                    Carbon::parse($renewal->cycle_anchor_date),
                    $renewal->cycle
                );
            }
            if (!$renewal->total_amount && $renewal->unit_cost && $renewal->qty) {
                $renewal->total_amount = (float) $renewal->unit_cost * (int) $renewal->qty;
            }
        });
    }

    public static function computeNextDueDate(Carbon $anchor, string $cycle): Carbon
    {
        $now = Carbon::now()->startOfDay();
        $next = $anchor->copy();
        $increment = match ($cycle) {
            'monthly' => fn ($d) => $d->addMonth(),
            'quarterly' => fn ($d) => $d->addMonths(3),
            'semi_annual' => fn ($d) => $d->addMonths(6),
            'annual' => fn ($d) => $d->addYear(),
            default => fn ($d) => $d->addMonth(),
        };
        $guard = 0;
        while ($next->lt($now) && $guard < 240) {
            $next = $increment($next);
            $guard++;
        }
        return $next;
    }

    public function advanceCycle(): void
    {
        if (!$this->next_due_date || !$this->cycle) return;
        $this->next_due_date = self::computeNextDueDate(
            Carbon::parse($this->next_due_date)->addDay(),
            $this->cycle
        );
        $this->save();
    }
}
