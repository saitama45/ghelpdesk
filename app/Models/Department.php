<?php

namespace App\Models;

use App\Models\Scopes\ActiveEntityScope;
use App\Services\DepartmentMailRouter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    /**
     * Keep the inbound routing table in step with the departments it is built
     * from. Hooked on the model rather than the controllers so a rename, a
     * deletion, or a seeder can't leave a stale address→department mapping
     * behind — a stale entry would route mail to the wrong desk.
     */
    protected static function booted(): void
    {
        static::saved(fn () => DepartmentMailRouter::flush());
        static::deleted(fn () => DepartmentMailRouter::flush());

        // tickets.serving_department_id carries no FK — SQL Server allows only one
        // SET NULL path from departments into tickets and department_id already
        // holds it. Release the references here so deleting a department leaves
        // its tickets in the shared intake pool rather than pointing at a gone row.
        static::deleting(function (Department $department) {
            Ticket::withoutGlobalScope(ActiveEntityScope::class)
                ->where('serving_department_id', $department->id)
                ->update(['serving_department_id' => null]);
        });
    }

    protected $fillable = [
        'name',
        'code',
        'mail_address',
        'mail_from_name',
        'description',
        'is_active',
        'company_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(DepartmentNode::class)->whereNull('parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function allNodes(): HasMany
    {
        return $this->hasMany(DepartmentNode::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
