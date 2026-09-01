<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ownership record for one milestone of a project plan.
 *
 * A milestone is not a row on the Gantt — it is the `category` shared by a group
 * of top-level project_tasks. This model is the only place that milestone is a
 * first-class thing, and it exists so a milestone can have an owner.
 */
class ProjectMilestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'category',
        'assigned_to',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'assigned_to' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    /**
     * How a milestone name is stored and compared. `project_tasks.category` is
     * nullable and the Gantt renders a blank category as "General", so an owner
     * set on that group has to be found again under the same name.
     */
    public static function normaliseCategory(?string $category): string
    {
        $trimmed = trim((string) $category);

        return $trimmed !== '' ? $trimmed : 'General';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
