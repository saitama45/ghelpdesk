<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group'];

    private static array $cache = [];

    /**
     * Get a setting value by key
     */
    public static function get($key, $default = null)
    {
        if (!array_key_exists($key, self::$cache)) {
            $setting = self::where('key', $key)->first();
            self::$cache[$key] = $setting ? $setting->value : null;
        }

        return self::$cache[$key] ?? $default;
    }

    /**
     * Get a setting with department/node override support and legacy org_path fallback.
     */
    public static function getScoped(
        string $key,
        mixed $default = null,
        ?int $departmentId = null,
        ?int $departmentNodeId = null,
        ?string $legacyScope = null
    ): mixed {
        foreach (self::scopedKeys($key, $departmentId, $departmentNodeId, $legacyScope) as $candidateKey) {
            $value = self::get($candidateKey);
            if ($value !== null) {
                return $value;
            }
        }

        return self::get($key, $default);
    }

    public static function scopedKey(string $key, string $scopeId): string
    {
        return $scopeId === 'global' || $scopeId === ''
            ? $key
            : "{$key}_{$scopeId}";
    }

    private static function scopedKeys(
        string $key,
        ?int $departmentId,
        ?int $departmentNodeId,
        ?string $legacyScope
    ): array {
        $keys = [];

        if ($departmentNodeId) {
            $keys[] = "{$key}_node_{$departmentNodeId}";
        }

        if ($departmentId) {
            $keys[] = "{$key}_department_{$departmentId}";
        }

        foreach (self::legacyScopeSlugs($legacyScope) as $slug) {
            $keys[] = "{$key}_{$slug}";
        }

        return array_values(array_unique($keys));
    }

    private static function legacyScopeSlugs(?string $legacyScope): array
    {
        if (!$legacyScope) {
            return [];
        }

        $frontendSlug = strtolower((string) $legacyScope);
        $frontendSlug = preg_replace('/\s+/', '_', $frontendSlug);
        $frontendSlug = preg_replace('/[^\w-]+/', '', $frontendSlug);
        $frontendSlug = preg_replace('/--+/', '_', $frontendSlug);
        $frontendSlug = trim($frontendSlug, '-');

        return array_filter([
            \Illuminate\Support\Str::slug($legacyScope, '_'),
            $frontendSlug,
        ]);
    }

    /**
     * Set a setting value by key
     */
    public static function set($key, $value, $group = 'general')
    {
        self::$cache[$key] = $value;

        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group]
        );
    }

    /**
     * Get all settings grouped by their group column
     */
    public static function getAllGrouped()
    {
        return self::all()->groupBy('group');
    }
}
