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
