<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterControllerTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload = [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+63 900 000 0000',
        'password' => 'Str0ng!Pass',
        'device_name' => 'test-device',
    ];

    public function test_creates_both_a_customer_and_a_linked_user(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload);

        $response->assertStatus(201);
        $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'email'], 'roles']);

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->customer_id);

        $customer = Customer::find($user->customer_id);
        $this->assertNotNull($customer);
        $this->assertSame('Jane Doe', $customer->name);
        $this->assertSame('jane@example.com', $customer->email);
        $this->assertSame('+63 900 000 0000', $customer->phone);
        $this->assertTrue($customer->is_active);
    }

    public function test_returns_a_token_that_authenticates_immediately(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload);
        $token = $response->json('token');

        $this->assertNotEmpty($token);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/campaigns')
            ->assertStatus(200); // 401 would mean the token doesn't actually work
    }

    public function test_a_registered_member_has_no_role_assigned(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload);
        $this->assertSame([], $response->json('roles'));
    }

    public function test_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->postJson('/api/register', $this->validPayload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_reuses_an_existing_customer_record_with_the_same_email(): void
    {
        // A walk-in customer staff added manually via the Stamps module
        // before this person ever installed the app.
        $existing = Customer::create([
            'name' => 'Jane D.',
            'email' => 'jane@example.com',
            'phone' => null,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/register', $this->validPayload);
        $response->assertStatus(201);

        $this->assertSame(1, Customer::where('email', 'jane@example.com')->count());
        $user = User::where('email', 'jane@example.com')->first();
        $this->assertSame($existing->id, $user->customer_id);

        // The registration's fresher details win.
        $existing->refresh();
        $this->assertSame('Jane Doe', $existing->name);
        $this->assertSame('+63 900 000 0000', $existing->phone);
    }

    public function test_requires_name_email_and_password(): void
    {
        $response = $this->postJson('/api/register', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_phone_is_optional(): void
    {
        $payload = $this->validPayload;
        unset($payload['phone']);

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(201);
    }

    public function test_rejects_a_weak_password(): void
    {
        $payload = $this->validPayload;
        $payload['password'] = 'weak';

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    public function test_never_stores_the_plaintext_password(): void
    {
        $this->postJson('/api/register', $this->validPayload);

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotEquals('Str0ng!Pass', $user->password);
    }

    public function test_is_throttled_after_five_attempts_per_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $payload = $this->validPayload;
            $payload['email'] = "jane{$i}@example.com";
            $this->postJson('/api/register', $payload)->assertStatus(201);
        }

        $response = $this->postJson('/api/register', array_merge(
            $this->validPayload,
            ['email' => 'janeoverflow@example.com']
        ));

        $response->assertStatus(429);
    }
}
