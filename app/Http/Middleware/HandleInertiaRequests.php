<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $permissions = [];

        if ($user) {
            // Load roles and companies for company-based filtering
            $user->loadMissing('roles.companies');

            // Cache per-user permission list for 1 hour.
            // Key includes: user.updated_at (changes when role is reassigned on this user)
            // and a global permissions_version counter (bumped whenever any role's permissions change).
            $version = Cache::get('permissions_version', 0);
            $cacheKey = 'user_permissions_' . $user->id . '_' . ($user->updated_at?->timestamp ?? 0) . '_v' . $version;
            $permissions = Cache::remember($cacheKey, 3600, function () use ($user) {
                return $user->getAllPermissions()->pluck('name')->unique()->values()->toArray();
            });
        }
        
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user,
                'permissions' => array_values($permissions),
            ],
            'dynamicTables' => Cache::remember('active_table_definitions', 3600, function() {
                return \App\Models\TableDefinition::where('is_active', true)->get(['name', 'slug', 'icon'])->toArray();
            }),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
            ],
        ]);
    }
}
