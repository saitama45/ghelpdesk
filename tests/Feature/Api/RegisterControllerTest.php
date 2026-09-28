<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\RegisterController;
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

    /**
     * An account as `AccountArchiveService` leaves it — address parked in
     * `archived_email`, tombstone in `email` — inserted in that state rather
     * than archived here.
     */
    private function archivedMember(string $address, bool $parked = true): User
    {
        $customer = (new Customer())->forceFill([
            'name' => 'Jane Doe',
            'email' => strtolower($address),
            'is_active' => true,
            'deleted_at' => now(),
        ]);
        $customer->save();

        return User::factory()->create([
            'email' => $parked ? 'deleted-900@archived.invalid' : $address,
            'archived_email' => $parked ? $address : null,
            'customer_id' => $customer->id,
            'deleted_at' => now(),
        ]);
    }

    public function test_refuses_an_email_whose_account_is_archived(): void
    {
        $this->archivedMember('jane@example.com');

        $response = $this->postJson('/api/register', $this->validPayload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email' => RegisterController::CLOSED_ACCOUNT_MESSAGE]);
        $this->assertSame(0, User::where('email', 'jane@example.com')->count());
        $this->assertSame(0, Customer::where('email', 'jane@example.com')->count());
    }

    public function test_the_archived_address_is_matched_whatever_its_case(): void
    {
        $this->archivedMember('Jane@Example.com');

        $this->postJson('/api/register', $this->validPayload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => RegisterController::CLOSED_ACCOUNT_MESSAGE]);
    }

    public function test_an_account_archived_before_the_address_was_parked_gets_the_same_answer(): void
    {
        $this->archivedMember('jane@example.com', parked: false);

        $this->postJson('/api/register', $this->validPayload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => RegisterController::CLOSED_ACCOUNT_MESSAGE]);
    }

    public function test_the_store_review_account_may_register_again_after_deletion(): void
    {
        config(['services.app_review.email' => 'jane@example.com']);
        $this->archivedMember('jane@example.com');

        $this->postJson('/api/register', $this->validPayload)->assertStatus(201);
        $this->assertSame(1, User::where('email', 'jane@example.com')->count());
    }

    public function test_any_listed_review_account_may_register_again_after_deletion(): void
    {
        config(['services.app_review.email' => 'keeper@example.com, Jane@Example.com']);
        $this->archivedMember('jane@example.com');

        $this->postJson('/api/register', $this->validPayload)->assertStatus(201);
        $this->assertSame(1, User::where('email', 'jane@example.com')->count());
    }

    public function test_a_list_that_only_mentions_the_address_in_passing_does_not_exempt_it(): void
    {
        // Exact entries only — no substring or partial matches.
        config(['services.app_review.email' => 'notjane@example.com,jane@example.com.ph']);
        $this->archivedMember('jane@example.com');

        $this->postJson('/api/register', $this->validPayload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => RegisterController::CLOSED_ACCOUNT_MESSAGE]);
    }

    private function archivedWalkIn(string $address): Customer
    {
        $customer = (new Customer())->forceFill([
            'name' => 'Jane D.',
            'email' => $address,
            'is_active' => true,
            'deleted_at' => now(),
        ]);
        $customer->save();

        return $customer;
    }

    public function test_refuses_an_email_only_an_archived_walk_in_customer_holds(): void
    {
        // Staff closed this customer from Stamps → Customers; they never had a login.
        $this->archivedWalkIn('Jane@Example.com');

        $this->postJson('/api/register', $this->validPayload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => RegisterController::CLOSED_ACCOUNT_MESSAGE]);
        $this->assertSame(0, User::where('email', 'jane@example.com')->count());
    }

    public function test_an_archived_duplicate_does_not_block_when_a_live_customer_shares_the_address(): void
    {
        $this->archivedWalkIn('jane@example.com');
        $live = Customer::create(['name' => 'Jane D.', 'email' => 'jane@example.com', 'is_active' => true]);

        $this->postJson('/api/register', $this->validPayload)->assertStatus(201);

        $this->assertSame($live->id, User::where('email', 'jane@example.com')->value('customer_id'));
    }

    public function test_an_archived_account_does_not_block_other_addresses(): void
    {
        $this->archivedMember('someone.else@example.com');

        $this->postJson('/api/register', $this->validPayload)->assertStatus(201);
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
