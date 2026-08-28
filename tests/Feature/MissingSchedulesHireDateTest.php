<?php

namespace Tests\Feature;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Nobody can be missing a schedule for a day before they were hired, so the
 * Missing Schedules screen (and its export) floors each person's date range at
 * their `date_hired` from /users.
 */
class MissingSchedulesHireDateTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->givePermissionTo(Permission::findOrCreate('schedules.view', 'web'));

        return $user;
    }

    public function test_days_before_the_hire_date_are_not_reported_as_missing(): void
    {
        $user = $this->viewer(['date_hired' => '2026-08-15']);

        $this->actingAs($user);

        $missing = $this->missingDaysFor($user, '2026-08-01', '2026-08-31');

        $this->assertNotContains('Aug 1', $missing);
        $this->assertNotContains('Aug 14', $missing);
        $this->assertContains('Aug 15', $missing);
        $this->assertContains('Aug 31', $missing);
        $this->assertCount(17, $missing, 'Aug 15-31 inclusive');
    }

    public function test_the_hire_date_itself_still_counts_as_missing(): void
    {
        $user = User::factory()->create(['date_hired' => '2026-08-20']);

        $this->assertTrue($user->wasEmployedOn('2026-08-20'));
        $this->assertFalse($user->wasEmployedOn('2026-08-19'));
    }

    public function test_a_user_without_a_hire_date_is_reported_for_the_whole_range(): void
    {
        // We cannot invent a start date, so swallowing their gaps would be a worse
        // failure than showing days that predate them.
        $user = $this->viewer(['date_hired' => null]);

        $this->actingAs($user);

        $missing = $this->missingDaysFor($user, '2026-08-01', '2026-08-31');

        $this->assertCount(31, $missing);
        $this->assertContains('Aug 1', $missing);
    }

    public function test_a_user_hired_after_the_whole_range_drops_off_the_report(): void
    {
        $user = $this->viewer(['date_hired' => '2026-09-10']);

        $this->actingAs($user);

        $response = $this->getJson(route('schedules.missing-schedules', [
            'start' => '2026-08-01',
            'end' => '2026-08-31',
            'user_id' => $user->id,
        ]));

        $response->assertOk();
        $this->assertSame([], $response->json('data'), 'A person not yet hired has no gaps to report.');
    }

    public function test_the_count_column_reflects_the_floored_range(): void
    {
        $user = $this->viewer(['date_hired' => '2026-08-25']);

        $this->actingAs($user);

        $row = $this->rowFor($user, '2026-08-01', '2026-08-31');

        $this->assertSame(7, $row['missing_days_count'], 'Aug 25-31 inclusive');
        $this->assertSame(7, $row['missing_total_count']);
    }

    /**
     * The exported PDF builds its rows in a second, duplicated copy of the same
     * logic, so it gets its own check — a fix that lands only on the screen would
     * leave the export contradicting it.
     */
    public function test_the_export_applies_the_same_hire_date_floor(): void
    {
        $user = $this->viewer(['date_hired' => '2026-08-25']);

        $this->actingAs($user);

        $exported = null;
        View::composer('pdf.missing-schedules', function ($view) use (&$exported) {
            $exported = $view->getData()['users'];
        });

        $this->get(route('schedules.export.pdf', [
            'view' => 'missing-schedules',
            'start' => '2026-08-01',
            'end' => '2026-08-31',
            'user_id' => $user->id,
        ]))->assertOk();

        $this->assertNotNull($exported, 'The missing-schedules PDF view was never rendered.');
        $this->assertCount(1, $exported);
        $this->assertSame(7, $exported[0]->missing_days_count, 'Aug 25-31 inclusive');
        $this->assertNotContains('Aug 1', $exported[0]->missing_days);
    }

    /** @return array<int, string> */
    private function missingDaysFor(User $user, string $start, string $end): array
    {
        return $this->rowFor($user, $start, $end)['missing_days'];
    }

    private function rowFor(User $user, string $start, string $end): array
    {
        $response = $this->getJson(route('schedules.missing-schedules', [
            'start' => $start,
            'end' => $end,
            'user_id' => $user->id,
        ]));

        $response->assertOk();

        $rows = $response->json('data');
        $this->assertNotEmpty($rows, 'Expected the user to appear on the missing report.');

        return $rows[0];
    }
}
