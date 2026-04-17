<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TableRecord extends Model
{
    protected $fillable = [
        'table_definition_id',
        'data',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function definition()
    {
        return $this->belongsTo(TableDefinition::class, 'table_definition_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updator()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
