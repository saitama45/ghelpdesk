<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class KbArticle extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'content',
        'kb_category_id',
        'author_id',
        'source_item_id',
        'source_ticket_id',
        'source_ticket_comment_id',
        'source_content_fingerprint',
        'is_ticket_generated',
        'is_published',
        'views'
    ];

    protected $casts = [
        'kb_category_id' => 'integer',
        'author_id' => 'integer',
        'source_item_id' => 'integer',
        'is_ticket_generated' => 'boolean',
        'is_published' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($article) {
            if (!$article->slug) {
                $article->slug = Str::slug($article->title) . '-' . Str::random(5);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'kb_category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'source_item_id');
    }

    public function sourceTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'source_ticket_id');
    }

    public function sourceTicketComment(): BelongsTo
    {
        return $this->belongsTo(TicketComment::class, 'source_ticket_comment_id');
    }
}
