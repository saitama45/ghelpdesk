<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'project_type',
        'store_class',
        'entity_company_id',
        'brand_company_id',
        'project_name',
    ];

    protected $casts = [
        'entity_company_id' => 'integer',
        'brand_company_id' => 'integer',
    ];

    public function entityCompany()
    {
        return $this->belongsTo(Company::class, 'entity_company_id');
    }

    public function brandCompany()
    {
        return $this->belongsTo(Company::class, 'brand_company_id');
    }

    public function activities()
    {
        return $this->hasMany(ActivityTemplate::class)
            ->orderBy('milestone_order')
            ->orderBy('parent_activity_template_id')
            ->orderBy('order')
            ->orderBy('id');
    }
}
