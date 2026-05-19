<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceVehicleTripAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_vehicle_trip_id',
        'file_name',
        'file_storage_path',
        'file_size_bytes',
        'uploaded_by',
        'uploaded_date',
    ];

    protected $casts = [
        'service_vehicle_trip_id' => 'integer',
        'file_size_bytes' => 'integer',
        'uploaded_by' => 'integer',
        'uploaded_date' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(ServiceVehicleTrip::class, 'service_vehicle_trip_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
