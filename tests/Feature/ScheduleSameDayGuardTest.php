<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A person can only be in one place at a time, so /schedules allows exactly one
 * schedule per user per calendar day. These cover the guard on every write path.
 */
class ScheduleSameDayGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'schedules.view']);
        Permission::firstOrCreate(['name' => 'schedules.edit']);
        Permission::firstOrCreate(['name' => 'schedules.create']);
    }

    public function test_second_schedule_on_the_same_day_is_rejected_even_when_the_hours_do_not_overlap(): void
    {
        [$actor, $store, $home] = $this->fixture();

        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'On-site', $store, '2026-08-10T07:00', '2026-08-10T12:00'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Same day, later hours, different status/location — still the same person
        // on the same date, which is what the duplicate rows on the board were.
        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'WFH', $home, '2026-08-10T13:00', '2026-08-10T17:00'))
            ->assertSessionHasErrors('stores');

        $this->assertSame(1, Schedule::where('user_id', $actor->id)->count());
    }

    public function test_second_schedule_with_the_same_hours_is_rejected(): void
    {
        [$actor, $store, $home] = $this->fixture();

        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'On-site', $store, '2026-08-10T07:00', '2026-08-10T17:00'))
            ->assertSessionHasNoErrors();

        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'WFH', $home, '2026-08-10T07:00', '2026-08-10T17:00'))
            ->assertSessionHasErrors('stores');

        $this->assertSame(1, Schedule::where('user_id', $actor->id)->count());
    }

    public function test_a_different_day_is_still_allowed(): void
    {
        [$actor, $store, $home] = $this->fixture();

        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'On-site', $store, '2026-08-10T07:00', '2026-08-10T17:00'))
            ->assertSessionHasNoErrors();

        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'WFH', $home, '2026-08-11T07:00', '2026-08-11T17:00'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Schedule::where('user_id', $actor->id)->count());
    }

    /** Editing the one schedule that owns the day must not trip its own guard. */
    public function test_editing_the_only_schedule_of_the_day_is_allowed(): void
    {
        [$actor, $store, $home] = $this->fixture();

        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'On-site', $store, '2026-08-10T07:00', '2026-08-10T17:00'))
            ->assertSessionHasNoErrors();

        $schedule = Schedule::where('user_id', $actor->id)->firstOrFail();

        $this->actingAs($actor)
            ->put("/schedules/{$schedule->id}", $this->payload($actor, 'WFH', $home, '2026-08-10T08:00', '2026-08-10T16:00'))
            ->assertSessionHasNoErrors();

        $this->assertSame('WFH', $schedule->fresh()->status);
    }

    /**
     * A Paris 9-to-6 in winter is 16:00 → 01:00 in Manila, crossing Manila midnight.
     * Counted in Manila days, two consecutive workdays would clash; counted in the
     * owner's own zone they are two separate days. Payloads are Manila wall time,
     * exactly what the page sends after converting the user's typed times.
     */
    public function test_consecutive_workdays_abroad_do_not_clash_in_the_owners_timezone(): void
    {
        [$actor, $store] = $this->fixture();
        $actor->update(['timezone' => 'Europe/Paris']);

        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'On-site', $store, '2026-12-01T16:00', '2026-12-02T01:00'))
            ->assertSessionHasNoErrors();

        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'On-site', $store, '2026-12-02T16:00', '2026-12-03T01:00'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Schedule::where('user_id', $actor->id)->count());

        // A second schedule on the same Paris day is still refused.
        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'On-site', $store, '2026-12-02T20:00', '2026-12-02T23:00'))
            ->assertSessionHasErrors('stores');
    }

    /** Without a saved timezone the original Manila-day rule is unchanged. */
    public function test_the_same_hours_still_clash_for_a_manila_user(): void
    {
        [$actor, $store] = $this->fixture();

        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'On-site', $store, '2026-12-01T16:00', '2026-12-02T01:00'))
            ->assertSessionHasNoErrors();

        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'On-site', $store, '2026-12-02T16:00', '2026-12-03T01:00'))
            ->assertSessionHasErrors('stores');
    }

    /** A multi-day range typed abroad becomes one row per day of the owner's zone. */
    public function test_multi_day_entry_is_split_on_the_owners_days(): void
    {
        [$actor, $store] = $this->fixture();
        $actor->update(['timezone' => 'Europe/Paris']);

        // Paris Dec 1–3, 09:00–18:00 = Manila Dec 1 16:00 → Dec 4 01:00.
        $this->actingAs($actor)
            ->post('/schedules', $this->payload($actor, 'On-site', $store, '2026-12-01T16:00', '2026-12-04T01:00'))
            ->assertSessionHasNoErrors();

        $rows = \App\Models\ScheduleStore::orderBy('start_time')->get();

        $this->assertCount(3, $rows);
        $this->assertSame('2026-12-01 16:00', $rows[0]->start_time->format('Y-m-d H:i'));
        $this->assertSame('2026-12-02 01:00', $rows[0]->end_time->format('Y-m-d H:i'));
        $this->assertSame('2026-12-03 16:00', $rows[2]->start_time->format('Y-m-d H:i'));
        $this->assertSame('2026-12-04 01:00', $rows[2]->end_time->format('Y-m-d H:i'));
    }

    public function test_user_can_save_and_reset_their_timezone(): void
    {
        [$actor] = $this->fixture();

        $this->actingAs($actor)
            ->patch('/profile/timezone', ['timezone' => 'America/Los_Angeles'])
            ->assertSessionHasNoErrors();
        $this->assertSame('America/Los_Angeles', $actor->fresh()->timezone);

        $this->actingAs($actor)
            ->patch('/profile/timezone', ['timezone' => 'Mars/Olympus_Mons'])
            ->assertSessionHasErrors('timezone');
        $this->assertSame('America/Los_Angeles', $actor->fresh()->timezone);

        // Choosing Manila stores NULL — the company default.
        $this->actingAs($actor)
            ->patch('/profile/timezone', ['timezone' => 'Asia/Manila'])
            ->assertSessionHasNoErrors();
        $this->assertNull($actor->fresh()->timezone);
    }

    /** @return array{0: User, 1: Store, 2: Store} */
    private function fixture(): array
    {
        $actor = User::factory()->create(['is_manager' => true, 'is_active' => true, 'is_vacant' => false]);
        $actor->givePermissionTo('schedules.view');
        $actor->givePermissionTo('schedules.edit');
        $actor->givePermissionTo('schedules.create');

        return [$actor, $this->store('OPUS', 'Opus'), $this->store('HOME', 'Home')];
    }

    private function store(string $code, string $name): Store
    {
        return Store::create([
            'code' => $code,
            'name' => $name,
            'sector' => 1,
            'area' => 'Metro',
            'brand' => 'Brand',
            'cluster' => 'Cluster',
            'is_active' => true,
        ]);
    }

    private function payload(User $user, string $status, Store $store, string $start, string $end): array
    {
        return [
            'user_id' => $user->id,
            'status' => $status,
            'stores' => [[
                'store_id' => $store->id,
                'ticket_id' => null,
                'start_time' => $start,
                'end_time' => $end,
                'grace_period_minutes' => 30,
                'remarks' => null,
            ]],
            'pickup_start' => null,
            'pickup_end' => null,
            'backlogs_start' => null,
            'backlogs_end' => null,
        ];
    }
}
