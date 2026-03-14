<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SettingsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:settings.view', only: ['index']),
            new Middleware('can:settings.edit', only: ['update']),
        ];
    }

    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key');
        $subUnits = \App\Models\User::whereNotNull('sub_unit')->distinct()->pluck('sub_unit');
        
        return Inertia::render('Settings/Index', [
            'settings' => $settings,
            'subUnits' => $subUnits
        ]);
    }

    public function update(Request $request)
    {
        $settings = $request->all();
        
        foreach ($settings as $key => $value) {
            // Skip internal inertia/laravel keys if any
            if (str_starts_with($key, '_')) continue;

            $group = 'general';
            if (str_starts_with($key, 'imap_') || str_starts_with($key, 'mail_')) {
                $group = 'mail';
            } elseif (str_starts_with($key, 'threshold_')) {
                $group = 'thresholds';
            } elseif (str_starts_with($key, 'business_') || str_starts_with($key, 'working_days')) {
                $group = 'business_hours';
            } elseif (str_starts_with($key, 'sla_')) {
                $group = 'sla_targets';
            }
            
            // Handle array values (like working_days)
            $finalValue = is_array($value) ? json_encode($value) : $value;
            
            Setting::set($key, $finalValue, $group);
        }

        return redirect()->back()->with('success', 'Settings updated successfully.');
    }

    public function testImap(Request $request, \App\Services\EmailTicketService $service)
    {
        $result = $service->testConnection($request->all());

        return response()->json($result);
    }
}
