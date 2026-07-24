<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlagaAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'inspector_id',
        'company_id',
        'assessment_date',
        'overall_score',
        'status',
        'asset_scores',
        'checklist',
        'observations',
        'recommendations',
        'next_review',
        'workflow_status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'inspector_id' => 'integer',
        'company_id' => 'integer',
        // Calendar dates — cast date-only so JSON keeps Y-m-d (Asia/Manila),
        // avoiding the UTC off-by-one that plain 'date' introduces.
        'assessment_date' => 'date:Y-m-d',
        'next_review' => 'date:Y-m-d',
        'overall_score' => 'decimal:2',
        'asset_scores' => 'array',
        'checklist' => 'array',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    /** Excellent >= 3.5, Good >= 3.0, else Fair. */
    public static function statusForScore(float $score): string
    {
        return $score >= 3.5 ? 'Excellent' : ($score >= 3.0 ? 'Good' : 'Fair');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
