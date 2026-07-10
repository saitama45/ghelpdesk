<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcctDocumentReview extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'acct_document_reviews';

    protected $fillable = [
        'idempotency_key', 'source_document_id', 'source_reference_no', 'document_type',
        'vendor_code', 'vendor_name', 'company_name', 'fields', 'line_items',
        'confidence', 'exceptions_summary', 'file_url', 'file_url_expires_at',
        'callback_url', 'status', 'decision', 'decision_remarks', 'decided_by',
        'decided_at', 'callback_status', 'callback_attempts', 'callback_error',
        'received_at', 'due_at',
    ];

    protected function casts(): array
    {
        return [
            // sqlsrv returns FK ids as strings; keep them integers for === checks
            'source_document_id' => 'integer',
            'decided_by' => 'integer',
            'callback_attempts' => 'integer',
            'fields' => 'array',
            'line_items' => 'array',
            'confidence' => 'array',
            'exceptions_summary' => 'array',
            'file_url_expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'received_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function events()
    {
        return $this->hasMany(AcctDocumentReviewEvent::class, 'review_id')->orderBy('created_at');
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_RETURNED, self::STATUS_REJECTED], true);
    }

    public function fileUrlExpired(): bool
    {
        return $this->file_url_expires_at !== null && $this->file_url_expires_at->isPast();
    }

    public function recordEvent(string $event, ?int $userId = null, ?string $remarks = null, array $meta = []): AcctDocumentReviewEvent
    {
        return $this->events()->create([
            'event' => $event,
            'user_id' => $userId,
            'remarks' => $remarks,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }
}
