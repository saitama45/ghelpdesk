<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepartmentNode extends Model
{
    protected $fillable = [
        'department_id',
        'parent_id',
        'name',
        'code',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'parent_id' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The department this node belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The parent node.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DepartmentNode::class, 'parent_id');
    }

    /**
     * Immediate child nodes.
     */
    public function children(): HasMany
    {
        return $this->hasMany(DepartmentNode::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Recursive children.
     */
    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    /**
     * Users assigned directly to this node.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'department_node_id');
    }
}
