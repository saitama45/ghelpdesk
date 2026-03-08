<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Spatie\Permission\Models\Permission;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        // Create permissions
        Permission::create(['name' => 'stores.create']);
        Permission::create(['name' => 'stores.edit']);
        Permission::create(['name' => 'stores.view']);
    }

    public function test_can_create_store_with_email(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('stores.create');

        $response = $this->actingAs($user)->post('/stores', [
            'code' => 'STR-001',
            'name' => 'Test Store',
            'email' => 'store@example.com',
            'sector' => 1,
            'area' => 'Test Area',
            'brand' => 'Test Brand',
            'cluster' => 'Test Cluster',
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('stores', [
            'code' => 'STR-001',
            'email' => 'store@example.com',
        ]);
    }

    public function test_can_update_store_with_email(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('stores.edit');

        $store = Store::create([
            'code' => 'STR-001',
            'name' => 'Test Store',
            'sector' => 1,
            'area' => 'Test Area',
            'brand' => 'Test Brand',
            'cluster' => 'Test Cluster',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->put("/stores/{$store->id}", [
            'code' => 'STR-001',
            'name' => 'Updated Store',
            'email' => 'updated@example.com',
            'sector' => 2,
            'area' => 'Updated Area',
            'brand' => 'Updated Brand',
            'cluster' => 'Updated Cluster',
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'email' => 'updated@example.com',
            'name' => 'Updated Store',
        ]);
    }
}
