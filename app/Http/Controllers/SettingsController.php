<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrganizationReferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SettingsController extends Controller implements HasMiddleware
{
    public function __construct(private OrganizationReferenceService $organizationReferences)
    {
    }

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
        $subUnits = User::whereNotNull('org_path')->distinct()->pluck('org_path');

        $assignableStaff = User::whereHas('roles', fn($q) => $q->where('is_assignable', true))
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        $companies = Company::where('is_active', true)->select('id', 'name', 'code')->orderBy('name')->get();

        return Inertia::render('Settings/Index', [
            'settings' => $settings,
            'subUnits' => $subUnits,
            'departmentReferences' => $this->organizationReferences->tree(activeOnly: true),
            'assignableStaff' => $assignableStaff,
            'companies' => $companies,
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
            } elseif (str_starts_with($key, 'ticket_retention_')) {
                $group = 'ticket_retention';
            } elseif (str_starts_with($key, 'threshold_')) {
                $group = 'thresholds';
            } elseif (str_starts_with($key, 'business_') || str_starts_with($key, 'working_days')) {
                $group = 'business_hours';
            } elseif (str_starts_with($key, 'sla_')) {
                $group = 'sla_targets';
            } elseif (str_starts_with($key, 'auto_assignee_')) {
                $group = 'auto_assignee';
            }

            if ($key === 'ticket_retention_value') {
                $value = max(1, (int) $value);
            } elseif ($key === 'ticket_retention_unit' && !in_array($value, ['months', 'years'], true)) {
                $value = 'months';
            }

            // Handle array values (like working_days)
            $finalValue = is_array($value) ? json_encode($value) : $value;

            Setting::set($key, $finalValue, $group);
        }

        Cache::forget('app_mail_settings');
        Cache::forget('sidebar_layout_config');

        return redirect()->back()->with('success', 'Settings updated successfully.');
    }

    public function testImap(Request $request, \App\Services\EmailTicketService $service)
    {
        $result = $service->testConnection($request->all());

        return response()->json($result);
    }
}
