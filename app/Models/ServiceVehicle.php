<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceVehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'plate_no',
        'capacity',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function trips()
    {
        return $this->hasMany(ServiceVehicleTrip::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
