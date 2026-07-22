<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Traits\HasPermissions;
use App\Models\Company;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'employee_id_no',
        'email',
        'email_verified_at',
        'password',
        'google_id',
        'department',
        'department_id',
        'department_node_id',
        'org_path',
        'position',
        'is_active',
        'is_manager',
        'is_vacant',
        'last_login',
        'status',
        'last_activity_at',
        'profile_photo',
        'org_sort_order',
        'company_id',
        'date_hired',
        'created_by',
        'updated_by',
    ];

    public function managers()
    {
        return $this->belongsToMany(User::class, 'manager_user', 'user_id', 'manager_id');
    }

    public function subordinates()
    {
        return $this->belongsToMany(User::class, 'manager_user', 'manager_id', 'user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

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
            'is_manager' => 'boolean',
            'is_vacant' => 'boolean',
            'company_id' => 'integer',
            'department_id' => 'integer',
            'department_node_id' => 'integer',
            'date_hired' => 'date:Y-m-d',
            'created_by' => 'integer',
            'updated_by' => 'integer',
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

    public function departmentReference()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function departmentNode()
    {
        return $this->belongsTo(DepartmentNode::class, 'department_node_id');
    }

    public function stores()
    {
        return $this->belongsToMany(Store::class)->withTimestamps();
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function pointTransactions()
    {
        return $this->hasMany(AgentPointTransaction::class, 'agent_id');
    }

    public function totalPoints(): int
    {
        return (int) $this->pointTransactions()->sum('points');
    }

    public function currentLevel(): string
    {
        $total = $this->totalPoints();
        $settings = \App\Models\Setting::whereIn('key', [
            'leadership.level_beginner', 'leadership.level_intermediate', 'leadership.level_professional',
            'leadership.level_expert', 'leadership.level_master', 'leadership.level_guru',
        ])->pluck('value', 'key');

        $levels = [
            'Guru'         => (int) ($settings['leadership.level_guru'] ?? 1000000),
            'Master'       => (int) ($settings['leadership.level_master'] ?? 500000),
            'Expert'       => (int) ($settings['leadership.level_expert'] ?? 250000),
            'Professional' => (int) ($settings['leadership.level_professional'] ?? 100000),
            'Intermediate' => (int) ($settings['leadership.level_intermediate'] ?? 25000),
            'Beginner'     => 0,
        ];

        foreach ($levels as $name => $threshold) {
            if ($total >= $threshold) {
                return $name;
            }
        }

        return 'Beginner';
    }
}
