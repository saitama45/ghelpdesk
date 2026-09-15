<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubCategory extends Model
{
    protected $fillable = [
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
