<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The mobile app's Suggestion Box: members post from Profile → Suggestion Box,
 * staff read the messages on /stamps → Suggestions.
 *
 * Runs against the isolated sqlite :memory: connection forced by phpunit.xml.
 */
class SuggestionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function member(): array
    {
        $customer = Customer::create(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'is_active' => true]);
        $user = User::factory()->create(['customer_id' => $customer->id]);

        return [$user, $customer];
    }

    private function bearerHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test-device')->plainTextToken];
    }

    public function test_sending_a_suggestion_requires_authentication(): void
    {
        $this->postJson('/api/loyalty/suggestions', ['message' => 'Hello'])->assertStatus(401);
    }

    public function test_a_member_suggestion_is_stored_against_their_customer_and_the_cbtl_entity(): void
    {
        $cbtl = Company::create(['name' => 'Coffee Bean & Tea Leaf', 'code' => 'CBTL']);
        [$user, $customer] = $this->member();

        $this->withHeaders($this->bearerHeaders($user))
            ->postJson('/api/loyalty/suggestions', [
                'subject' => '  Oat milk  ',
                'message' => "Please offer oat milk.\nThank you!",
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'message']);

        $suggestion = CustomerSuggestion::sole();
        $this->assertSame($customer->id, $suggestion->customer_id);
        $this->assertSame($user->id, $suggestion->user_id);
        $this->assertSame($cbtl->id, $suggestion->company_id);
        $this->assertSame('Oat milk', $suggestion->subject);
        $this->assertSame("Please offer oat milk.\nThank you!", $suggestion->message);
    }

    public function test_a_message_is_required_and_capped(): void
    {
        [$user] = $this->member();
        $headers = $this->bearerHeaders($user);

        $this->withHeaders($headers)->postJson('/api/loyalty/suggestions', ['message' => '   '])
            ->assertStatus(422)->assertJsonValidationErrors('message');

        $this->withHeaders($headers)->postJson('/api/loyalty/suggestions', ['message' => str_repeat('a', 2001)])
            ->assertStatus(422)->assertJsonValidationErrors('message');

        $this->assertSame(0, CustomerSuggestion::count());
    }

    public function test_staff_with_stamps_view_see_suggestions_on_the_stamps_page(): void
    {
        [$user, $customer] = $this->member();
        CustomerSuggestion::create([
            'customer_id' => $customer->id, 'user_id' => $user->id, 'message' => 'More seats, please.',
        ]);

        $staff = User::factory()->create();
        $staff->givePermissionTo(Permission::findOrCreate('stamps.view', 'web'));

        $this->actingAs($staff)->get('/stamps?tab=suggestions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Stamps/Index')
                ->where('tab', 'suggestions')
                ->has('suggestions', 1)
                ->where('suggestions.0.message', 'More seats, please.')
                ->where('suggestions.0.customer.name', 'Jane Doe'));
    }

    public function test_the_stamps_page_still_refuses_staff_without_stamps_view(): void
    {
        $this->actingAs(User::factory()->create())->get('/stamps?tab=suggestions')->assertForbidden();
    }
}
