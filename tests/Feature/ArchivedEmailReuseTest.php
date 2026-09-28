<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * An archive parks a login's address in `archived_email` and tombstones
 * `email`, so `unique:users` no longer sees it. Every path that creates a login
 * or changes its address must still refuse it, or the address goes to someone
 * new and a later restore cannot reclaim it. Mobile sign-up is covered in
 * `Api\RegisterControllerTest`.
 *
 * Archived rows are inserted already archived — nothing here archives anything.
 */
class ArchivedEmailReuseTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = 'closed@example.com';

    private const STAFF_MESSAGE = 'That email address belongs to "Closed Member", an archived account. Restore or purge it from Settings → Account Archive first.';

    private const SELF_SERVICE_MESSAGE = 'This email address belongs to an archived account. Please contact the administrator to restore it.';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function archivedLogin(string $address = self::ADDRESS, ?string $employeeId = null): User
    {
        return User::factory()->create([
            'name' => 'Closed Member',
            'email' => 'deleted-901@archived.invalid',
            'archived_email' => $address,
            'employee_id_no' => $employeeId,
            'deleted_at' => now(),
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        $admin->givePermissionTo(Permission::findOrCreate('users.create', 'web'));

        return $admin;
    }

    private function updatePayload(User $user, array $overrides = []): array
    {
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);

        return array_merge([
            'name' => $user->name,
            'employee_id_no' => $user->employee_id_no,
            'email' => $user->email,
            'role' => 'Employee',
            'is_active' => true,
            'is_manager' => false,
            'store_ids' => [],
            'manager_ids' => [],
        ], $overrides);
    }

    public function test_staff_cannot_create_a_login_with_an_archived_address(): void
    {
        $this->archivedLogin();
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);

        $this->actingAs($this->admin())->post(route('users.store'), [
            'name' => 'Someone New',
            'employee_id_no' => 'EMP-NEW-1',
            'email' => self::ADDRESS,
            'password' => 'password123',
            'role' => 'Employee',
        ])->assertSessionHasErrors(['email' => self::STAFF_MESSAGE]);

        $this->assertSame(0, User::where('email', self::ADDRESS)->count());
    }

    public function test_staff_cannot_move_a_login_onto_an_archived_address(): void
    {
        $this->archivedLogin();
        $user = User::factory()->create(['email' => 'live@example.com', 'employee_id_no' => 'EMP-LIVE-1']);

        $this->actingAs($this->admin())
            ->put(route('users.update', $user), $this->updatePayload($user, ['email' => 'Closed@Example.com']))
            ->assertSessionHasErrors(['email' => self::STAFF_MESSAGE]);

        $this->assertSame('live@example.com', $user->fresh()->email);
    }

    public function test_an_unchanged_address_that_matches_an_archive_still_saves(): void
    {
        // The review account re-registering after deletion leaves exactly this:
        // a live login and an archived one sharing an address.
        $this->archivedLogin();
        $user = User::factory()->create(['email' => self::ADDRESS, 'employee_id_no' => 'EMP-LIVE-2']);

        $this->actingAs($this->admin())
            ->put(route('users.update', $user), $this->updatePayload($user, ['name' => 'Renamed Member']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Member', $user->fresh()->name);
    }

    public function test_import_skips_a_row_with_an_archived_address(): void
    {
        $this->archivedLogin();
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $file = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,employee_id_no,email,role,department,position,date_hired,is_manager,is_active,assigned_stores,reports_to\n"
            ."Closed Member,EMP-IMP-1,Closed@Example.com,Employee,,,,No,Yes,,\n"
            ."New Person,EMP-IMP-2,new.person@example.com,Employee,,,,No,Yes,,\n"
        );

        $this->actingAs($this->admin())->post(route('users.import'), ['file' => $file])
            ->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('errors.0', "Row 2: email 'Closed@Example.com' belongs to an archived account — restore or purge it from Settings → Account Archive first. Skipped.");

        $this->assertDatabaseMissing('users', ['email' => 'Closed@Example.com']);
        $this->assertDatabaseHas('users', ['email' => 'new.person@example.com']);
    }

    public function test_import_skips_a_row_with_an_archived_employee_id_instead_of_failing(): void
    {
        $this->archivedLogin('other@example.com', 'EMP-ARCHIVED');
        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        $file = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,employee_id_no,email,role,department,position,date_hired,is_manager,is_active,assigned_stores,reports_to\n"
            ."Reused Id,EMP-ARCHIVED,reused.id@example.com,Employee,,,,No,Yes,,\n"
        );

        $this->actingAs($this->admin())->post(route('users.import'), ['file' => $file])
            ->assertOk()
            ->assertJsonPath('imported', 0)
            ->assertJsonCount(1, 'errors');

        $this->assertDatabaseMissing('users', ['email' => 'reused.id@example.com']);
    }

    public function test_google_sign_in_does_not_bring_an_archived_account_back_as_a_new_one(): void
    {
        $this->archivedLogin();
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
        $googleUser = Mockery::mock();
        $googleUser->shouldReceive('getId')->andReturn('google-closed');
        $googleUser->shouldReceive('getName')->andReturn('Closed Member');
        $googleUser->shouldReceive('getEmail')->andReturn(self::ADDRESS);
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', self::SELF_SERVICE_MESSAGE);

        $this->assertSame(0, User::where('email', self::ADDRESS)->count());
        $this->assertGuest();
    }

    public function test_web_registration_refuses_an_archived_address(): void
    {
        $this->archivedLogin();

        $this->post('/register', [
            'name' => 'Closed Member',
            'email' => self::ADDRESS,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors(['email' => self::SELF_SERVICE_MESSAGE]);

        $this->assertGuest();
        $this->assertSame(0, User::where('email', self::ADDRESS)->count());
    }
}
