<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class CctvInspection extends Model
{
    public const STATUSES = ['Working', 'Not Working', 'For Schedule', 'On-going', 'Pending'];

    public const LGU_STATUSES = ['Compliant', 'Non-Compliant', 'Pending', 'N/A'];

    public const UNIT_CONDITIONS = ['Working', 'Defective', 'N/A'];

    protected $fillable = [
        'cctv_system_id',
        'inspection_date',
        'overall_status',
        'working_cameras',
        'not_working_cameras',
        'total_cameras',
        'technician',
        'data_retention',
        'storage',
        'ups_status',
        'lgu_memo',
        'lgu_status',
        'next_step',
        'remarks',
        'ticket_id',
        'is_latest',
        'created_by',
    ];

    protected $casts = [
        'inspection_date' => 'date',
        'working_cameras' => 'integer',
        'not_working_cameras' => 'integer',
        'total_cameras' => 'integer',
        'is_latest' => 'boolean',
    ];

    public function cctvSystem(): BelongsTo
    {
        return $this->belongsTo(CctvSystem::class);
    }

    public function store(): BelongsTo
    {
        return $this->hasOneThrough(Store::class, CctvSystem::class, 'id', 'id', 'cctv_system_id', 'store_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function linkedUnits(): BelongsToMany
    {
        return $this->belongsToMany(StockIn::class, 'cctv_inspection_units')
            ->using(CctvInspectionUnit::class)
            ->withPivot(['id', 'condition', 'notes'])
            ->withTimestamps();
    }

    /**
     * Re-flag the single latest inspection per (system, year, month).
     * Call within a transaction after create/update/delete.
     */
    public static function maintainLatestFlag(int $cctvSystemId): void
    {
        $rows = DB::table('cctv_inspections')
            ->where('cctv_system_id', $cctvSystemId)
            ->orderByDesc('inspection_date')
            ->get(['id', 'inspection_date']);

        $latestPerMonth = [];
        foreach ($rows as $row) {
            $key = $row->inspection_date ? substr($row->inspection_date, 0, 7) : null;
            if ($key && !isset($latestPerMonth[$key])) {
                $latestPerMonth[$key] = $row->id;
            }
        }

        $latestIds = array_values($latestPerMonth);

        DB::table('cctv_inspections')->where('cctv_system_id', $cctvSystemId)->update(['is_latest' => false]);

        if ($latestIds) {
            DB::table('cctv_inspections')->whereIn('id', $latestIds)->update(['is_latest' => true]);
        }
    }
}
