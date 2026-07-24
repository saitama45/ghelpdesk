<?php

namespace App\Models;

use App\Models\Scopes\ActiveEntityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Ticket extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'ticket_key',
        'title',
        'description',
        'description_html',
        'type',
        'status',
        'priority',
        'severity',
        'reporter_id',
        'assignee_id',
        'project_id',
        'milestone_id',
        'company_id',
        'store_id',
        'category_id',
        'sub_category_id',
        'item_id',
        'vendor_id',
        'parent_id',
        'sender_email',
        'sender_name',
        'department',
        'department_id',
        'message_id',
        'source_message_id',
        'email_body_hash',
        'survey_token',
        'channel',
        'queue_track_token',
        'called_at',
        'queue_called_lane',
        'is_deleted',
        'deleted_by',
    ];

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->firstOrFail();
    }

    /**
     * The given root tickets plus their direct children — the unit that cascade
     * operations (archive / restore / purge) act on.
     *
     * Two things this gets right that a bare `whereIn(...)->orWhereIn(...)` does not:
     *
     * 1. ActiveEntityScope is dropped. A ticket family can span entities, and the
     *    scope is a listing filter, not an authorization boundary — resolveRouteBinding
     *    bypasses it too. Leaving it on made cascades silently skip the root when the
     *    viewer's active entity differed from the ticket's company.
     * 2. The OR is grouped. Otherwise it compiles to
     *    `company_id = ? AND id IN (...) OR parent_id IN (...)`, letting the second
     *    branch escape every other constraint on the query.
     *
     * Callers are responsible for authorizing the roots before calling this.
     */
    public function scopeFamilyOf(Builder $query, $rootIds): Builder
    {
        return $query
            ->withoutGlobalScope(ActiveEntityScope::class)
            ->where(function (Builder $q) use ($rootIds) {
                $q->whereIn('id', $rootIds)->orWhereIn('parent_id', $rootIds);
            });
    }

    public function parent()
    {
        return $this->belongsTo(Ticket::class, 'parent_id')
            ->withoutGlobalScope(ActiveEntityScope::class);
    }

    public function children()
    {
        return $this->hasMany(Ticket::class, 'parent_id')
            ->withoutGlobalScope(ActiveEntityScope::class);
    }

    public function scheduleStore()
    {
        return $this->hasOne(ScheduleStore::class);
    }

    public function schedule()
    {
        return $this->hasOneThrough(
            Schedule::class,
            ScheduleStore::class,
            'ticket_id',   // FK on schedule_stores → tickets
            'id',          // PK on schedules
            'id',          // PK on tickets
            'schedule_id'  // FK on schedule_stores → schedules
        );
    }

    public function slaMetric()
    {
        return $this->hasOne(TicketSlaMetric::class);
    }

    public function survey()
    {
        return $this->hasOne(TicketSurvey::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function comments()
    {
        return $this->hasMany(TicketComment::class);
    }

    public function ccs()
    {
        return $this->hasMany(TicketCc::class);
    }

    /**
     * Returns the effective CC list for notifications.
     * Child tickets inherit from their parent.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function effectiveCcs()
    {
        $owner = $this->parent_id ? ($this->parent ?? static::find($this->parent_id)) : $this;
        return $owner ? $owner->ccs()->get() : collect();
    }

    /**
     * The requestor whose concern owns this email thread. Child tickets retain
     * their staff creator as reporter, so their customer/requestor comes from
     * the parent ticket instead.
     */
    public function effectiveRequesterRecipient(): ?array
    {
        $owner = $this->parent_id ? $this->parent()->first() : $this;

        if (!$owner) {
            return null;
        }

        $owner->loadMissing('reporter:id,name,email');

        if ($owner->reporter?->email) {
            return [
                'email' => strtolower(trim($owner->reporter->email)),
                'name' => $owner->reporter->name,
                'id' => $owner->reporter->id,
                'role' => 'requester',
            ];
        }

        if ($owner->sender_email) {
            return [
                'email' => strtolower(trim($owner->sender_email)),
                'name' => $owner->sender_name ?: 'External User',
                'id' => null,
                'role' => 'requester',
            ];
        }

        return null;
    }

    /**
     * Every email participant following this ticket thread. Parent requestors
     * and CCs follow child tickets without changing the child's ownership.
     */
    public function threadEmailRecipients(): Collection
    {
        $this->loadMissing([
            'assignee:id,name,email',
            'reporter:id,name,email',
            'vendor:id,name,email,contact_person',
        ]);

        $recipients = collect();
        $push = function (?string $email, ?string $name, ?int $id, string $role) use ($recipients): void {
            $email = strtolower(trim((string) $email));
            if ($email === '') {
                return;
            }

            $recipients->push([
                'email' => $email,
                'name' => $name ?: $email,
                'id' => $id,
                'role' => $role,
            ]);
        };

        $push($this->assignee?->email, $this->assignee?->name, $this->assignee?->id, 'assignee');
        $push($this->vendor?->email, $this->vendor?->contact_person ?: $this->vendor?->name, null, 'vendor');
        $push($this->reporter?->email, $this->reporter?->name, $this->reporter?->id, 'reporter');

        if ($requester = $this->effectiveRequesterRecipient()) {
            $recipients->push($requester);
        }

        foreach ($this->effectiveCcs() as $cc) {
            $push($cc->email, $cc->name ?: $cc->email, $cc->user_id, 'cc');
        }

        return $recipients->unique('email')->values();
    }

    public function histories()
    {
        return $this->hasMany(TicketHistory::class);
    }

    /**
     * Retired ticket_key values this ticket used to carry (after a renumber).
     */
    public function keyAliases()
    {
        return $this->hasMany(TicketKeyAlias::class);
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function taggedAssets()
    {
        return $this->hasMany(TicketAsset::class);
    }

    public function views()
    {
        return $this->hasMany(TicketView::class);
    }

    protected $casts = [
        'reporter_id' => 'integer',
        'assignee_id' => 'integer',
        'company_id' => 'integer',
        'department_id' => 'integer',
        'store_id' => 'integer',
        'category_id' => 'integer',
        'sub_category_id' => 'integer',
        'item_id' => 'integer',
        'vendor_id' => 'integer',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'deleted_at' => 'datetime:Y-m-d H:i:s',
        'called_at' => 'datetime:Y-m-d H:i:s',
        'is_deleted' => 'boolean',
    ];

    /**
     * Lazily assign and persist a stable token for the public "Track my ticket"
     * queue page, then return it. Existing tickets (created before the queue
     * feature) get one the first time a track link is built for them.
     */
    public function ensureTrackToken(): string
    {
        if (empty($this->queue_track_token)) {
            $this->queue_track_token = \Illuminate\Support\Str::random(40);
            $this->saveQuietly();
        }

        return $this->queue_track_token;
    }

    /**
     * Prepare a date for array / JSON serialization.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Who archived this ticket. Null for tickets archived before deleted_by existed.
     * Named `archiver`, not `deletedBy`: the latter serializes to the key `deleted_by`
     * and would overwrite the integer column of the same name in toArray().
     */
    public function archiver()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The owning department. Nullable — legacy rows may only carry the free-text
     * {@see $department} string, which remains the display fallback.
     */
    public function departmentRef()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function vendor()
    {
        return $this->belongsTo(\App\Models\Vendor::class);
    }

    public function cctvInspection()
    {
        return $this->hasOne(\App\Models\CctvInspection::class);
    }
}
