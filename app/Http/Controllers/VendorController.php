<?php

namespace App\Http\Controllers;

use App\Models\ReferenceOption;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorApproval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class VendorController extends Controller implements HasMiddleware
{
    /**
     * Columns the listing needs. Pinned rather than `SELECT *` so the shared
     * `vendors` table's nvarchar(MAX) payment-split blob and portal credentials
     * never cross the wire.
     */
    private const LIST_COLUMNS = [
        'id', 'code', 'name', 'vendor_type', 'store_id', 'contact_person', 'email', 'phone',
        'address', 'is_active', 'status', 'email_verified_at', 'last_login_at',
        'approved_by', 'approved_at', 'created_by', 'updated_by',
        'created_at', 'updated_at',
    ];

    /** action => the status it puts the portal account into. */
    private const APPROVAL_ACTIONS = [
        'approve' => Vendor::STATUS_ACTIVE,
        'reject' => Vendor::STATUS_REJECTED,
        'suspend' => Vendor::STATUS_SUSPENDED,
        'reactivate' => Vendor::STATUS_ACTIVE,
    ];

    /**
     * action => how the decision reads once made. Spelled out rather than built
     * from the verb: appending "d" turns "reject" into "rejectd".
     */
    private const APPROVAL_PAST_TENSE = [
        'approve' => 'approved',
        'reject' => 'rejected',
        'suspend' => 'suspended',
        'reactivate' => 'reactivated',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:vendors.view', only: ['index']),
            new Middleware('can:vendors.create', only: ['store']),
            new Middleware('can:vendors.edit', only: ['update']),
            new Middleware('can:vendors.delete', only: ['destroy']),
            new Middleware('can:vendors.approve', only: ['approval']),
            new Middleware('can:vendors.reset_password', only: ['resetPassword']),
        ];
    }

    public function index(Request $request)
    {
        $query = Vendor::query()
            ->select(self::LIST_COLUMNS)
            // `password` decides portal access but must not reach the client, so
            // it is resolved server-side into a boolean here.
            ->addSelect(DB::raw('CASE WHEN password IS NULL THEN 0 ELSE 1 END AS has_portal_access'))
            ->with([
                'creator:id,name,email',
                'updater:id,name,email',
                'approver:id,name,email',
                // Only portal accounts ever have decisions, so this stays empty
                // for the back-office reference vendors that make up most rows.
                'approvals.decider:id,name,email',
            ]);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'pending' => $query->whereNotNull('password')->where('status', Vendor::STATUS_PENDING),
                'portal' => $query->whereNotNull('password'),
                'active' => $query->where('is_active', true),
                'inactive' => $query->where('is_active', false),
                default => null,
            };
        }

        $vendors = $query->orderBy('name')->paginate($request->get('per_page', 10))->withQueryString();

        $vendors->getCollection()->transform(function ($vendor) {
            $vendor->has_portal_access = (bool) $vendor->has_portal_access;

            return $vendor;
        });

        return Inertia::render('Vendors/Index', [
            'vendors' => $vendors,
            'filters' => $request->only(['search', 'status']),
            // Surfaces the registration queue on the index so it does not have
            // to be filtered for to be noticed.
            'pendingCount' => Vendor::withPortalAccess()->where('status', Vendor::STATUS_PENDING)->count(),
            // Managed inline from the modal, the way /activity-templates manages
            // project types.
            'vendorTypes' => ReferenceOption::ofType('vendor_type'),
            // Only customer-facing outlets: a Cashier account runs Campaigns at
            // a till, which warehouses and offices do not have.
            'stores' => Store::query()
                ->where('class', 'Regular')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'cashierType' => Vendor::TYPE_CASHIER,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'code'           => 'nullable|string|max:50',
            'name'           => 'required|string|max:255|unique:vendors,name',
            'vendor_type'    => 'required|string|in:' . implode(',', Vendor::types()),
            'store_id'       => 'nullable|exists:stores,id',
            'contact_person' => 'nullable|string|max:255',
            'email'          => 'nullable|email|max:255|unique:vendors,email',
            'phone'          => 'nullable|string|max:50',
            'address'        => 'nullable|string',
        ]);

        Vendor::create([
            'code'           => $request->code,
            'name'           => $request->name,
            'vendor_type'    => $request->vendor_type,
            // A store is only meaningful for a Cashier; carrying one on a
            // supplier would scope a Campaigns module they never see.
            'store_id'       => $request->vendor_type === Vendor::TYPE_CASHIER ? $request->store_id : null,
            'contact_person' => $request->contact_person,
            'email'          => $request->email,
            'phone'          => $request->phone,
            'address'        => $request->address,
            'is_active'      => true,
        ]);

        return redirect()->back()->with('success', 'Vendor created successfully');
    }

    public function update(Request $request, Vendor $vendor)
    {
        $request->validate([
            'code'           => 'nullable|string|max:50',
            'name'           => 'required|string|max:255|unique:vendors,name,' . $vendor->id,
            'vendor_type'    => 'required|string|in:' . implode(',', Vendor::types()),
            'store_id'       => 'nullable|exists:stores,id',
            'contact_person' => 'nullable|string|max:255',
            'email'          => 'nullable|email|max:255|unique:vendors,email,' . $vendor->id,
            'phone'          => 'nullable|string|max:50',
            'address'        => 'nullable|string',
            'is_active'      => 'boolean',
        ]);

        // The portal authenticates on this column, so it must not be cleared or
        // changed out from under a live login account.
        if ($vendor->hasPortalAccess() && $request->email !== $vendor->email) {
            return redirect()->back()->with(
                'error',
                'This vendor has a portal login. Change their email from the vendor portal admin instead.'
            );
        }

        $vendor->update([
            'code'           => $request->code,
            'name'           => $request->name,
            'vendor_type'    => $request->vendor_type,
            'store_id'       => $request->vendor_type === Vendor::TYPE_CASHIER ? $request->store_id : null,
            'contact_person' => $request->contact_person,
            'email'          => $request->email,
            'phone'          => $request->phone,
            'address'        => $request->address,
            // For a portal account `is_active` is a consequence of the approval
            // decision — letting this form flip it would grant or revoke portal
            // access with no approver, no reason and no history.
            'is_active'      => $vendor->hasPortalAccess()
                ? $vendor->isPortalApproved()
                : $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', 'Vendor updated successfully');
    }

    /**
     * The approval matrix: the single decision point that grants, refuses or
     * withdraws a portal vendor's access. Every outcome is written to
     * `vendor_approvals` with its approver and reason.
     */
    public function approval(Request $request, Vendor $vendor)
    {
        abort_unless($vendor->hasPortalAccess(), 404);

        $validated = $request->validate([
            'action' => 'required|in:' . implode(',', array_keys(self::APPROVAL_ACTIONS)),
            // A refusal or a suspension must say why — the vendor is told, and
            // the next reviewer needs the context.
            'remarks' => 'nullable|string|max:1000|required_unless:action,approve,reactivate',
        ]);

        $action = $validated['action'];
        $statusAfter = self::APPROVAL_ACTIONS[$action];
        $statusBefore = $vendor->status;

        if ($statusBefore === $statusAfter) {
            return redirect()->back()->with('error', 'This vendor is already ' . $statusAfter . '.');
        }

        $pastTense = self::APPROVAL_PAST_TENSE[$action];

        DB::transaction(function () use ($request, $vendor, $pastTense, $statusBefore, $statusAfter, $validated) {
            $vendor->forceFill([
                'status' => $statusAfter,
                // `is_active` is what the back office and its payment screens read.
                'is_active' => $statusAfter === Vendor::STATUS_ACTIVE,
                'approved_by' => $statusAfter === Vendor::STATUS_ACTIVE ? $request->user()->id : $vendor->approved_by,
                'approved_at' => $statusAfter === Vendor::STATUS_ACTIVE ? now() : $vendor->approved_at,
                'updated_by' => $request->user()->id,
            ])->save();

            VendorApproval::create([
                'vendor_id' => $vendor->id,
                'action' => $pastTense,
                'status_before' => $statusBefore,
                'status_after' => $statusAfter,
                'remarks' => $validated['remarks'] ?? null,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ]);
        });

        $this->notifyPortalVendor($vendor, $action, $validated['remarks'] ?? null);

        return redirect()->back()->with('success', 'Vendor ' . $pastTense . ' successfully');
    }

    /**
     * Set a portal vendor's password on their behalf, mirroring the reset on
     * /users. Vendors can also reset their own from the portal's login screen.
     */
    public function resetPassword(Request $request, Vendor $vendor)
    {
        // A Cashier never self-registers — the public portal registration form is
        // for suppliers — so this is also where their login is first issued. Every
        // other vendor must already have portal access for there to be a password
        // to reset.
        $issuingCashierLogin = ! $vendor->hasPortalAccess() && $vendor->isCashier();

        abort_unless($vendor->hasPortalAccess() || $issuingCashierLogin, 404);

        if ($issuingCashierLogin && ! $vendor->email) {
            return redirect()->back()->with('error', 'Add an email address before issuing a portal login.');
        }

        if ($issuingCashierLogin && ! $vendor->store_id) {
            return redirect()->back()->with('error', 'Assign a store before issuing a cashier portal login.');
        }

        $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        // forceFill: `password` is deliberately absent from $fillable so that no
        // ordinary vendor update can ever write a credential.
        $vendor->forceFill([
            'password' => Hash::make($request->password),
            // Any "remember me" session the old password left behind dies with it.
            'remember_token' => null,
            'updated_by' => $request->user()->id,
        ] + ($issuingCashierLogin ? [
            // The account is created by an approver, so it starts approved —
            // there is no registration for anyone to review, and no OTP inbox
            // round trip to make a cashier wait through.
            'status' => Vendor::STATUS_ACTIVE,
            'is_active' => true,
            'email_verified_at' => now(),
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ] : []))->save();

        $this->notifyPortalVendor($vendor, 'password_reset');

        return redirect()->back()->with(
            'success',
            $issuingCashierLogin
                ? 'Cashier portal login created.'
                : 'Vendor portal password reset successfully'
        );
    }

    public function destroy(Vendor $vendor)
    {
        // Deleting here would also destroy the vendor's portal login and orphan
        // their profile, documents and submitted invoices.
        if ($vendor->hasPortalAccess()) {
            return redirect()->back()->with(
                'error',
                'This vendor has a portal login and cannot be deleted here. Reject or suspend the account instead.'
            );
        }

        $vendor->delete();

        return redirect()->back()->with('success', 'Vendor deleted successfully');
    }

    /**
     * Drop a message into the portal's own notification list. Written straight
     * to the table because the portal is a separate application sharing this
     * database — deliberately in-app only, so an approval never sends mail from
     * the back office.
     */
    private function notifyPortalVendor(Vendor $vendor, string $action, ?string $remarks = null): void
    {
        if (! Schema::hasTable('portal_notifications')) {
            return;
        }

        [$title, $message] = match ($action) {
            'approve', 'reactivate' => [
                'Account activated',
                'Your vendor account has been approved. You now have full portal access.',
            ],
            'reject' => [
                'Registration rejected',
                'Your vendor registration was not approved' . ($remarks ? ': ' . $remarks : '.'),
            ],
            'suspend' => [
                'Account suspended',
                'Your vendor account has been suspended' . ($remarks ? ': ' . $remarks : '.'),
            ],
            'password_reset' => [
                'Password reset',
                'An administrator reset your portal password. Contact them for your new credentials.',
            ],
        };

        DB::table('portal_notifications')->insert([
            'notifiable_type' => 'vendor',
            'notifiable_id' => $vendor->id,
            'type' => $action === 'password_reset' ? 'password_reset' : 'account_status',
            'title' => $title,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
