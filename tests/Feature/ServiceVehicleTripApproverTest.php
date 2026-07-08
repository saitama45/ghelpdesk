<?php

namespace Tests\Feature;

use App\Models\ServiceVehicle;
use App\Models\ServiceVehicleTrip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ServiceVehicleTripApproverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['view', 'create', 'edit', 'approve'] as $ability) {
            Permission::create(['name' => "service_vehicle_trips.{$ability}"]);
        }
    }

    /**
     * requester → manager (direct) → head (next level up); plus an unrelated
     * approver who is NOT in the requester's chain.
     */
    private function orgChain(): array
    {
        $head = User::factory()->create(['name' => 'Head Above', 'is_manager' => true]);
        $manager = User::factory()->create(['name' => 'Direct Manager', 'is_manager' => true]);
        $requester = User::factory()->create(['name' => 'Requester']);
        $stranger = User::factory()->create(['name' => 'Unrelated Approver']);

        $manager->managers()->attach($head->id);      // manager reports to head
        $requester->managers()->attach($manager->id); // requester reports to manager

        foreach ([$head, $manager, $stranger] as $u) {
            $u->givePermissionTo('service_vehicle_trips.approve', 'service_vehicle_trips.edit', 'service_vehicle_trips.view');
        }
        $requester->givePermissionTo('service_vehicle_trips.view');

        return compact('head', 'manager', 'requester', 'stranger');
    }

    private function vehicle(): ServiceVehicle
    {
        return ServiceVehicle::create([
            'name' => 'Van 1',
            'plate_no' => 'ABC-123',
            'status' => 'active',
        ]);
    }

    private function pendingTrip(ServiceVehicle $vehicle, User $requester, User $driver): ServiceVehicleTrip
    {
        return ServiceVehicleTrip::create([
            'service_vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'date_used' => now()->addDay()->toDateString(),
            'purpose_of_travel' => 'Client visit',
            'start_point' => 'Office',
            'end_point' => 'Client HQ',
            'planned_departure_time' => '08:00:00',
            'planned_arrival_time' => '17:00:00',
            'status' => 'Pending Approval',
            'created_by' => $requester->id,
            'updated_by' => $requester->id,
        ]);
    }

    public function test_booking_notifies_only_the_requesters_manager_chain(): void
    {
        NotificationFacade::fake();
        ['head' => $head, 'manager' => $manager, 'requester' => $requester, 'stranger' => $stranger] = $this->orgChain();
        $requester->givePermissionTo('service_vehicle_trips.create');
        $vehicle = $this->vehicle();

        $this->actingAs($requester)
            ->post(route('service-vehicle-trips.store'), [
                'service_vehicle_id' => $vehicle->id,
                'driver_id' => $requester->id,
                'date_used' => now()->addDay()->toDateString(),
                'purpose_of_travel' => 'Client visit',
                'start_point' => 'Office',
                'end_point' => 'Client HQ',
                'planned_departure_time' => '08:00',
                'planned_arrival_time' => '17:00',
            ])
            ->assertRedirect();

        // Direct manager + next head above get notified; the unrelated approver does not.
        NotificationFacade::assertSentTo($manager, ActivityNotification::class);
        NotificationFacade::assertSentTo($head, ActivityNotification::class);
        NotificationFacade::assertNotSentTo($stranger, ActivityNotification::class);
    }

    public function test_only_requesters_manager_can_approve(): void
    {
        ['manager' => $manager, 'requester' => $requester, 'stranger' => $stranger] = $this->orgChain();
        $vehicle = $this->vehicle();
        $trip = $this->pendingTrip($vehicle, $requester, $requester);

        // A user with the approve permission but outside the chain is forbidden.
        $this->actingAs($stranger)
            ->patch(route('service-vehicle-trips.approve', $trip))
            ->assertForbidden();

        $this->assertSame('Pending Approval', $trip->fresh()->status);

        // The requester's direct manager can approve.
        $this->actingAs($manager)
            ->patch(route('service-vehicle-trips.approve', $trip))
            ->assertRedirect();

        $this->assertSame('Scheduled', $trip->fresh()->status);
    }

    public function test_edit_flag_and_authorization_are_limited_to_manager_chain(): void
    {
        ['head' => $head, 'manager' => $manager, 'requester' => $requester, 'stranger' => $stranger] = $this->orgChain();
        $requester->givePermissionTo('service_vehicle_trips.edit');
        $vehicle = $this->vehicle();
        $trip = $this->pendingTrip($vehicle, $requester, $requester);

        // can_edit / can_approve flags: true for manager + head, false for requester + stranger.
        $flagFor = fn (User $user) => $this->actingAs($user)
            ->getJson(route('service-vehicle-trips.show', $trip))
            ->json();

        $this->assertTrue($flagFor($manager)['can_edit']);
        $this->assertTrue($flagFor($head)['can_edit']);
        $this->assertFalse($flagFor($requester)['can_edit']);
        $this->assertFalse($flagFor($stranger)['can_edit']);

        // The requester cannot edit even with the edit permission.
        $editPayload = [
            'service_vehicle_id' => $vehicle->id,
            'driver_id' => $requester->id,
            'date_used' => now()->addDays(2)->toDateString(),
            'purpose_of_travel' => 'Updated purpose',
            'start_point' => 'Office',
            'end_point' => 'New Client',
            'planned_departure_time' => '09:00',
            'planned_arrival_time' => '18:00',
        ];

        $this->actingAs($requester)
            ->patch(route('service-vehicle-trips.update', $trip), $editPayload)
            ->assertForbidden();

        $this->actingAs($manager)
            ->patch(route('service-vehicle-trips.update', $trip), $editPayload)
            ->assertRedirect();

        $this->assertSame('Updated purpose', $trip->fresh()->purpose_of_travel);
    }

    public function test_only_the_assigned_driver_can_start_a_trip(): void
    {
        ['manager' => $manager, 'requester' => $requester] = $this->orgChain();
        $driver = User::factory()->create(['name' => 'Assigned Driver']);
        $driver->givePermissionTo('service_vehicle_trips.view');
        $vehicle = $this->vehicle();

        $trip = $this->pendingTrip($vehicle, $requester, $driver);
        $trip->update(['status' => 'Scheduled']);

        // is_driver flag surfaces only for the assigned driver.
        $this->assertTrue(
            $this->actingAs($driver)->getJson(route('service-vehicle-trips.show', $trip))->json('is_driver')
        );
        $this->assertFalse(
            $this->actingAs($manager)->getJson(route('service-vehicle-trips.show', $trip))->json('is_driver')
        );

        // A manager (with edit permission) is not the driver → cannot start.
        $this->actingAs($manager)
            ->patch(route('service-vehicle-trips.start', $trip))
            ->assertForbidden();
        $this->assertSame('Scheduled', $trip->fresh()->status);

        // The assigned driver can start.
        $this->actingAs($driver)
            ->patch(route('service-vehicle-trips.start', $trip))
            ->assertRedirect();
        $this->assertSame('In Progress', $trip->fresh()->status);
    }
}
