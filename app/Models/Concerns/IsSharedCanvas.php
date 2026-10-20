<?php

namespace App\Models\Concerns;

use App\Models\Department;
use App\Models\User;
use App\Support\SharedCanvasAccess;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A page-based canvas document (diagram, standalone whiteboard) that people share by
 * visibility: private / department / everyone. Columns: title, description, visibility,
 * department_id, scene, preview, revision, page_count, created_by, updated_by.
 *
 * @see SharedCanvasAccess for who can see and change one.
 */
trait IsSharedCanvas
{
    public const VISIBILITIES = ['private', 'department', 'everyone'];

    /**
     * Everything except the scene/preview LOBs. Routes that run often (poll, autosave) bind
     * the record with these columns only — see the light bindings in routes/web.php.
     */
    public const LIGHT_COLUMNS = [
        'id', 'title', 'description', 'visibility', 'department_id', 'revision', 'page_count',
        'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
