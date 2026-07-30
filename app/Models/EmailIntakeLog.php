<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per inbound message the fetcher has decided about — the fetcher's own
 * memory, independent of the mailbox \Seen flag that humans also change.
 *
 * See the create migration for why the flag alone was not enough.
 */
class EmailIntakeLog extends Model
{
    protected $fillable = [
        'message_key',
        'uid',
        'folder',
        'subject',
        'sender_email',
        'recipients',
        'outcome',
        'error',
        'ticket_id',
        'is_recovered',
        'processed_at',
    ];

    protected $casts = [
        'is_recovered' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public const OUTCOME_CREATED = 'created';
    public const OUTCOME_COMMENT = 'comment';
    public const OUTCOME_DUPLICATE = 'duplicate';
    public const OUTCOME_BANNED_SENDER = 'banned_sender';
    public const OUTCOME_NOT_ADDRESSED_TO_US = 'not_addressed_to_us';
    public const OUTCOME_DEPARTMENT_DIRECTORY = 'department_directory';
    public const OUTCOME_CLOSED_TICKET = 'closed_ticket';
    public const OUTCOME_NO_SUPPORT_EMAIL = 'no_support_email';
    public const OUTCOME_ERROR = 'error';

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Whether this message still needs processing. An error is not a decision —
     * it must be retried on the next pass.
     */
    public function isSettled(): bool
    {
        return $this->outcome !== self::OUTCOME_ERROR;
    }
}
