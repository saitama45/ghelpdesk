<?php

namespace App\Services;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the weekly milestone-completion trend shown on the Projects dashboard tab.
 *
 * The projects table stores only PLANNED milestone dates (there is no recorded
 * "actual" date), so a true actual-vs-target on-time rate is not derivable. What is
 * derivable — retroactively, with no snapshot table — is a completion rate: a
 * milestone counts as reached in week W when its date falls on or before W's end.
 */
class ProjectDashboardService
{
    /** Milestone date column => display label, in lifecycle order. */
    public const MILESTONES = [
        'turn_over_date'               => 'Turn Over',
        'training_date'                => 'Training',
        'testing_date'                 => 'Testing',
        'mock_service_date'            => 'Mock Service',
        'turn_over_to_franchisee_date' => 'Turn Over to Franchisee',
        'target_go_live'               => 'Go Live',
    ];

    /**
     * Categorical slots 1-6 from the validated palette. Colour follows the milestone,
     * never its rank, so a filter that drops a series never repaints the survivors.
     */
    private const COLORS = [
        'turn_over_date'               => ['light' => '#2a78d6', 'dark' => '#3987e5'],
        'training_date'                => ['light' => '#1baf7a', 'dark' => '#199e70'],
        'testing_date'                 => ['light' => '#eda100', 'dark' => '#c98500'],
        'mock_service_date'            => ['light' => '#008300', 'dark' => '#008300'],
        'turn_over_to_franchisee_date' => ['light' => '#4a3aa7', 'dark' => '#9085e9'],
        'target_go_live'               => ['light' => '#e34948', 'dark' => '#e66767'],
    ];

    /** @param string[] $types Project types to include; empty means all types. */
    public function build(array $types, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end   = CarbonImmutable::parse($to)->endOfDay();

        if ($end->lessThan($start)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        $weeks    = $this->buildWeeks($start, $end);
        $projects = $this->fetchProjects($types);

        return [
            'weeks'    => $weeks,
            'series'   => $this->buildMilestoneSeries($projects, $weeks),
            'overall'  => $this->buildOverallSeries($projects, $weeks),
            'projects' => $this->buildProjectSeries($projects, $weeks),
            'summary'  => [
                'from'          => $start->toDateString(),
                'to'            => $end->toDateString(),
                'project_count' => $projects->count(),
                'type_count'    => $projects->pluck('project_type')->filter()->unique()->count(),
                'overall_rate'  => $this->overallRateAt($projects, $end),
            ],
        ];
    }

    /** ISO weeks (Mon-Sun) intersecting the range, with the first and last clipped to it. */
    private function buildWeeks(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $weeks  = [];
        $cursor = $start->startOfWeek();

        while ($cursor->lessThanOrEqualTo($end)) {
            $weekStart = $cursor->greaterThan($start) ? $cursor : $start;
            $weekEnd   = $cursor->endOfWeek();
            $weekEnd   = $weekEnd->greaterThan($end) ? $end : $weekEnd;

            $weeks[] = [
                'label' => $weekStart->format('M j') . '-' . $weekEnd->format(
                    $weekStart->month === $weekEnd->month ? 'j' : 'M j'
                ),
                'start' => $weekStart->toDateString(),
                'end'   => $weekEnd->endOfDay()->toDateTimeString(),
            ];

            $cursor = $cursor->addWeek()->startOfWeek();
        }

        return $weeks;
    }

    private function fetchProjects(array $types): Collection
    {
        return Project::query()
            ->when(! empty($types), fn ($q) => $q->whereIn('project_type', $types))
            ->with(['store:id,name', 'subject'])
            ->get(array_merge(
                ['id', 'name', 'project_type', 'store_id', 'subject_type', 'subject_id'],
                array_keys(self::MILESTONES)
            ))
            // A project with no milestone dates at all has no completion to plot.
            ->filter(fn (Project $p) => $this->definedMilestones($p) > 0)
            ->values();
    }

    private function definedMilestones(Project $project): int
    {
        return collect(array_keys(self::MILESTONES))
            ->filter(fn (string $column) => $project->{$column} !== null)
            ->count();
    }

    /** One line per milestone: % of projects that set it AND reached it by week end. */
    private function buildMilestoneSeries(Collection $projects, array $weeks): array
    {
        $series = [];

        foreach (self::MILESTONES as $column => $label) {
            $withDate = $projects->filter(fn (Project $p) => $p->{$column} !== null);

            $series[] = [
                'key'    => $column,
                'label'  => $label,
                'color'  => self::COLORS[$column]['light'],
                'dark'   => self::COLORS[$column]['dark'],
                'total'  => $withDate->count(),
                'values' => collect($weeks)->map(function (array $week) use ($withDate, $column) {
                    if ($withDate->isEmpty()) {
                        return null;
                    }

                    $cutoff  = CarbonImmutable::parse($week['end']);
                    $reached = $withDate->filter(
                        fn (Project $p) => CarbonImmutable::parse($p->{$column})->lessThanOrEqualTo($cutoff)
                    )->count();

                    return round($reached / $withDate->count() * 100, 2);
                })->all(),
            ];
        }

        return $series;
    }

    /** Weighted overall: every milestone reached, over every milestone set. */
    private function buildOverallSeries(Collection $projects, array $weeks): array
    {
        return [
            'key'    => 'overall',
            'label'  => 'Overall',
            'color'  => '#0b0b0b',
            'dark'   => '#ffffff',
            'values' => collect($weeks)
                ->map(fn (array $week) => $this->overallRateAt($projects, CarbonImmutable::parse($week['end'])))
                ->all(),
        ];
    }

    /** One line per project: its own milestones reached / milestones set. */
    private function buildProjectSeries(Collection $projects, array $weeks): array
    {
        return $projects->map(function (Project $project) use ($weeks) {
            $defined = $this->definedMilestones($project);

            return [
                'id'      => $project->id,
                'label'   => $project->name,
                // Secondary context only — the project name carries identity.
                'context' => $project->subject_label,
                'type'    => $project->project_type,
                'values'  => collect($weeks)->map(function (array $week) use ($project, $defined) {
                    $cutoff = CarbonImmutable::parse($week['end']);

                    return round($this->reachedBy($project, $cutoff) / $defined * 100, 2);
                })->all(),
            ];
        })->all();
    }

    private function reachedBy(Project $project, CarbonImmutable $cutoff): int
    {
        $reached = 0;

        foreach (array_keys(self::MILESTONES) as $column) {
            $date = $project->{$column};

            if ($date !== null && CarbonImmutable::parse($date)->lessThanOrEqualTo($cutoff)) {
                $reached++;
            }
        }

        return $reached;
    }

    private function overallRateAt(Collection $projects, CarbonImmutable $cutoff): ?float
    {
        $defined = 0;
        $reached = 0;

        foreach ($projects as $project) {
            $defined += $this->definedMilestones($project);
            $reached += $this->reachedBy($project, $cutoff);
        }

        return $defined > 0 ? round($reached / $defined * 100, 2) : null;
    }
}
