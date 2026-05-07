<?php

namespace Tests\Feature\Auth;

use App\Mail\GoogleRegistrationApproved;
use App\Mail\GoogleRegistrationPending;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PENDING_MESSAGE = 'Your Google registration was received. Please wait for an administrator to approve your account.';
    private const CONFIG_ERROR = 'Google sign-in is not configured yet. Please contact the administrator.';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_google_redirect_returns_to_login_when_config_is_missing(): void
    {
        config([
            'services.google.client_id' => '',
            'services.google.client_secret' => '',
            'services.google.redirect' => '',
        ]);

        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error', self::CONFIG_ERROR);
    }

    public function test_google_callback_creates_pending_no_role_user_and_notifies_admins(): void
    {
        Mail::fake();
        $this->configureGoogle();
        $admin = $this->createRegistrationNotificationRecipient();
        $this->mockGoogleUser('google-123', 'New Google User', 'new-google@example.com');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('info', self::PENDING_MESSAGE);
        $this->assertGuest();

        $user = User::where('email', 'new-google@example.com')->firstOrFail();
        $this->assertSame('google-123', $user->google_id);
        $this->assertFalse((bool) $user->is_active);
        $this->assertFalse($user->roles()->exists());

        Mail::assertSent(GoogleRegistrationPending::class, function (GoogleRegistrationPending $mail) use ($admin, $user) {
            return $mail->hasTo($admin->email) && $mail->user->is($user);
        });
    }

    public function test_google_callback_does_not_notify_roles_without_registration_toggle(): void
    {
        Mail::fake();
        $this->configureGoogle();
        $admin = $this->createAdmin();
        $this->mockGoogleUser('google-untoggled', 'Untoggled User', 'untoggled@example.com');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('info', self::PENDING_MESSAGE);
        $this->assertFalse($admin->roles()->where('notify_on_user_registration', true)->exists());
        Mail::assertNotSent(GoogleRegistrationPending::class);
    }

    public function test_pending_google_user_cannot_login_from_callback(): void
    {
        Mail::fake();
        $this->configureGoogle();
        $user = User::factory()->create([
            'email' => 'pending@example.com',
            'google_id' => 'google-pending',
            'is_active' => false,
        ]);
        $this->mockGoogleUser('google-pending', $user->name, $user->email);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('info', self::PENDING_MESSAGE);
        $this->assertGuest();
        Mail::assertNotSent(GoogleRegistrationPending::class);
    }

    public function test_approved_google_user_can_login_from_callback(): void
    {
        $this->configureGoogle();
        $role = $this->createRole('User');
        $user = User::factory()->create([
            'email' => 'approved@example.com',
            'google_id' => 'google-approved',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->mockGoogleUser('google-approved', $user->name, $user->email);

        $response = $this->get(route('auth.google.callback'));

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_existing_email_account_is_linked_to_google_without_duplicate_user(): void
    {
        $this->configureGoogle();
        $role = $this->createRole('User');
        $user = User::factory()->create([
            'email' => 'linked@example.com',
            'google_id' => null,
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->mockGoogleUser('google-linked', $user->name, $user->email);

        $this->get(route('auth.google.callback'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::where('email', 'linked@example.com')->count());
        $this->assertSame('google-linked', $user->fresh()->google_id);
    }

    public function test_approving_pending_google_user_sends_approval_email(): void
    {
        Mail::fake();
        $admin = $this->createAdmin();
        $role = $this->createRole('User');
        $pendingUser = User::factory()->create([
            'email' => 'approval@example.com',
            'google_id' => 'google-approval',
            'is_active' => false,
        ]);

        $response = $this->actingAs($admin)->put(route('users.update', $pendingUser), [
            'name' => $pendingUser->name,
            'email' => $pendingUser->email,
            'role' => $role->name,
            'department' => null,
            'unit' => null,
            'sub_unit' => null,
            'position' => null,
            'is_active' => true,
            'is_manager' => false,
            'store_ids' => [],
            'manager_ids' => [],
            'notify_user_approval' => true,
        ]);

        $response->assertRedirect();
        $pendingUser->refresh();

        $this->assertTrue((bool) $pendingUser->is_active);
        $this->assertTrue($pendingUser->hasRole($role->name));
        Mail::assertSent(GoogleRegistrationApproved::class, function (GoogleRegistrationApproved $mail) use ($pendingUser) {
            return $mail->hasTo($pendingUser->email) && $mail->user->is($pendingUser);
        });
    }

    public function test_approving_pending_google_user_can_skip_approval_email(): void
    {
        Mail::fake();
        $admin = $this->createAdmin();
        $role = $this->createRole('User');
        $pendingUser = User::factory()->create([
            'email' => 'silent-approval@example.com',
            'google_id' => 'google-silent-approval',
            'is_active' => false,
        ]);

        $response = $this->actingAs($admin)->put(route('users.update', $pendingUser), [
            'name' => $pendingUser->name,
            'email' => $pendingUser->email,
            'role' => $role->name,
            'department' => null,
            'unit' => null,
            'sub_unit' => null,
            'position' => null,
            'is_active' => true,
            'is_manager' => false,
            'store_ids' => [],
            'manager_ids' => [],
            'notify_user_approval' => false,
        ]);

        $response->assertRedirect();
        $this->assertTrue($pendingUser->fresh()->hasRole($role->name));
        Mail::assertNotSent(GoogleRegistrationApproved::class);
    }

    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
    }

    private function mockGoogleUser(string $id, string $name, string $email): void
    {
        $googleUser = Mockery::mock();
        $googleUser->shouldReceive('getId')->andReturn($id);
        $googleUser->shouldReceive('getName')->andReturn($name);
        $googleUser->shouldReceive('getEmail')->andReturn($email);

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function createAdmin(): User
    {
        $adminRole = $this->createRole('Admin');
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        return $admin;
    }

    private function createRegistrationNotificationRecipient(): User
    {
        $role = $this->createRole('Registration Notifications');
        $role->update(['notify_on_user_registration' => true]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function createRole(string $name): Role
    {
        return Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['landing_page' => 'dashboard'],
        );
    }
}
