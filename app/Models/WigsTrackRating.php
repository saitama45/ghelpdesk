<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WigsTrackRating extends Model
{
    protected $table = 'wigs_track_ratings';

    protected $fillable = ['rating', 'description', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
