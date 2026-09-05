<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shared with the vendor portal (linkportal), which authenticates against this
 * same table via its `vendor` guard. Rows carrying a password are live portal
 * login accounts; the rest are back-office reference vendors.
 */
class Vendor extends Model
{
    use SoftDeletes;

    /**
     * A Cashier is a linkportal login that runs the Campaigns (loyalty stamps)
     * module for the one store in `store_id`, rather than a supplier trading
     * with the group.
     */
    public const TYPE_CASHIER = 'Cashier';

    /**
     * The seed list only. Vendor types are managed at runtime through
     * reference_options (type = `vendor_type`) from the /vendors modal, exactly
     * like project types on /activity-templates — read them with `types()`,
     * never from this constant, or a type added by an admin (or written by the
     * portal) would fail validation.
     */
    public const TYPES = [
        'Supplier',
        'Service Provider',
        'Contractor',
        'Consultant',
        'Logistics / Forwarder',
        self::TYPE_CASHIER,
    ];

    /**
     * Selectable vendor types, managed list first and the seed as a fallback so
     * a database whose reference options have not been seeded still validates.
     *
     * @return array<int, string>
     */
    public static function types(): array
    {
        $managed = ReferenceOption::valuesOfType('vendor_type');

        return $managed ?: self::TYPES;
    }

    /** True when this account is a portal cashier running Campaigns. */
    public function isCashier(): bool
    {
        return $this->vendor_type === self::TYPE_CASHIER;
    }

    /**
     * Lifecycle of a portal account. `pending` still signs in — that is how a
     * registrant completes their profile and uploads accreditation documents —
     * but the portal's `vendor.active` middleware keeps them out of transactions
     * until an approver moves them to `active`.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DEACTIVATED = 'deactivated';

    protected $fillable = [
        'code',
        'name',
        'vendor_type',
        'store_id',
        'contact_person',
        'email',
        'phone',
        'address',
        'is_active',
        'default_payment_mode',
        'default_payment_split',
        'status',
        'approved_by',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'default_payment_split' => 'array',
        'approved_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    /** Never expose portal credentials through back-office responses. */
    protected $hidden = ['password', 'remember_token'];

    /**
     * Stamp who touched the record, the way /users does. Vendors also arrive
     * from portal self-registration, where there is no back-office user — those
     * rows keep a null creator and read as "Vendor Portal" in the UI.
     */
    protected static function booted(): void
    {
        static::creating(function (self $vendor) {
            $vendor->created_by ??= auth()->id();
            $vendor->updated_by ??= auth()->id();
        });

        static::updating(function (self $vendor) {
            $vendor->updated_by = auth()->id() ?? $vendor->updated_by;
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Portal login accounts only — the reference-vendor rows have no password. */
    public function scopeWithPortalAccess($query)
    {
        return $query->whereNotNull('password');
    }

    /** True when this vendor can sign in to the vendor portal. */
    public function hasPortalAccess(): bool
    {
        return $this->password !== null;
    }

    /** True once an approver has activated the portal account. */
    public function isPortalApproved(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** The store a Cashier account books its loyalty activity against. */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvals()
    {
        return $this->hasMany(VendorApproval::class)->latest('decided_at');
    }

    /** The company profile the vendor maintains in the portal. */
    public function profile()
    {
        return $this->hasOne(VendorProfile::class);
    }

    /** Accreditation files uploaded through the portal. */
    public function documents()
    {
        return $this->hasMany(VendorDocument::class);
    }
}
