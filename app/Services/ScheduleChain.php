<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Turns an ordered set of plan rows into concrete Start/Finish dates, driven by
 * each row's requisite rather than by its position in the list.
 *
 * The rules come from References/Business_Requirement_Milestone_Schedule_
 * Computation.xlsx ("Developer Logic"), for a row R with dependency D:
 *
 *   - No dependency at all      — R starts on the fallback (Day 1 for the first
 *                                 root, the parent's start for a first sub-task).
 *   - "Can Run Parallel? = No"  — R starts the day after D finishes.
 *   - "Can Run Parallel? = Yes" — R starts on the SAME day D starts, so the two
 *                                 run side by side.
 *
 * A NULL requisite means "follow the previous row", which is what every row did
 * before dependencies existed — so an untouched plan schedules exactly as it
 * always has. That implicit predecessor IS the dependency for the rules above,
 * which is what makes "Start = previous Finish + 1" fall out of the No branch.
 *
 * Sub-tasks run *inside* their parent: the first one starts on the parent's own
 * start date and the parent's bar is stretched to cover whatever its sub-tasks
 * actually span. A parent owns no dates of its own; its lead time is the SUM of
 * its sub-tasks' lead times (ProjectScheduler::syncParentRollups).
 *
 * Rows are resolved requisite-first, not top-to-bottom, so a row may depend on
 * one further down the list. A dependency cycle can't be satisfied, so the rows
 * caught in it fall back to plain sequential order rather than hanging.
 */
class ScheduleChain
{
    public function __construct(private ScheduleCalculator $calculator)
    {
    }

    public function calculator(): ScheduleCalculator
    {
        return $this->calculator;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  each: id, parent_id,
     *         depends_on, parallel, days, anchor (Y-m-d|null), milestone_order, order
     * @return array<string, array{start: string, end: string, days: int}> keyed by row id
     */
    public function resolve(array $rows, Carbon|string|null $day1): array
    {
        if (!$day1 || $rows === []) {
            return [];
        }

        $day1 = $this->calculator->toWorkingDay(Carbon::parse($day1)->startOfDay());

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$this->key($row['id'])] = $this->normalize($row);
        }

        // A requisite pointing at a row that is not in this plan (deleted, or
        // filtered out) is dropped, so the row falls back to following the one
        // above it rather than jumping to Day 1.
        foreach ($byKey as $key => $row) {
            if ($row['depends_on'] !== null && !isset($byKey[$row['depends_on']])) {
                $byKey[$key]['depends_on'] = null;
            }
        }

        [$rootKeys, $childKeysByParent] = $this->buildHierarchy($byKey);
        $this->assignPredecessors($byKey, $rootKeys, $childKeysByParent);

        $groups = [];
        foreach ($rootKeys as $rootKey) {
            $groups[] = array_merge([$rootKey], $childKeysByParent[$rootKey] ?? []);
        }

        $resolved = [];
        $pending = array_keys($groups);
        $guard = count($groups) + 1;

        // A group can only be laid down once everything it points at is placed.
        // Repeat until a full sweep places nothing new.
        while ($pending !== [] && $guard-- > 0) {
            $progressed = false;

            foreach ($pending as $index => $groupIndex) {
                if (!$this->groupIsReady($groups[$groupIndex], $byKey, $resolved)) {
                    continue;
                }

                $this->resolveGroup($groups[$groupIndex], $byKey, $resolved, $day1, false);
                unset($pending[$index]);
                $progressed = true;
            }

            if (!$progressed) {
                break;
            }
        }

        // Whatever is left is in a cycle or points at a row that no longer
        // exists — lay it down in plain list order so the plan still renders.
        foreach ($pending as $groupIndex) {
            $this->resolveGroup($groups[$groupIndex], $byKey, $resolved, $day1, true);
        }

        $out = [];
        foreach ($resolved as $key => $span) {
            $out[$key] = [
                'start' => $span['start']->toDateString(),
                'end' => $span['end']->toDateString(),
                'days' => $this->calculator->daysBetween($span['start'], $span['end']),
            ];
        }

        return $out;
    }

    private function normalize(array $row): array
    {
        $id = $this->key($row['id']);
        $dependsOn = isset($row['depends_on']) && $row['depends_on'] !== null && $row['depends_on'] !== ''
            ? $this->key($row['depends_on'])
            : null;

        return [
            'id' => $id,
            'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null && $row['parent_id'] !== ''
                ? $this->key($row['parent_id'])
                : null,
            // A row that points at itself would deadlock the resolver.
            'depends_on' => $dependsOn === $id ? null : $dependsOn,
            'parallel' => (bool) ($row['parallel'] ?? false),
            'days' => max(1, (int) ($row['days'] ?? 1)),
            'anchor' => ($row['anchor'] ?? null) ?: null,
            'milestone_order' => (int) ($row['milestone_order'] ?? 0),
            'order' => (float) ($row['order'] ?? 0),
            'predecessor' => null,
        ];
    }

    private function key(mixed $id): string
    {
        return (string) $id;
    }

    /**
     * @return array{0: array<int, string>, 1: array<string, array<int, string>>}
     */
    private function buildHierarchy(array &$byKey): array
    {
        $rootKeys = [];
        $childKeysByParent = [];

        foreach ($byKey as $key => $row) {
            // An orphan (parent deleted mid-request) is treated as a root rather
            // than silently dropped off the plan.
            if ($row['parent_id'] === null || !isset($byKey[$row['parent_id']])) {
                $byKey[$key]['parent_id'] = null;
                $rootKeys[] = $key;

                continue;
            }

            $childKeysByParent[$row['parent_id']][] = $key;
        }

        $rootKeys = $this->sortKeys($rootKeys, $byKey, true);

        foreach ($childKeysByParent as $parentKey => $keys) {
            $childKeysByParent[$parentKey] = $this->sortKeys($keys, $byKey, false);
        }

        return [$rootKeys, $childKeysByParent];
    }

    /** Roots order by (milestone_order, order, id); sub-tasks by (order, id). */
    private function sortKeys(array $keys, array $byKey, bool $withMilestone): array
    {
        usort($keys, function (string $a, string $b) use ($byKey, $withMilestone) {
            if ($withMilestone && $byKey[$a]['milestone_order'] !== $byKey[$b]['milestone_order']) {
                return $byKey[$a]['milestone_order'] <=> $byKey[$b]['milestone_order'];
            }

            if ($byKey[$a]['order'] !== $byKey[$b]['order']) {
                return $byKey[$a]['order'] <=> $byKey[$b]['order'];
            }

            return strnatcmp($a, $b);
        });

        return $keys;
    }

    /** The row each one queues behind: the previous root, or previous sibling. */
    private function assignPredecessors(array &$byKey, array $rootKeys, array $childKeysByParent): void
    {
        $previous = null;
        foreach ($rootKeys as $key) {
            $byKey[$key]['predecessor'] = $previous;
            $previous = $key;
        }

        foreach ($childKeysByParent as $keys) {
            $previous = null;
            foreach ($keys as $key) {
                $byKey[$key]['predecessor'] = $previous;
                $previous = $key;
            }
        }
    }

    /** Whether every row in $group has its outside-the-group inputs placed. */
    private function groupIsReady(array $group, array $byKey, array $resolved): bool
    {
        $inGroup = array_flip($group);

        foreach ($group as $key) {
            foreach ($this->requirements($byKey[$key]) as $needed) {
                if (isset($inGroup[$needed]) || isset($resolved[$needed])) {
                    continue;
                }

                // Points at a row that isn't in this plan at all — never
                // satisfiable, so don't let it stall the sweep.
                if (!isset($byKey[$needed])) {
                    continue;
                }

                return false;
            }
        }

        return true;
    }

    /**
     * The one row this one hangs off: its chosen requisite, or — when none is
     * set — the row above it.
     */
    private function dependencyOf(array $row): ?string
    {
        return $row['depends_on'] ?? $row['predecessor'];
    }

    /** Row ids that must be placed before this one can be. */
    private function requirements(array $row): array
    {
        $dependency = $this->dependencyOf($row);

        return $dependency !== null ? [$dependency] : [];
    }

    private function resolveGroup(array $group, array $byKey, array &$resolved, Carbon $day1, bool $force): void
    {
        $rootKey = $group[0];
        $childKeys = array_slice($group, 1);

        $rootStart = $this->startFor($byKey[$rootKey], $resolved, $day1);
        $rootEnd = $this->calculator->endOfSpan($rootStart, $byKey[$rootKey]['days']);
        $resolved[$rootKey] = ['start' => $rootStart, 'end' => $rootEnd];

        if ($childKeys === []) {
            return;
        }

        // Sub-tasks may point at a later sibling, so sweep them the same way.
        $pending = $childKeys;
        $guard = count($childKeys) + 1;

        while ($pending !== [] && $guard-- > 0) {
            $progressed = false;

            foreach ($pending as $index => $childKey) {
                if (!$force && !$this->rowIsReady($byKey[$childKey], $byKey, $resolved)) {
                    continue;
                }

                $this->resolveChild($byKey[$childKey], $resolved, $rootStart);
                unset($pending[$index]);
                $progressed = true;
            }

            if (!$progressed) {
                break;
            }
        }

        foreach ($pending as $childKey) {
            $this->resolveChild($byKey[$childKey], $resolved, $rootStart);
        }

        // The parent owns no span of its own — its bar is exactly the stretch of
        // its sub-tasks, however they overlap.
        $start = null;
        $end = null;

        foreach ($childKeys as $childKey) {
            $span = $resolved[$childKey];
            $start = ($start === null || $span['start']->lt($start)) ? $span['start']->copy() : $start;
            $end = ($end === null || $span['end']->gt($end)) ? $span['end']->copy() : $end;
        }

        $resolved[$rootKey] = ['start' => $start ?? $rootStart, 'end' => $end ?? $rootEnd];
    }

    private function resolveChild(array $child, array &$resolved, Carbon $parentStart): void
    {
        $start = $this->startFor($child, $resolved, $parentStart);
        $resolved[$child['id']] = [
            'start' => $start,
            'end' => $this->calculator->endOfSpan($start, $child['days']),
        ];
    }

    private function rowIsReady(array $row, array $byKey, array $resolved): bool
    {
        foreach ($this->requirements($row) as $needed) {
            if (!isset($resolved[$needed]) && isset($byKey[$needed])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where a row begins. A hand-pinned Start Date always wins; otherwise the
     * dependency decides — the day after it finishes, or its very own start date
     * when the row may run in parallel — falling back to $fallback (Day 1 for a
     * root, the parent's start for a first sub-task).
     */
    private function startFor(array $row, array $resolved, Carbon $fallback): Carbon
    {
        if ($row['anchor']) {
            return $this->calculator->toWorkingDay(Carbon::parse($row['anchor'])->startOfDay());
        }

        $dependencyKey = $this->dependencyOf($row);
        $dependency = $dependencyKey !== null ? ($resolved[$dependencyKey] ?? null) : null;

        if (!$dependency) {
            return $this->calculator->toWorkingDay($fallback->copy());
        }

        return $row['parallel']
            ? $this->calculator->toWorkingDay($dependency['start']->copy())
            : $this->calculator->nextDay($dependency['end']);
    }
}
