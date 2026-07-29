<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use App\Services\DepartmentMailRouter;
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
            'departmentMailboxes' => $this->departmentMailboxes(),
            'supportBaseAddress' => app(DepartmentMailRouter::class)->baseAddress(),
        ]);
    }

    /**
     * Per-department inbound address and outbound display name, for the Mail
     * Configuration tab.
     */
    private function departmentMailboxes(): \Illuminate\Support\Collection
    {
        $router = app(DepartmentMailRouter::class);

        return Department::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'mail_address', 'mail_from_name'])
            ->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'code' => $department->code,
                'mail_address' => $department->mail_address,
                'mail_from_name' => $department->mail_from_name,
                // Advisory only: an address on another domain is legitimate, it
                // just needs forwarding, so the UI hints rather than blocks.
                'shares_domain' => $router->sharesDomainWithMailbox($department->mail_address),
            ]);
    }

    /**
     * Saves the per-department mail identity, as part of the single Settings save.
     *
     * Separate method rather than inline because these are department ROWS, not
     * key-value settings — the caller strips them out of the flat writer first.
     * A null/absent payload means the page never sent them, so leave them alone.
     */
    private function saveDepartmentMailboxes($mailboxes, ?string $submittedSupportAddress = null): void
    {
        if (! is_array($mailboxes)) {
            return;
        }

        $validated = validator(
            ['mailboxes' => $mailboxes],
            [
                'mailboxes' => 'array',
                'mailboxes.*.id' => 'required|exists:departments,id',
                'mailboxes.*.mail_address' => 'nullable|email|max:255',
                'mailboxes.*.mail_from_name' => 'nullable|string|max:100',
            ],
            ['mailboxes.*.mail_address.email' => 'Enter a complete email address, e.g. fm@tablegroup.com.']
        )->validate();

        $rows = collect($validated['mailboxes'] ?? [])
            ->map(function (array $row) {
                // Addresses are matched case-insensitively against inbound headers,
                // so normalise on the way in rather than at every comparison.
                $row['mail_address'] = strtolower(trim((string) ($row['mail_address'] ?? ''))) ?: null;
                $row['mail_from_name'] = trim((string) ($row['mail_from_name'] ?? '')) ?: null;

                return $row;
            });

        // A duplicate would silently route two departments' mail to whichever row
        // the routing map happened to build last.
        $addresses = $rows->pluck('mail_address')->filter();
        if ($addresses->count() !== $addresses->unique()->count()) {
            throw ValidationException::withMessages([
                'mailboxes' => 'Each department needs a distinct address — two departments cannot share one inbox address.',
            ]);
        }

        // The support mailbox is the catch-all; handing it to a department would
        // make every unrouted message land on that desk instead of the pool.
        //
        // Compared against the SUBMITTED support address, not the stored one: this
        // runs before the settings write, so a user changing the mailbox and
        // assigning its old value to a department in the same save would otherwise
        // slip past.
        $base = strtolower(trim((string) ($submittedSupportAddress ?? '')))
            ?: app(DepartmentMailRouter::class)->baseAddress();

        if ($base !== '' && $addresses->contains($base)) {
            throw ValidationException::withMessages([
                'mailboxes' => "{$base} is the shared support mailbox and cannot be assigned to a single department.",
            ]);
        }

        foreach ($rows as $row) {
            Department::whereKey($row['id'])->update([
                'mail_address' => $row['mail_address'],
                'mail_from_name' => $row['mail_from_name'],
            ]);
        }

        // whereKey()->update() bypasses model events, so flush explicitly.
        DepartmentMailRouter::flush();
    }

    public function update(Request $request)
    {
        $this->validateHealthThresholds($request);
        $settings = $request->all();

        // Department mailboxes ride along in the same submit so the page has ONE
        // save button, but they are department ROWS rather than key-value settings
        // — pull them out before the mass-assign loop below, which would otherwise
        // json_encode the whole array into a bogus `mailboxes` setting.
        $this->saveDepartmentMailboxes($request->input('mailboxes'), $request->input('imap_username'));
        unset($settings['mailboxes']);

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

            if ($key === 'mail_require_department_address') {
                // Stored as an explicit '1'/'0' rather than a raw bool: PHP casts
                // false to '' on the way into the text column, and (bool) '' works
                // by luck rather than intent.
                $value = $request->boolean($key) ? '1' : '0';
            } elseif ($key === 'ticket_retention_value') {
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
        // Departmental addresses are derived from the support mailbox, so a change
        // to imap_username re-points every one of them.
        DepartmentMailRouter::flush();

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
