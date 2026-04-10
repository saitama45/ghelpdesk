<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group'];

    /**
     * Get a setting value by key
     */
    public static function get($key, $default = null)
    {
        static $cache = [];
        if (!array_key_exists($key, $cache)) {
            $setting = self::where('key', $key)->first();
            $cache[$key] = $setting ? $setting->value : null;
        }
        return $cache[$key] ?? $default;
    }

    /**
     * Set a setting value by key
     */
    public static function set($key, $value, $group = 'general')
    {
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
