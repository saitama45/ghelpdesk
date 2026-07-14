<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SettingsHealthThresholdValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_thresholds_must_be_continuous_and_begin_at_zero(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('settings.edit');
        $user->givePermissionTo('settings.edit');

        $response = $this->actingAs($user)->put(route('settings.update'), [
            'threshold_green_min' => 1,
            'threshold_green_max' => 2,
            'threshold_green_label' => 'Healthy',
            'threshold_yellow_min' => 4,
            'threshold_yellow_max' => 4,
            'threshold_yellow_label' => 'Warning',
            'threshold_orange_min' => 5,
            'threshold_orange_max' => 5,
            'threshold_orange_label' => 'At-risk',
            'threshold_red_min' => 6,
            'threshold_red_label' => 'Critical',
        ]);

        $response->assertSessionHasErrors(['threshold_green_min', 'threshold_yellow_min']);
        $this->assertDatabaseMissing('settings', ['key' => 'threshold_green_min']);
    }

    public function test_valid_scoped_health_thresholds_are_saved(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('settings.edit');
        $user->givePermissionTo('settings.edit');

        $response = $this->actingAs($user)->put(route('settings.update'), [
            'threshold_green_min_node_12' => 0,
            'threshold_green_max_node_12' => 3,
            'threshold_green_label_node_12' => 'Stable',
            'threshold_yellow_min_node_12' => 4,
            'threshold_yellow_max_node_12' => 5,
            'threshold_yellow_label_node_12' => 'Monitor',
            'threshold_orange_min_node_12' => 6,
            'threshold_orange_max_node_12' => 7,
            'threshold_orange_label_node_12' => 'Escalate',
            'threshold_red_min_node_12' => 8,
            'threshold_red_label_node_12' => 'Critical',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('settings', [
            'key' => 'threshold_green_min_node_12',
            'value' => '0',
            'group' => 'thresholds',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'threshold_red_min_node_12',
            'value' => '8',
            'group' => 'thresholds',
        ]);
    }
}
