<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreBlueprint extends Model
{
    protected $fillable = [
        'store_id',
        'file_name',
        'file_storage_path',
        'file_size_bytes',
        'mime_type',
        'uploaded_by',
        'uploaded_at',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'file_size_bytes' => 'integer',
        'uploaded_by' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    /**
     * Serialize dates in Asia/Manila to match the app convention.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
