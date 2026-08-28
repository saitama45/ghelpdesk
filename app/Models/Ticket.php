<?php

namespace App\Models;

use App\Models\Scopes\ActiveEntityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
        'serving_department_id',
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

    /**
     * Tickets are addressed by their human key — `/tickets/TGI-4096/edit` — not by
     * the UUID primary key. The key is what people read, quote in email and search
     * for; a UUID in the address bar tells them nothing about which ticket they are
     * looking at.
     */
    public function getRouteKeyName(): string
    {
        return 'ticket_key';
    }

    /**
     * A ticket whose key has not been generated yet (a row mid-import, or one whose
     * company could not be resolved) still has to be linkable, so fall back to the
     * UUID rather than minting `/tickets//edit`.
     */
    public function getRouteKey()
    {
        return $this->ticket_key ?: $this->getKey();
    }

    /**
     * Accepts all three ways a ticket has ever been addressed, so no link anyone
     * holds stops working:
     *   1. the current ticket_key,
     *   2. the UUID — every link mailed out before the switch carries one,
     *   3. a key retired by a renumber ({@see TicketKeyAlias}).
     *
     * Unscoped by entity on purpose: ActiveEntityScope is a listing filter, not an
     * access boundary, and applying it here 404s a ticket the user may legitimately
     * open from another entity.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = fn () => $this->withoutGlobalScope(ActiveEntityScope::class);

        if ($field) {
            return $query()->where($field, $value)->firstOrFail();
        }

        $value = (string) $value;

        if ($ticket = $query()->where('ticket_key', $value)->first()) {
            return $ticket;
        }

        // Guarded: handing a non-UUID to a uniqueidentifier column is a SQL Server
        // conversion error, not a miss.
        if (Str::isUuid($value) && ($ticket = $query()->whereKey($value)->first())) {
            return $ticket;
        }

        $aliasedId = TicketKeyAlias::where('ticket_key', $value)->value('ticket_id');

        if ($aliasedId && ($ticket = $query()->whereKey($aliasedId)->first())) {
            return $ticket;
        }

        throw (new ModelNotFoundException)->setModel(static::class, [$value]);
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

    /**
     * The tickets a department is responsible for — the PROVIDER side.
     *
     * Resolution order:
     *
     * 1. `serving_department_id` when set. This is the explicit route: the
     *    inbound plus-address the mail arrived on, the department that owns the
     *    form the request came from, or a staff override.
     * 2. Otherwise the ASSIGNEE's department, the signal this scope used
     *    exclusively before routing existed. Kept for rows the backfill could not
     *    resolve (assignee has no department) and for anything created by a path
     *    that does not set a route yet.
     * 3. Otherwise, if `$includeUnassigned`, the shared intake pool: unrouted AND
     *    unassigned, so nobody has claimed it and every desk should see it.
     *
     * Note `department_id` is deliberately NOT consulted — that column is the
     * REQUESTER's department (the internal customer), not the servicing desk.
     * Reading it here is the mistake that made ExecutiveController report the
     * tickets a department raised as the work it delivered.
     *
     * Pass `$includeUnassigned: false` where the department must be certain —
     * e.g. the internal-customer view, which would otherwise repeat the same
     * unclaimed ticket under every department tab.
     *
     * Every OR is grouped so no branch can escape the surrounding constraints.
     */
    public function scopeOwnedByDepartment(Builder $query, int $departmentId, bool $includeUnassigned = true): Builder
    {
        return $query->where(function (Builder $q) use ($departmentId, $includeUnassigned) {
            $q->where('tickets.serving_department_id', $departmentId)
                ->orWhere(function (Builder $byAssignee) use ($departmentId) {
                    $byAssignee->whereNull('tickets.serving_department_id')
                        ->whereHas('assignee', fn ($a) => $a->where('department_id', $departmentId));
                });

            if ($includeUnassigned) {
                $q->orWhere(function (Builder $pool) {
                    $pool->whereNull('tickets.serving_department_id')
                        ->whereNull('tickets.assignee_id');
                });
            }
        });
    }

    /**
     * The scope SLA business hours are measured against: the department whose
     * working day the clock should follow.
     *
     * That is the SERVING department — the desk held to the target — falling back
     * to the assignee's department when the ticket carries no route.
     *
     * The finer-grained sub-unit values (org_path, department_node_id) can only
     * come from the assignee, and are only safe when the assignee actually sits
     * in the resolved department. Passing a node from a different department
     * would resolve business hours for a sub-unit of the wrong org.
     *
     * @return array{0: ?string, 1: ?int, 2: ?int} [orgPath, departmentId, departmentNodeId]
     */
    public function slaScope(?User $assignee = null): array
    {
        $assignee ??= $this->assignee_id ? User::find($this->assignee_id) : null;

        $departmentId = $this->serving_department_id ?: $assignee?->department_id;

        $assigneeMatchesDepartment = $assignee
            && $departmentId
            && (int) $assignee->department_id === (int) $departmentId;

        return [
            $assigneeMatchesDepartment ? $assignee->org_path : null,
            $departmentId ? (int) $departmentId : null,
            $assigneeMatchesDepartment ? $assignee->department_node_id : null,
        ];
    }

    /**
     * The tickets a department RAISED — the customer side of the axis.
     *
     * Counterpart to {@see scopeOwnedByDepartment()}. Uses the requester's
     * department FK, falling back to the reporter's current department for rows
     * predating the column (it was only ever written from the free-text
     * `department` string, so a large share of history is null).
     */
    public function scopeRequestedByDepartment(Builder $query, int $departmentId): Builder
    {
        return $query->where(function (Builder $q) use ($departmentId) {
            $q->where('tickets.department_id', $departmentId)
                ->orWhere(function (Builder $byReporter) use ($departmentId) {
                    $byReporter->whereNull('tickets.department_id')
                        ->whereHas('reporter', fn ($r) => $r->where('department_id', $departmentId));
                });
        });
    }

    /**
     * Escalated to an external vendor: responsibility (and SLA) sits with the
     * vendor rather than an internal user.
     *
     * A vendor_id ALONE does not mean this. Ordinary tickets are tagged with a
     * vendor for reporting — including the "None - Remote" placeholder, which
     * carries 128 tickets — and they still need an internal owner. Escalation
     * creates a CHILD ticket against the vendor, so it is the pairing of a
     * parent with a vendor that identifies one. Mirrors `isVendorEscalationChild`
     * in Tickets/Edit.vue; keep the two definitions in step.
     */
    public function scopeVendorEscalated(Builder $query): Builder
    {
        return $query->whereNotNull('vendor_id')->whereNotNull('parent_id');
    }

    /**
     * Tickets genuinely waiting for someone to take ownership.
     *
     * A null assignee alone is not enough: a vendor-escalation child also has no
     * internal assignee yet IS owned, so the desk must not invite anyone to
     * "accept" it. Excluding those keeps the Unassigned counts and filters
     * meaning "needs an owner".
     */
    public function scopeAwaitingOwner(Builder $query): Builder
    {
        return $query->whereNull('assignee_id')
            ->where(fn (Builder $q) => $q->whereNull('vendor_id')->orWhereNull('parent_id'));
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
     * The CC list for OUTBOUND EMAIL: effectiveCcs minus addresses the mail server
     * has permanently rejected (see ticket_ccs.undeliverable_at).
     *
     * Kept separate from effectiveCcs on purpose — that one still backs the ticket
     * page and the in-app bell, so a dead address stays visible and its owner keeps
     * their notifications. Only the mailing stops.
     */
    public function deliverableCcs()
    {
        return $this->effectiveCcs()->reject(fn ($cc) => $cc->undeliverable_at !== null)->values();
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

        // Email participants only — a bounced CC is not one.
        foreach ($this->deliverableCcs() as $cc) {
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
     * The REQUESTER's department — the internal customer who raised this. Not
     * the department doing the work; see {@see servingDepartment()} for that.
     *
     * Nullable — legacy rows may only carry the free-text {@see $department}
     * string, which remains the display fallback.
     */
    public function departmentRef()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * The department RESPONSIBLE for the work — the service provider side of the
     * department axis, and what {@see scopeOwnedByDepartment()} resolves.
     *
     * Set explicitly by the routing sources: the inbound plus-address
     * (App\Services\DepartmentMailRouter), the owning department of the form a
     * request came from (form_definitions.department_id), or a staff override.
     * Nullable — an unrouted ticket sits in the shared intake pool until someone
     * takes it, and the assignee's department is the fallback signal.
     */
    public function servingDepartment()
    {
        return $this->belongsTo(Department::class, 'serving_department_id');
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
