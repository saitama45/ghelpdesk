<?php

namespace App\Support;

use App\Http\Middleware\EnsureCanvasVisible;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Who can see and change a shared canvas (diagram, standalone whiteboard). The listing
 * filter and the per-record check mirror each other exactly, so a record that shows in the
 * list always opens, and one hidden from the list is also refused by URL
 * ({@see EnsureCanvasVisible}).
 *
 *   - Admin / Solutions Admin see everything.
 *   - The creator always sees their own.
 *   - "everyone"   → every user holding the module's .view permission.
 *   - "department" → users whose "I belong to" department owns it; unowned (NULL) is shared;
 *                    Executive mode sees every department's.
 *   - "private"    → the creator only.
 */
final class SharedCanvasAccess
{
    public static function seesEverything(User $user): bool
    {
        return $user->hasAnyRole(['Admin', 'Solutions Admin']);
    }

    public static function scopeVisible(Builder $query, User $user): Builder
    {
        if (self::seesEverything($user)) {
            return $query;
        }

        $executive = DepartmentContext::isExecutive($user);
        $homeId = DepartmentContext::homeDepartmentId($user);

        return $query->where(function (Builder $visible) use ($user, $executive, $homeId) {
            $visible->where('created_by', $user->id)
                ->orWhere('visibility', 'everyone')
                ->orWhere(function (Builder $department) use ($executive, $homeId) {
                    $department->where('visibility', 'department');

                    if (! $executive) {
                        $department->where(function (Builder $owned) use ($homeId) {
                            $owned->whereNull('department_id');
                            if ($homeId) {
                                $owned->orWhere('department_id', $homeId);
                            }
                        });
                    }
                });
        });
    }

    public static function canView(User $user, Model $record): bool
    {
        if (self::seesEverything($user) || self::isCreator($user, $record)) {
            return true;
        }

        return match ($record->visibility) {
            'everyone' => true,
            'department' => DepartmentContext::isExecutive($user)
                || $record->department_id === null
                || $record->department_id === DepartmentContext::homeDepartmentId($user),
            default => false,
        };
    }

    /** `$permission` is the module prefix, e.g. "diagrams" → diagrams.edit. */
    public static function canEdit(User $user, Model $record, string $permission): bool
    {
        return $user->can("{$permission}.edit") && self::canView($user, $record);
    }

    /** Owner-level actions: changing who can see it, deleting it. */
    public static function canManage(User $user, Model $record): bool
    {
        return self::isCreator($user, $record) || self::seesEverything($user);
    }

    private static function isCreator(User $user, Model $record): bool
    {
        return $record->created_by !== null && (int) $record->created_by === (int) $user->id;
    }
}
