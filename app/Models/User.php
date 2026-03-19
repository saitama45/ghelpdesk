<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Traits\HasPermissions;
use App\Models\Company;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'department',
        'unit',
        'sub_unit',
        'position',
        'is_active',
        'last_login',
        'status',
        'last_activity_at',
        'profile_photo',
        'company_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function userPresenceLogs()
    {
        return $this->hasMany(UserPresenceLog::class);
    }

    public function lastPresenceLog()
    {
        return $this->hasOne(UserPresenceLog::class)->latestOfMany();
    }

    public function updateStatus(string $status): void
    {
        if ($this->status === $status) {
            $this->update(['last_activity_at' => now()]);
            return;
        }

        // Close the previous log
        $lastLog = $this->lastPresenceLog;
        if ($lastLog && !$lastLog->ended_at) {
            $endedAt = now();
            // Use diffInSeconds with absolute set to true, and cast to int
            $duration = abs((int) $endedAt->diffInSeconds($lastLog->started_at));
            $lastLog->update([
                'ended_at' => $endedAt,
                'duration_seconds' => $duration,
            ]);
        }

        // Create new log
        $this->userPresenceLogs()->create([
            'status' => $status,
            'started_at' => now(),
        ]);

        $this->update([
            'status' => $status,
            'last_activity_at' => now(),
        ]);
    }

    public function lastAttendanceLog()
    {
        return $this->hasOne(AttendanceLog::class)->latestOfMany();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login' => 'datetime',
            'last_activity_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function updateLastLogin(): void
    {
        $this->last_login = now();
        $this->save();
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function stores()
    {
        return $this->belongsToMany(Store::class)->withTimestamps();
    }
}