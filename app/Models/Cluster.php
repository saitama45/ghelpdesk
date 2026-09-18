<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Cluster extends Model
{
    use \App\Models\Concerns\HasDepartmentReference;
    protected $fillable = [
        'department_id',
        'code',
        'name',
    ];

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class)->withTimestamps();
    }

    /** Owning entity; drives entity switching on the reference pages (EntityReferenceScope). */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
