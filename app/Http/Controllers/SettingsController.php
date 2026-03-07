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
        ]);

        foreach ($validated as $key => $value) {
            $group = str_starts_with($key, 'imap_') ? 'mail' : 'general';
            Setting::set($key, $value, $group);
        }

        return redirect()->back()->with('success', 'Settings updated successfully.');
    }
}
