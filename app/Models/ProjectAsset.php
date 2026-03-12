<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'project_task_id',
        'category',
        'item_name',
        'model_specs',
        'quantity',
        'delivery_status',
        'responsible',
        'store_delivery_date',
        'store_setup_date',
        'remarks',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'store_delivery_date' => 'date',
        'store_setup_date' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }
}
