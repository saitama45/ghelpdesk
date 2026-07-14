<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrganizationReferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
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

        $stores = \App\Models\Store::where('is_active', true)->select('id', 'name', 'code')->orderBy('name')->get();

        return Inertia::render('Settings/Index', [
            'settings' => $settings,
            'subUnits' => $subUnits,
            'departmentReferences' => $this->organizationReferences->tree(activeOnly: true),
            'assignableStaff' => $assignableStaff,
            'companies' => $companies,
            'stores' => $stores,
        ]);
    }

    public function update(Request $request)
    {
        $this->validateHealthThresholds($request);
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
            } elseif (str_starts_with($key, 'queue_')) {
                $group = 'queue';
            }

            if ($key === 'ticket_retention_value') {
                $value = max(1, (int) $value);
            } elseif ($key === 'ticket_retention_unit' && !in_array($value, ['months', 'years'], true)) {
                $value = 'months';
            } elseif ($key === 'queue_refresh_seconds') {
                $value = max(3, (int) $value);
            } elseif ($key === 'queue_walkin_priority_floor' && !in_array($value, ['low', 'medium', 'high', 'urgent'], true)) {
                $value = 'medium';
            } elseif ($key === 'queue_lane_nodes') {
                $value = is_array($value)
                    ? array_values(array_filter(array_map(fn ($code) => trim((string) $code), $value)))
                    : array_values(array_filter(array_map('trim', explode(',', (string) $value))));
            }

            // Handle array values (like working_days)
            $finalValue = is_array($value) ? json_encode($value) : $value;

            Setting::set($key, $finalValue, $group);
        }

        Cache::forget('app_mail_settings');
        Cache::forget('sidebar_layout_config');

        return redirect()->back()->with('success', 'Settings updated successfully.');
    }

    private function validateHealthThresholds(Request $request): void
    {
        $suffixes = collect(array_keys($request->all()))
            ->map(fn ($key) => preg_match('/^threshold_green_min(.*)$/', $key, $matches) ? $matches[1] : null)
            ->filter(fn ($suffix) => $suffix !== null)
            ->unique();

        $errors = [];

        foreach ($suffixes as $suffix) {
            $key = fn (string $color, string $field) => "threshold_{$color}_{$field}{$suffix}";
            $numericFields = [
                $key('green', 'min'), $key('green', 'max'),
                $key('yellow', 'min'), $key('yellow', 'max'),
                $key('orange', 'min'), $key('orange', 'max'),
                $key('red', 'min'),
            ];

            foreach ($numericFields as $field) {
                $value = $request->input($field);
                if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
                    $errors[$field] = 'Enter a whole ticket count of zero or greater.';
                }
            }

            foreach (['green', 'yellow', 'orange', 'red'] as $color) {
                $field = $key($color, 'label');
                $label = trim((string) $request->input($field, ''));
                if ($label === '') {
                    $errors[$field] = 'Enter a status label.';
                } elseif (mb_strlen($label) > 50) {
                    $errors[$field] = 'The status label must not exceed 50 characters.';
                }
            }

            if (array_intersect($numericFields, array_keys($errors))) {
                continue;
            }

            $greenMin = (int) $request->input($key('green', 'min'));
            $greenMax = (int) $request->input($key('green', 'max'));
            $yellowMin = (int) $request->input($key('yellow', 'min'));
            $yellowMax = (int) $request->input($key('yellow', 'max'));
            $orangeMin = (int) $request->input($key('orange', 'min'));
            $orangeMax = (int) $request->input($key('orange', 'max'));
            $redMin = (int) $request->input($key('red', 'min'));

            if ($greenMin !== 0) {
                $errors[$key('green', 'min')] = 'Healthy must begin at 0 so stores without open tickets remain Healthy.';
            }
            if ($greenMax < $greenMin) {
                $errors[$key('green', 'max')] = 'Healthy maximum must be at least its minimum.';
            }
            if ($yellowMin !== $greenMax + 1) {
                $errors[$key('yellow', 'min')] = 'Warning must begin immediately after the Healthy maximum.';
            }
            if ($yellowMax < $yellowMin) {
                $errors[$key('yellow', 'max')] = 'Warning maximum must be at least its minimum.';
            }
            if ($orangeMin !== $yellowMax + 1) {
                $errors[$key('orange', 'min')] = 'At-risk must begin immediately after the Warning maximum.';
            }
            if ($orangeMax < $orangeMin) {
                $errors[$key('orange', 'max')] = 'At-risk maximum must be at least its minimum.';
            }
            if ($redMin !== $orangeMax + 1) {
                $errors[$key('red', 'min')] = 'Critical must begin immediately after the At-risk maximum.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function testImap(Request $request, \App\Services\EmailTicketService $service)
    {
        $result = $service->testConnection($request->all());

        return response()->json($result);
    }
}
