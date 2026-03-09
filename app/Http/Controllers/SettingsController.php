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
        
        return Inertia::render('Settings/Index', [
            'settings' => $settings
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'imap_host' => 'nullable|string',
            'imap_port' => 'nullable|numeric',
            'imap_encryption' => 'nullable|string',
            'imap_username' => 'nullable|string',
            'imap_password' => 'nullable|string',
            'mail_mailer' => 'nullable|string',
            'mail_host' => 'nullable|string',
            'mail_port' => 'nullable|numeric',
            'mail_username' => 'nullable|string',
            'mail_password' => 'nullable|string',
            'mail_encryption' => 'nullable|string',
            'mail_from_address' => 'nullable|email',
            'mail_from_name' => 'nullable|string',
            'google_maps_api_key' => 'nullable|string',
            'threshold_green_min' => 'nullable|numeric',
            'threshold_green_max' => 'nullable|numeric',
            'threshold_green_label' => 'nullable|string',
            'threshold_yellow_min' => 'nullable|numeric',
            'threshold_yellow_max' => 'nullable|numeric',
            'threshold_yellow_label' => 'nullable|string',
            'threshold_orange_min' => 'nullable|numeric',
            'threshold_orange_max' => 'nullable|numeric',
            'threshold_orange_label' => 'nullable|string',
            'threshold_red_min' => 'nullable|numeric',
            'threshold_red_label' => 'nullable|string',
            'business_start_time' => 'nullable|string',
            'business_end_time' => 'nullable|string',
            'working_days' => 'nullable|array',
            'sla_low_response' => 'nullable|numeric',
            'sla_low_resolution' => 'nullable|numeric',
            'sla_medium_response' => 'nullable|numeric',
            'sla_medium_resolution' => 'nullable|numeric',
            'sla_high_response' => 'nullable|numeric',
            'sla_high_resolution' => 'nullable|numeric',
            'sla_urgent_response' => 'nullable|numeric',
            'sla_urgent_resolution' => 'nullable|numeric',
            'sla_low_label' => 'nullable|string',
            'sla_medium_label' => 'nullable|string',
            'sla_high_label' => 'nullable|string',
            'sla_urgent_label' => 'nullable|string',
            'auto_close_resolved_hours' => 'nullable|numeric|min:0',
        ]);

        foreach ($validated as $key => $value) {
            $group = 'general';
            if (str_starts_with($key, 'imap_') || str_starts_with($key, 'mail_')) {
                $group = 'mail';
            } elseif (str_starts_with($key, 'threshold_')) {
                $group = 'thresholds';
            } elseif (str_starts_with($key, 'business_') || $key === 'working_days') {
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
}
