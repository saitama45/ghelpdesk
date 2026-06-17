<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WigsQuarterGuideline extends Model
{
    protected $table = 'wigs_quarter_guidelines';

    protected $fillable = ['quarter', 'value_name', 'description'];

    protected $casts = [
        'quarter' => 'integer',
    ];
}
