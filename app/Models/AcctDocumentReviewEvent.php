<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcctDocumentReviewEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'acct_document_review_events';

    protected $fillable = ['review_id', 'event', 'user_id', 'remarks', 'meta', 'created_at'];

    protected function casts(): array
    {
        return [
            'review_id' => 'integer',
            'user_id' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function review()
    {
        return $this->belongsTo(AcctDocumentReview::class, 'review_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
