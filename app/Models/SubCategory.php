<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubCategory extends Model
{
    use \App\Models\Concerns\HasDepartmentReference;
    protected $fillable = [
        'department_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Owning entity; drives entity switching on the reference pages (EntityReferenceScope). */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
