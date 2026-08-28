<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use App\Services\DepartmentMailRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Saving the per-department inbound addresses from Settings.
 *
 * The shared support mailbox used to be refused here. That looked prudent and was
 * a trap: with "require a department address" switched on, mail to the address
 * every requester actually knows was answered with the directory notice instead
 * of becoming a ticket, and there was no way to say "this desk owns that mail".
 */
class DepartmentMailboxSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('settings.edit');
        $user->givePermissionTo('settings.edit');

        return $user;
    }

    private function department(string $name, string $code): Department
    {
        return Department::create(['name' => $name, 'code' => $code, 'is_active' => true]);
    }

    public function test_a_department_may_claim_the_shared_support_mailbox(): void
    {
        Setting::set('imap_username', 'support@example.test', 'email');
        $tas = $this->department('Technology and Solutions', 'TAS');

        $response = $this->actingAs($this->editor())->put(route('settings.update'), [
            'imap_username' => 'support@example.test',
            'mailboxes' => [
                ['id' => $tas->id, 'mail_address' => 'Support@Example.test', 'mail_from_name' => 'TAS Service Center'],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        // Normalised on the way in, so inbound header matching stays case-insensitive.
        $this->assertSame('support@example.test', $tas->fresh()->mail_address);

        // And it routes: the base address now names a department rather than the pool.
        $this->assertSame(
            ['matched' => true, 'department_id' => $tas->id],
            app(DepartmentMailRouter::class)->resolve(['support@example.test'])
        );
    }

    public function test_two_departments_still_cannot_share_one_address(): void
    {
        Setting::set('imap_username', 'support@example.test', 'email');
        $tas = $this->department('Technology and Solutions', 'TAS');
        $scm = $this->department('Supply Chain Management', 'SCM');

        $response = $this->actingAs($this->editor())->put(route('settings.update'), [
            'imap_username' => 'support@example.test',
            'mailboxes' => [
                ['id' => $tas->id, 'mail_address' => 'support@example.test'],
                ['id' => $scm->id, 'mail_address' => 'support@example.test'],
            ],
        ]);

        $response->assertSessionHasErrors('mailboxes');
        $this->assertNull($tas->fresh()->mail_address);
        $this->assertNull($scm->fresh()->mail_address);
    }

    public function test_the_routing_cache_is_flushed_after_a_save(): void
    {
        Setting::set('imap_username', 'support@example.test', 'email');
        $tas = $this->department('Technology and Solutions', 'TAS');

        // Warm the cache with the pre-save answer.
        $this->assertSame(['matched' => false, 'department_id' => null], app(DepartmentMailRouter::class)->resolve(['tas@example.test']));

        $this->actingAs($this->editor())->put(route('settings.update'), [
            'imap_username' => 'support@example.test',
            'mailboxes' => [
                ['id' => $tas->id, 'mail_address' => 'tas@example.test'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['matched' => true, 'department_id' => $tas->id],
            app(DepartmentMailRouter::class)->resolve(['tas@example.test'])
        );
    }
}
