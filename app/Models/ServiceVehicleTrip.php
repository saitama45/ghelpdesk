<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceVehicleTrip extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_vehicle_id',
        'driver_id',
        'date_used',
        'purpose_of_travel',
        'passengers',
        'start_point',
        'end_point',
        'waypoints',
        'planned_departure_time',
        'planned_arrival_time',
        'actual_departure_time',
        'actual_arrival_time',
        'odometer_before',
        'odometer_after',
        'remarks',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'acknowledgement_accepted',
        'acknowledged_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'service_vehicle_id' => 'integer',
        'driver_id' => 'integer',
        'date_used' => 'date:Y-m-d',
        'odometer_before' => 'integer',
        'odometer_after' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'acknowledgement_accepted' => 'boolean',
        'acknowledged_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'waypoints' => 'array',
    ];

    public function vehicle()
    {
        return $this->belongsTo(ServiceVehicle::class, 'service_vehicle_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function attachments()
    {
        return $this->hasMany(ServiceVehicleTripAttachment::class);
    }
}
