<?php

namespace Tests\Feature;

use App\Services\HolidayCalendar;
use App\Services\ScheduleCalculator;
use App\Services\ScheduleChain;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dependency-driven chain, checked against the day numbers on the reference
 * WBS sheet. Day 1 is pinned to a Monday and the tests run in calendar mode so a
 * "day number" is a plain offset — weekend skipping is exercised separately.
 */
class ScheduleChainTest extends TestCase
{
    use RefreshDatabase;

    private const DAY1 = '2026-08-03'; // a Monday

    private function chain(string $mode = ScheduleCalculator::MODE_CALENDAR): ScheduleChain
    {
        return new ScheduleChain(new ScheduleCalculator(app(HolidayCalendar::class), $mode));
    }

    /** Turns a resolved date back into the sheet's "day N" numbering. */
    private function dayNumber(string $date): int
    {
        return Carbon::parse(self::DAY1)->diffInDays(Carbon::parse($date)) + 1;
    }

    private function row(array $overrides): array
    {
        return array_merge([
            'id' => null,
            'parent_id' => null,
            'depends_on' => null,
            'parallel' => false,
            'days' => 1,
            'anchor' => null,
            'milestone_order' => 1,
            'order' => 1,
        ], $overrides);
    }

    public function test_rows_chain_back_to_back_when_nothing_is_parallel(): void
    {
        $schedule = $this->chain()->resolve([
            $this->row(['id' => 1, 'days' => 10, 'order' => 1]),
            $this->row(['id' => 2, 'days' => 5, 'order' => 2, 'depends_on' => 1]),
            $this->row(['id' => 3, 'days' => 10, 'order' => 3, 'depends_on' => 2]),
        ], self::DAY1);

        // Lead time is counted AFTER the start date (the confirmed rule), so a
        // 10-day row starting on day 1 ends on day 11 and the next starts day 12.
        $this->assertSame(1, $this->dayNumber($schedule['1']['start']));
        $this->assertSame(11, $this->dayNumber($schedule['1']['end']));
        $this->assertSame(12, $this->dayNumber($schedule['2']['start']));
        $this->assertSame(17, $this->dayNumber($schedule['2']['end']));
        $this->assertSame(18, $this->dayNumber($schedule['3']['start']));
    }

    /**
     * Sheet rows 1.6 and 1.7: both flagged Yes, both pointing at 1.5, so both
     * start together the day after 1.5 finishes instead of queueing.
     */
    public function test_two_parallel_rows_sharing_a_requisite_start_together(): void
    {
        $schedule = $this->chain()->resolve([
            $this->row(['id' => 5, 'days' => 5, 'order' => 5]),
            $this->row(['id' => 6, 'days' => 5, 'order' => 6, 'depends_on' => 5, 'parallel' => true]),
            $this->row(['id' => 7, 'days' => 5, 'order' => 7, 'depends_on' => 5, 'parallel' => true]),
        ], self::DAY1);

        $this->assertSame($schedule['6']['start'], $schedule['7']['start']);
        $this->assertSame($schedule['6']['end'], $schedule['7']['end']);
        $this->assertSame(
            Carbon::parse($schedule['5']['end'])->addDay()->toDateString(),
            $schedule['6']['start']
        );
    }

    /**
     * Sheet row 3.1: a lone Yes that reaches back to a requisite in an earlier
     * milestone and therefore starts long BEFORE the row printed above it
     * finishes. This is the case no adjacency rule can produce.
     */
    public function test_a_parallel_row_starts_before_the_row_above_it_finishes(): void
    {
        $schedule = $this->chain()->resolve([
            // The requisite, finishing early.
            $this->row(['id' => 27, 'days' => 5, 'milestone_order' => 2, 'order' => 7]),
            // A long row sitting between them in the list.
            $this->row(['id' => 216, 'days' => 60, 'milestone_order' => 2, 'order' => 16, 'depends_on' => 27]),
            // Points back at 27 and is allowed to overlap 216.
            $this->row(['id' => 31, 'days' => 10, 'milestone_order' => 3, 'order' => 1, 'depends_on' => 27, 'parallel' => true]),
            // Queues behind 31 as usual.
            $this->row(['id' => 32, 'days' => 10, 'milestone_order' => 3, 'order' => 2, 'depends_on' => 31]),
        ], self::DAY1);

        $this->assertSame(
            Carbon::parse($schedule['27']['end'])->addDay()->toDateString(),
            $schedule['31']['start'],
            'A parallel row must start off its requisite, ignoring the queue.'
        );

        $this->assertTrue(
            Carbon::parse($schedule['31']['start'])->lt(Carbon::parse($schedule['216']['end'])),
            'Row 3.1 overlaps the long row above it rather than waiting for it.'
        );

        $this->assertSame(
            Carbon::parse($schedule['31']['end'])->addDay()->toDateString(),
            $schedule['32']['start'],
            'The row after a parallel row still queues behind it.'
        );
    }

