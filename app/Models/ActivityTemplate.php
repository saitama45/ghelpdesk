<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_template_id',
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
}
