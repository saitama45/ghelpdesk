<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A picture in the canvas image library (whiteboard Elements panel). */
class CanvasAsset extends Model
{
    protected $fillable = [
        'name',
        'category',
        'path',
        'mime',
        'width',
        'height',
        'size',
        'shared',
        'created_by',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
        'shared' => 'boolean',
        'created_by' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Origin-relative URL (never Storage::url(), which bakes in APP_URL). */
    public function getUrlAttribute(): string
    {
        return '/serve-storage/'.$this->path;
    }
}