    /** A No row waits for BOTH its requisite and the row ahead of it. */
    public function test_a_sequential_row_waits_for_the_later_of_requisite_and_predecessor(): void
    {
        $schedule = $this->chain()->resolve([
            $this->row(['id' => 1, 'days' => 5, 'order' => 1]),
            // Long row in the queue ahead of 3.
            $this->row(['id' => 2, 'days' => 40, 'order' => 2, 'depends_on' => 1]),
            // Requisite finishes early, but the queue is what holds it back.
            $this->row(['id' => 3, 'days' => 5, 'order' => 3, 'depends_on' => 1]),
        ], self::DAY1);

        $this->assertSame(
            Carbon::parse($schedule['2']['end'])->addDay()->toDateString(),
            $schedule['3']['start']
        );
    }

    /** With no requisite to anchor to, Yes means "run alongside the row above". */
    public function test_a_parallel_row_without_a_requisite_shares_the_previous_start(): void
    {
        $schedule = $this->chain()->resolve([
            $this->row(['id' => 1, 'days' => 5, 'order' => 1]),
            $this->row(['id' => 2, 'days' => 3, 'order' => 2, 'parallel' => true]),
        ], self::DAY1);

        $this->assertSame($schedule['1']['start'], $schedule['2']['start']);
    }

    /** Sub-tasks run inside the parent, and the parent's bar covers them all. */
    public function test_parent_span_covers_parallel_sub_tasks(): void
    {
        $schedule = $this->chain()->resolve([
            $this->row(['id' => 1, 'days' => 1, 'order' => 1]),
            $this->row(['id' => 11, 'parent_id' => 1, 'days' => 3, 'order' => 1]),
            $this->row(['id' => 12, 'parent_id' => 1, 'days' => 8, 'order' => 2, 'parallel' => true]),
            $this->row(['id' => 2, 'days' => 5, 'order' => 2, 'depends_on' => 1]),
        ], self::DAY1);

        // Both sub-tasks start on the parent's start; the parent ends with the
        // longer of the two, not with their sum.
        $this->assertSame($schedule['1']['start'], $schedule['11']['start']);
        $this->assertSame($schedule['1']['start'], $schedule['12']['start']);
        $this->assertSame($schedule['12']['end'], $schedule['1']['end']);

        // The next activity queues behind the parent's stretched bar.
        $this->assertSame(
            Carbon::parse($schedule['1']['end'])->addDay()->toDateString(),
            $schedule['2']['start']
        );
    }

    /** Working mode skips the weekend; calendar mode runs straight through. */
    public function test_day_mode_changes_where_a_span_lands(): void
    {
        $rows = [$this->row(['id' => 1, 'days' => 4])];

        // Wed 29 Jul + 4 working days = Tue 4 Aug (the confirmed reference case).
        $working = $this->chain(ScheduleCalculator::MODE_WORKING)->resolve($rows, '2026-07-29');
        $this->assertSame('2026-08-04', $working['1']['end']);

        // The same 4 days counted straight through land on Sun 2 Aug.
        $calendar = $this->chain(ScheduleCalculator::MODE_CALENDAR)->resolve($rows, '2026-07-29');
        $this->assertSame('2026-08-02', $calendar['1']['end']);
    }

    /** A pinned Start Date beats both the requisite and the queue. */
    public function test_an_anchor_pins_a_row_regardless_of_the_chain(): void
    {
        $schedule = $this->chain()->resolve([
            $this->row(['id' => 1, 'days' => 10, 'order' => 1]),
            $this->row(['id' => 2, 'days' => 5, 'order' => 2, 'depends_on' => 1, 'anchor' => '2026-09-15']),
        ], self::DAY1);

        $this->assertSame('2026-09-15', $schedule['2']['start']);
    }

    /** A dependency cycle must degrade to list order, not hang the request. */
    public function test_a_dependency_cycle_falls_back_to_sequential_order(): void
    {
        $schedule = $this->chain()->resolve([
            $this->row(['id' => 1, 'days' => 5, 'order' => 1, 'depends_on' => 2]),
            $this->row(['id' => 2, 'days' => 5, 'order' => 2, 'depends_on' => 1]),
        ], self::DAY1);

        $this->assertCount(2, $schedule);
        $this->assertNotEmpty($schedule['1']['start']);
        $this->assertNotEmpty($schedule['2']['start']);
    }

    /** A requisite pointing at a deleted row is ignored, not fatal. */
    public function test_a_dangling_requisite_is_ignored(): void
    {
        $schedule = $this->chain()->resolve([
            $this->row(['id' => 1, 'days' => 5, 'order' => 1]),
            $this->row(['id' => 2, 'days' => 5, 'order' => 2, 'depends_on' => 999]),
        ], self::DAY1);

        $this->assertSame(
            Carbon::parse($schedule['1']['end'])->addDay()->toDateString(),
            $schedule['2']['start']
        );
    }
}
