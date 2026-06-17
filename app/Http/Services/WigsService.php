<?php

namespace App\Http\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared logic for the WIGS module:
 *  - hierarchy scoping (who a user may view/manage), and
 *  - quarterly grading windows.
 */
class WigsService
{
    /**
     * IDs of the users whose WIGS records the given actor may view/manage.
     *
     * Rules:
     *  - wigs.manage_all  -> every user (returns null = "no restriction").
     *  - otherwise        -> the actor plus every descendant in the org tree
     *                        (transitive closure over the manager_user pivot).
     *
     * @return array<int>|null  Null means unrestricted (see all).
     */
    public static function viewableUserIds(User $actor): ?array
    {
        if ($actor->can('wigs.manage_all')) {
            return null;
        }

        $ids = [$actor->id];

        // Breadth-first walk down the manager_user pivot (manager_id -> user_id).
        $frontier = [$actor->id];
        $seen = [$actor->id => true];

        while (!empty($frontier)) {
            $children = DB::table('manager_user')
                ->whereIn('manager_id', $frontier)
                ->pluck('user_id')
                ->all();

            $frontier = [];
            foreach ($children as $childId) {
                $childId = (int) $childId;
                if (!isset($seen[$childId])) {
                    $seen[$childId] = true;
                    $ids[] = $childId;
                    $frontier[] = $childId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Can the actor access (view/manage) the WIGS records of $targetUserId?
     */
    public static function canAccessUser(User $actor, int $targetUserId): bool
    {
        $ids = self::viewableUserIds($actor);

        return $ids === null || in_array($targetUserId, $ids, true);
    }

    /**
     * IDs of the team members the actor may create a PCF for:
     *  - "Is Manager" -> themselves + their direct reports (users who report to them).
     *  - otherwise    -> only themselves (self-service).
     *
     * This is intentionally org-based and does NOT widen for wigs.manage_all —
     * that permission governs which records may be viewed/managed, not who a
     * person commits goals for.
     *
     * @return array<int>
     */
    public static function selectableUserIds(User $actor): array
    {
        if ($actor->is_manager) {
            $ids = $actor->subordinates()
                ->where('users.is_active', true)
                ->pluck('users.id')
                ->all();
            $ids[] = $actor->id;
        } else {
            $ids = [$actor->id];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Selectable team members as {value,label} options.
     *
     * @return array<int, array{value:int,label:string}>
     */
    public static function selectableUsers(User $actor): array
    {
        return User::whereIn('id', self::selectableUserIds($actor))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'position'])
            ->map(fn (User $u) => [
                'value' => $u->id,
                'label' => $u->position ? "{$u->name} — {$u->position}" : $u->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Inclusive date range [start, end] of a quarter for a given year.
     */
    public static function quarterRange(int $year, int $quarter): array
    {
        $startMonth = (($quarter - 1) * 3) + 1;
        $start = Carbon::create($year, $startMonth, 1)->startOfDay();
        $end = (clone $start)->addMonths(3)->subDay()->endOfDay();

        return [$start, $end];
    }

    /**
     * Grading for a quarter opens on the first day of the month AFTER the
     * quarter's last covered day (e.g. Q1 = Jan–Mar opens Apr 1).
     */
    public static function gradingOpensAt(int $year, int $quarter): Carbon
    {
        [, $end] = self::quarterRange($year, $quarter);

        return $end->copy()->addDay()->startOfMonth()->startOfDay();
    }

    /**
     * Is the quarter currently open for grading?
     */
    public static function isQuarterOpen(int $year, int $quarter, ?Carbon $now = null): bool
    {
        $now ??= now();

        return $now->greaterThanOrEqualTo(self::gradingOpensAt($year, $quarter));
    }

    /**
     * Per-quarter open status for a year, keyed 1..4.
     *
     * @return array<int, array{open:bool, opens_at:string, label:string}>
     */
    public static function quarterStatuses(int $year, ?Carbon $now = null): array
    {
        $now ??= now();
        $labels = [1 => 'Q1 (Jan–Mar)', 2 => 'Q2 (Apr–Jun)', 3 => 'Q3 (Jul–Sep)', 4 => 'Q4 (Oct–Dec)'];

        $out = [];
        foreach ([1, 2, 3, 4] as $q) {
            $opensAt = self::gradingOpensAt($year, $q);
            $out[$q] = [
                'open' => $now->greaterThanOrEqualTo($opensAt),
                'opens_at' => $opensAt->toDateString(),
                'label' => $labels[$q],
            ];
        }

        return $out;
    }
}
