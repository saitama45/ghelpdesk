<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferenceOption extends Model
{
    protected $fillable = ['type', 'value', 'label', 'sort_order'];

    public static function ofType(string $type)
    {
        return static::where('type', $type)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();
    }

    public static function valuesOfType(string $type): array
    {
        return static::where('type', $type)->pluck('value')->all();
    }
}
