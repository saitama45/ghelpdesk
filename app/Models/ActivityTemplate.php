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
        'activity',
        'milestone',
        'asset_item',
        'model_specs',
        'qty',
        'responsible',
        'default_duration_days',
        'order',
    ];

    protected $casts = [
        'default_duration_days' => 'integer',
        'order' => 'integer',
        'qty' => 'integer',
    ];

    public function projectTemplate()
    {
        return $this->belongsTo(ProjectTemplate::class);
    }

    public function parentActivity()
    {
        return $this->belongsTo(ActivityTemplate::class, 'parent_activity_template_id');
    }

    public function subActivities()
    {
        return $this->hasMany(ActivityTemplate::class, 'parent_activity_template_id')->orderBy('order');
    }
}
