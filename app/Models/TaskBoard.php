<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskBoard extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'board_source',
        'department',
        'sub_unit',
        'board_month',
        'board_year',
        'monthly_key',
        'title',
        'description',
        'background_type',
        'background_value',
        'created_by',
        'closed_at',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'created_by' => 'integer',
        'board_month' => 'integer',
        'board_year' => 'integer',
        'closed_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function memberRecords(): HasMany
    {
        return $this->hasMany(TaskBoardMember::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_board_members')
            ->withPivot(['role', 'starred', 'last_opened_at'])
            ->withTimestamps();
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_board_watchers')->withTimestamps();
    }

    public function labels(): HasMany
    {
        return $this->hasMany(TaskLabel::class)->orderBy('sort_order')->orderBy('id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(TaskCard::class)->orderBy('sort_order')->orderBy('id');
    }

    public function columns(): HasMany
    {
        return $this->hasMany(TaskBoardColumn::class)->orderBy('sort_order')->orderBy('id');
    }

    public function columnForRole(string $role): ?TaskBoardColumn
    {
        return $this->columns->firstWhere('role', $role)
            ?? $this->columns()->where('role', $role)->orderBy('sort_order')->first();
    }

    public function columnNameForRole(string $role): ?string
    {
        return $this->columnForRole($role)?->name;
    }

    public function columnForName(string $name): ?TaskBoardColumn
    {
        return $this->columns->firstWhere('name', $name)
            ?? $this->columns()->where('name', $name)->first();
    }

    public function roleForColumnName(string $name): ?string
    {
        return $this->columnForName($name)?->role;
    }

    /**
     * Seed the four default columns onto a freshly created board.
     */
    public function seedDefaultColumns(): void
    {
        foreach (TaskBoardColumn::DEFAULTS as $index => $default) {
            $this->columns()->create([
                'name' => $default['name'],
                'color' => $default['color'],
                'role' => $default['role'],
                'sort_order' => $index,
            ]);
        }
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskCardActivity::class)->latest();
    }

    public function isMember(User $user): bool
    {
        return $this->memberRecords()->where('user_id', $user->id)->exists();
    }

    public function memberRole(User $user): ?string
    {
        return $this->memberRecords()->where('user_id', $user->id)->value('role');
    }
}
