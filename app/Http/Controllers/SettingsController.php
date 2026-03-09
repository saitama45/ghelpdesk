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
        ]);

        foreach ($validated as $key => $value) {
            $group = 'general';
            if (str_starts_with($key, 'imap_')) {
                $group = 'mail';
            } elseif (str_starts_with($key, 'threshold_')) {
                $group = 'thresholds';
            }
            Setting::set($key, $value, $group);
        }

        return redirect()->back()->with('success', 'Settings updated successfully.');
    }
}
