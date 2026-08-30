<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_template_id',
        'parent_activity_template_id',
        'depends_on_template_id',
        'can_run_parallel',
        'activity',
        'milestone',
        'milestone_order',
        'asset_item',
        'model_specs',
        'qty',
        'responsible',
        'department',
        'sub_unit',
        'default_duration_days',
        'order',
        'activity_mode',
        'milestone_weight',
        'activity_weight',
        'sub_task_weight',
        'acceptance_criteria',
    ];

    protected $casts = [
        'project_template_id' => 'integer',
        'parent_activity_template_id' => 'integer',
        'depends_on_template_id' => 'integer',
        'can_run_parallel' => 'boolean',
        'default_duration_days' => 'integer',
        'milestone_order' => 'integer',
        'order' => 'float',
        'qty' => 'integer',
        'milestone_weight' => 'decimal:2',
        'activity_weight' => 'decimal:2',
        'sub_task_weight' => 'decimal:2',
    ];

    public function projectTemplate()
    {
        return $this->belongsTo(ProjectTemplate::class);
    }

    public function parentActivity()
    {
        return $this->belongsTo(ActivityTemplate::class, 'parent_activity_template_id');
    }

    /** The requisite row this one starts after. NULL = follow the previous row. */
    public function dependsOn()
    {
        return $this->belongsTo(ActivityTemplate::class, 'depends_on_template_id');
    }

    public function subActivities()
    {
        return $this->hasMany(ActivityTemplate::class, 'parent_activity_template_id')
            ->orderBy('milestone_order')
            ->orderBy('order')
            ->orderBy('id');
    }
}
