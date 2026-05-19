<?php

namespace App\Http\Controllers;

use App\Models\ServiceVehicle;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ServiceVehicleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:service_vehicle_trips.view',   only: ['index']),
            new Middleware('can:service_vehicle_trips.create', only: ['store']),
            new Middleware('can:service_vehicle_trips.edit',   only: ['update']),
            new Middleware('can:service_vehicle_trips.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        return response()->json(
            ServiceVehicle::orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'plate_no' => 'required|string|max:50|unique:service_vehicles,plate_no',
            'capacity' => 'nullable|integer|min:1',
            'status'   => 'required|string|in:active,maintenance,retired',
            'notes'    => 'nullable|string',
        ]);

        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        ServiceVehicle::create($validated);

        return redirect()->back()->with('success', 'Service vehicle added.');
    }

    public function update(Request $request, ServiceVehicle $serviceVehicle)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'plate_no' => 'required|string|max:50|unique:service_vehicles,plate_no,' . $serviceVehicle->id,
            'capacity' => 'nullable|integer|min:1',
            'status'   => 'required|string|in:active,maintenance,retired',
            'notes'    => 'nullable|string',
        ]);

        $validated['updated_by'] = $request->user()->id;

        $serviceVehicle->update($validated);

        return redirect()->back()->with('success', 'Vehicle updated.');
    }

    public function destroy(ServiceVehicle $serviceVehicle)
    {
        if ($serviceVehicle->trips()->exists()) {
            return redirect()->back()->withErrors(['vehicle' => 'Cannot delete a vehicle that has trip records. Retire it instead.']);
        }

        $serviceVehicle->delete();

        return redirect()->back()->with('success', 'Vehicle removed.');
    }
}
