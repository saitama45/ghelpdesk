<?php

namespace App\Services;

use App\Models\ServiceVehicleTrip;

class ServiceVehicleTripService
{
    /**
     * Statuses that occupy a vehicle slot and therefore participate in conflict detection.
     */
    public const ACTIVE_STATUSES = ['Pending Approval', 'Scheduled', 'In Progress'];

    /**
     * Find a conflicting trip on the same vehicle and date whose planned time window overlaps.
     * Returns the offending trip or null.
     *
     * @param  int $vehicleId
     * @param  string $dateUsed Y-m-d
     * @param  string $plannedDeparture H:i or H:i:s
     * @param  string $plannedArrival H:i or H:i:s
     * @param  int|null $excludeTripId When editing, exclude the trip being edited.
     */
    public function detectConflict(
        int $vehicleId,
        string $dateUsed,
        string $plannedDeparture,
        string $plannedArrival,
        ?int $excludeTripId = null
    ): ?ServiceVehicleTrip {
        $query = ServiceVehicleTrip::query()
            ->where('service_vehicle_id', $vehicleId)
            ->where('date_used', $dateUsed)
            ->whereIn('status', self::ACTIVE_STATUSES)
            // overlap: not (existing.end <= new.start OR existing.start >= new.end)
            ->where(function ($q) use ($plannedDeparture, $plannedArrival) {
                $q->where('planned_departure_time', '<', $plannedArrival)
                  ->where('planned_arrival_time', '>', $plannedDeparture);
            });

        if ($excludeTripId) {
            $query->where('id', '!=', $excludeTripId);
        }

        return $query->with('driver:id,name')->first();
    }
}
