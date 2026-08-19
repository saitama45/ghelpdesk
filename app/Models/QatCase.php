<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One QAT test case. Sibling of {@see UatCase} — same shape, so cases copy
 * between the two modules without translation.
 *
 * Steps and expected results stay as authored text so procedures can be pasted
 * straight in from the source document without being shredded into child rows.
 */
class QatCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'qat_cycle_id',
        'qat_section_id',
        'case_key',
        'screen',
        'title',
        'description',
        'steps',
        'expected_results',
        'is_critical',
        'priority',
        'order',
        'source_uat_case_id',
        'created_by',
        'updated_by',
    ];

    // The sqlsrv driver hands back foreign keys as strings while the identity
    // column comes back as an int, so `$case->qat_cycle_id === $cycle->id`
    // silently fails without these. Every FK on the module is cast explicitly.
    protected $casts = [
        'is_critical' => 'boolean',
        'order' => 'integer',
        'qat_cycle_id' => 'integer',
        'qat_section_id' => 'integer',
        'source_uat_case_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public static function priorities(): array
    {
        return [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'critical' => 'Critical',
        ];
    }

    /**
     * Next free key inside a cycle. Follows whatever prefix the cycle already
     * uses, defaulting to TC.
     */
    public static function nextKey(int $cycleId, string $prefix = 'TC'): string
    {
        $keys = static::where('qat_cycle_id', $cycleId)->pluck('case_key');

        $highest = 0;
        foreach ($keys as $key) {
            if (preg_match('/(\d+)\s*$/', (string) $key, $m)) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return sprintf('%s-%02d', $prefix, $highest + 1);
    }

    /** The prefix already in use in this cycle, so new keys stay consistent. */
    public static function keyPrefix(int $cycleId): string
    {
        $sample = static::where('qat_cycle_id', $cycleId)->orderBy('id')->value('case_key');

        if ($sample && preg_match('/^(.*?)[-_ ]?\d+\s*$/', $sample, $m) && $m[1] !== '') {
            return rtrim($m[1], '-_ ');
        }

        return 'TC';
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(QatCycle::class, 'qat_cycle_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(QatSection::class, 'qat_section_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(QatCaseResult::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(QatFinding::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
