<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A user deactivated on /users drops off /schedules entirely: not just from the people list
 * (already filtered) but also their schedule entries, which used to keep them on the calendar.
 */
class ScheduleInactiveUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedules_page_hides_the_schedules_of_inactive_users(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'schedules.view']);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('schedules.view');
        $active = User::factory()->create(['is_active' => true, 'name' => 'Active Person']);
        $inactive = User::factory()->create(['is_active' => false, 'name' => 'Inactive Person']);

        $kept = $this->scheduleFor($active);
        $hidden = $this->scheduleFor($inactive);

        $this->actingAs($viewer)
            ->get(route('schedules.index', ['start' => '2026-05-01', 'end' => '2026-05-31']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Schedules/Index')
                ->has('schedules', 1)
                ->where('schedules.0.id', $kept->id)
                ->where('users', fn ($users) => collect($users)->pluck('id')->doesntContain($inactive->id))
            );

        // The record itself is untouched; it only stops showing.
        $this->assertTrue(Schedule::whereKey($hidden->id)->exists());
        $this->assertSame([$kept->id], Schedule::ofActiveUsers()->pluck('id')->all());
    }

    private function scheduleFor(User $user): Schedule
    {
        return Schedule::create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'status' => 'WFH',
            'start_time' => '2026-05-10 07:00:00',
            'end_time' => '2026-05-10 17:00:00',
        ]);
    }
}
