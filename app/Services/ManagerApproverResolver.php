<?php

namespace App\Services;

use App\Models\DepartmentNode;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Works out who a given user's approving manager is.
 *
 * There is no "department head" column anywhere in this schema, so this cannot be
 * a lookup. What exists is: the `manager_user` many-to-many pivot (a user may have
 * SEVERAL managers), the `users.is_manager` flag, and the recursive
 * `department_nodes` tree. The ladder below is the one already proven in
 * ScheduleController::resolveScheduleChangeApproverIds(), lifted out and
 * parameterised on the permission slug so any module can use it.
 *
 * ScheduleController is deliberately left alone. Pointing it at this service would
 * be a behaviour-neutral refactor of a working approval flow, which is not worth
 * bundling into a new module's first release — it is a clean follow-up.
 */
class ManagerApproverResolver
{
    /** Which rung of the ladder produced the answer — recorded for the audit trail. */
    public const SOURCE_DIRECT_MANAGER = 'direct_manager';

    public const SOURCE_DEPARTMENT_NODE = 'department_node';

    public const SOURCE_ADMIN_FALLBACK = 'admin_fallback';

    public const SOURCE_NONE = 'none';

    /**
     * @param  User  $requester  the person whose approval chain is being resolved
     * @param  string  $permission  approvers must additionally hold this, e.g. 'qat.approve'
     * @return array{ids: int[], source: string}
     */
    public function resolve(
        User $requester,
        string $permission,
        array $fallbackRoles = ['Admin', 'Solutions Admin']
    ): array {
        // 1. Direct managers from the org chart.
        $direct = $this->eligible(
            $requester->managers()
                ->where('is_active', true)
                ->where('is_vacant', false)
                ->where('is_manager', true)
                ->pluck('users.id'),
            $permission,
            $requester
        );

        if ($direct->isNotEmpty()) {
            return ['ids' => $direct->all(), 'source' => self::SOURCE_DIRECT_MANAGER];
        }

        // 2. Nobody is named as their manager — climb the department tree until a
        //    level yields somebody flagged as a manager.
        if ($requester->department_node_id) {
            $node = DepartmentNode::find($requester->department_node_id);

            while ($node) {
                $node = $node->parent_id ? DepartmentNode::find($node->parent_id) : null;

                if (! $node) {
                    break;
                }

                $leaders = $this->eligible(
                    User::active()
                        ->where('is_vacant', false)
                        ->where('is_manager', true)
                        ->where('department_node_id', $node->id)
                        ->pluck('id'),
                    $permission,
                    $requester
                );

                if ($leaders->isNotEmpty()) {
                    return ['ids' => $leaders->all(), 'source' => self::SOURCE_DEPARTMENT_NODE];
                }
            }
        }

        // 3. Last resort, so a cycle can never become undecidable.
        $admins = $this->eligible(
            User::role($fallbackRoles)
                ->where('is_active', true)
                ->where('is_vacant', false)
                ->pluck('id'),
            $permission,
            $requester
        );

        if ($admins->isNotEmpty()) {
            return ['ids' => $admins->all(), 'source' => self::SOURCE_ADMIN_FALLBACK];
        }

        // The caller must refuse to create a pending request from this. A request
        // with no possible approver is a record nobody can ever clear.
        return ['ids' => [], 'source' => self::SOURCE_NONE];
    }

    /**
     * Keeps only active, non-vacant candidates who actually hold the permission —
     * and never the requester themselves, which a self-managing row would
     * otherwise allow.
     *
     * The permission has to be checked in PHP rather than in the query: Spatie
     * resolves it through roles as well as direct grants, so `User::permission()`
     * alone would miss role-granted holders in some configurations.
     */
    private function eligible($ids, string $permission, User $requester): Collection
    {
        $ids = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->reject(fn (int $id) => $id === (int) $requester->id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $ids->all())
            ->where('is_active', true)
            ->where('is_vacant', false)
            ->get()
            ->filter(fn (User $user) => $user->can($permission))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /** Display names for a resolved id list, for the "waiting on" banner. */
    public function names(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return User::whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
