<?php

namespace App\Models;

use App\Support\TicketStatuses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Whether one department's desk may pick one ticket status. See TicketStatuses. */
class TicketStatusVisibility extends Model
{
    protected $fillable = ['department_id', 'status', 'is_visible', 'updated_by'];

    protected $casts = [
        'department_id' => 'integer',
        'is_visible' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => TicketStatuses::flush());
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
