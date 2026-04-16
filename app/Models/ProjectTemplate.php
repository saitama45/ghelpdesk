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
    ];

    public function activities()
    {
        return $this->hasMany(ActivityTemplate::class)->orderBy('order');
    }
}
