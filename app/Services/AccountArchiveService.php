<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use App\Support\UserDeletionBlockers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The single place that knows a mobile-app member is TWO rows — a `users` login
 * and a `customers` CRM record joined by `users.customer_id` (see
 * `Api\RegisterController`, which creates both).
 *
 * Deleting from /users or from /stamps → Customers must therefore do the same
 * thing whichever side the click came from: archive the pair together, restore
 * the pair together, purge the pair together. Anything less leaves a login with
 * no customer (invisible in Stamps but still able to sign in) or a customer with
 * a dangling login.
 *
 * Nothing here is destructive except `purge*`, which is reachable only from
 * Settings → Account Archive, only after the retention window, and only with
 * both `settings.edit` and the module's own delete permission.
 */
class AccountArchiveService
{
    public function __construct(private UserDeletionBlockers $blockers) {}

    /* ----------------------------------------------------------------------
     | Pair resolution
     * ------------------------------------------------------------------- */

    /**
     * The customer record behind a login, archived or not.
     *
     * `withTrashed()` matters: once the pair is archived, the plain relation
     * would return null and the partner would be stranded.
     */
    public function customerFor(User $user): ?Customer
    {
        if (! $user->customer_id) {
            return null;
        }

        return Customer::withTrashed()->find($user->customer_id);
    }

    /** The login behind a customer record, archived or not. Null for a walk-in. */
    public function userFor(Customer $customer): ?User
    {
        return User::withTrashed()->where('customer_id', $customer->id)->first();
    }

    /* ----------------------------------------------------------------------
     | Archive (what the delete icon on /users and /stamps now does)
     * ------------------------------------------------------------------- */

    /**
     * Archive a login and, when it is a mobile-app member, their customer record.
     *
     * Deliberately does NOT touch the user's tickets, schedules, attendance or
     * task-board rows. The old hard delete cleared all of that up front; an
     * archive that did the same could not be undone, which defeats the point.
     * That cleanup now runs in `purgeUser()` instead.
     *
     * @return array{user: string, customer: ?string} names of what was archived
     */
    public function archiveUser(User $user, ?int $actorId): array
    {
        return DB::transaction(function () use ($user, $actorId) {
            $customer = $this->customerFor($user);

            $this->archiveRow($user, $actorId);

            if ($customer && ! $customer->trashed()) {
                $this->archiveRow($customer, $actorId);
            }

            Log::info('Account archived from user management', [
                'user_id' => $user->id,
                'customer_id' => $customer?->id,
                'archived_by' => $actorId,
            ]);

            return ['user' => $user->name, 'customer' => $customer?->name];
        });
    }

    /**
     * Archive a customer record and, when they have registered in the mobile app,
     * the login that belongs to it.
     *
     * @return array{customer: string, user: ?string}
     */
    public function archiveCustomer(Customer $customer, ?int $actorId): array
    {
        return DB::transaction(function () use ($customer, $actorId) {
            $user = $this->userFor($customer);

            $this->archiveRow($customer, $actorId);

            if ($user && ! $user->trashed()) {
                $this->archiveRow($user, $actorId);
            }

            Log::info('Account archived from stamps customers', [
                'customer_id' => $customer->id,
                'user_id' => $user?->id,
                'archived_by' => $actorId,
            ]);

            return ['customer' => $customer->name, 'user' => $user?->name];
        });
    }

    private function archiveRow(Model $row, ?int $actorId): void
    {
        // Stamped before the delete so the archive page can say who did it —
        // saving after the row is trashed would need an unscoped write.
        $row->forceFill(['deleted_by' => $actorId])->saveQuietly();
        $row->delete();
    }

    /* ----------------------------------------------------------------------
     | Restore
     * ------------------------------------------------------------------- */

    /** @return array{user: ?string, customer: ?string} */
    public function restoreUser(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $customer = $this->customerFor($user);

            $this->restoreRow($user);

            if ($customer && $customer->trashed()) {
                $this->restoreRow($customer);
            }

            return ['user' => $user->name, 'customer' => $customer?->name];
        });
    }

    /** @return array{user: ?string, customer: ?string} */
    public function restoreCustomer(Customer $customer): array
    {
        return DB::transaction(function () use ($customer) {
            $user = $this->userFor($customer);

            $this->restoreRow($customer);

            if ($user && $user->trashed()) {
                $this->restoreRow($user);
            }

            return ['user' => $user?->name, 'customer' => $customer->name];
        });
    }

    private function restoreRow(Model $row): void
    {
        if ($row->trashed()) {
            $row->restore();
        }

        $row->forceFill(['deleted_by' => null])->saveQuietly();
    }

    /* ----------------------------------------------------------------------
     | Purge — permanent, and the only destructive path in this class
     * ------------------------------------------------------------------- */

    /**
     * Why this login cannot be purged yet, or null when it can.
     *
     * The retention check is the caller's job (it needs the settings window);
     * this reports only the structural reasons.
     */
    public function userPurgeBlocker(User $user): ?string
    {
        if (! $user->trashed()) {
            return "{$user->name} is not archived.";
        }

        $customer = $this->customerFor($user);

        if ($customer && ! $customer->trashed()) {
            return "{$user->name} is linked to the active customer record \"{$customer->name}\". Archive the customer first so the pair is purged together.";
        }

        if ($customer) {
            return $this->customerDataBlocker($customer);
        }

        return null;
    }

    /** Why this customer cannot be purged yet, or null when it can. */
    public function customerPurgeBlocker(Customer $customer): ?string
    {
        if (! $customer->trashed()) {
            return "{$customer->name} is not archived.";
        }

        $user = $this->userFor($customer);

        if ($user && ! $user->trashed()) {
            return "{$customer->name} is linked to the active login \"{$user->email}\". Archive the user first so the pair is purged together.";
        }

        return $this->customerDataBlocker($customer);
    }

    /**
     * Redemptions are a settled financial movement — a reward left the store's
     * inventory. Those rows are never deleted to tidy up a member, so a customer
     * holding any is refused rather than silently cascaded away.
     */
    private function customerDataBlocker(Customer $customer): ?string
    {
        $redemptions = $customer->redemptions()->count();
        if ($redemptions > 0) {
            return "\"{$customer->name}\" has {$redemptions} reward redemption(s), which are kept as financial records. This account can stay archived but cannot be purged.";
        }

        if (Schema::hasTable('voucher_redemptions')) {
            $voucherRedemptions = $customer->voucherRedemptions()->count();
            if ($voucherRedemptions > 0) {
                return "\"{$customer->name}\" has {$voucherRedemptions} voucher payment(s), which are kept as financial records. This account can stay archived but cannot be purged.";
            }
        }

        return null;
    }

    /**
     * Permanently remove a login and its paired customer record.
     *
     * This is where the reference cleanup that used to sit in
     * `UserController@destroy` now lives: it is correct for a permanent purge and
     * wrong for a reversible archive.
     */
    public function purgeUser(User $user, ?int $actorId): void
    {
        DB::transaction(function () use ($user, $actorId) {
            $customer = $this->customerFor($user);

            $this->releaseUserReferences($user);

            $remaining = $this->blockers->for($user);

            if (! empty($remaining)) {
                throw ValidationException::withMessages([
                    'purge' => $this->blockers->message($user, $remaining),
                ]);
            }

            Log::warning('User account purged permanently', [
                'user_id' => $user->id,
                'email' => $user->email,
                'customer_id' => $customer?->id,
                'purged_by' => $actorId,
            ]);

            // The login holds the FK into customers, so it must go first.
            $user->forceDelete();

            if ($customer && $customer->trashed()) {
                $this->purgeCustomerRow($customer, $actorId);
            }
        });
    }

    /** Permanently remove a customer record and its paired login. */
    public function purgeCustomer(Customer $customer, ?int $actorId): void
    {
        DB::transaction(function () use ($customer, $actorId) {
            $user = $this->userFor($customer);

            if ($user && $user->trashed()) {
                // Delegates back through the login path so the reference cleanup
                // and blocker scan run exactly once, in the right order.
                $this->purgeUser($user, $actorId);

                return;
            }

            $this->purgeCustomerRow($customer, $actorId);
        });
    }

    private function purgeCustomerRow(Customer $customer, ?int $actorId): void
    {
        $customer->loadMissing('stampCards');

        foreach ($customer->stampCards as $card) {
            // stamp_entries cascade on the card; redemptions would have blocked
            // the purge long before this point.
            $card->delete();
        }

        Log::warning('Customer record purged permanently', [
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'purged_by' => $actorId,
        ]);

        $customer->forceDelete();
    }

    /**
     * Clear everything that points at a user so the row can actually be removed.
     * Lifted verbatim from the old hard-delete path in `UserController@destroy`.
     */
    private function releaseUserReferences(User $user): void
    {
        DB::table('tickets')->where('reporter_id', $user->id)->update(['reporter_id' => null]);
        DB::table('tickets')->where('assignee_id', $user->id)->update(['assignee_id' => null]);

        DB::table('ticket_comments')->where('user_id', $user->id)->update(['user_id' => null]);
        DB::table('ticket_histories')->where('user_id', $user->id)->update(['user_id' => null]);

        DB::table('project_tasks')->where('assigned_to', $user->id)->update(['assigned_to' => null]);
        DB::table('project_tasks')->where('support_by', $user->id)->update(['support_by' => null]);

        if (Schema::hasTable('inventory_transactions')) {
            DB::table('inventory_transactions')->where('created_by', $user->id)->update(['created_by' => null]);
            DB::table('inventory_transactions')->where('updated_by', $user->id)->update(['updated_by' => null]);
        }

        if (Schema::hasTable('sap_requests')) {
            DB::table('sap_requests')->where('user_id', $user->id)->update(['user_id' => null]);
        }
        if (Schema::hasTable('sap_request_approvals')) {
            DB::table('sap_request_approvals')->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('pos_requests')) {
            DB::table('pos_requests')->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('pos_request_approvals')) {
            DB::table('pos_request_approvals')->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('schedule_change_requests')) {
            DB::table('schedule_change_requests')->where('requester_id', $user->id)->delete();
            DB::table('schedule_change_requests')->where('approved_by', $user->id)->update(['approved_by' => null]);
            DB::table('schedule_change_requests')->where('rejected_by', $user->id)->update(['rejected_by' => null]);
        }

        if (Schema::hasTable('attendance_logs')) {
            DB::table('attendance_logs')->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('schedules')) {
            DB::table('schedules')->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('user_presence_logs')) {
            DB::table('user_presence_logs')->where('user_id', $user->id)->delete();
        }

        if (Schema::hasTable('task_board_members')) {
            DB::table('task_board_members')->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('task_board_watchers')) {
            DB::table('task_board_watchers')->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('task_card_assignees')) {
            DB::table('task_card_assignees')->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('task_card_watchers')) {
            DB::table('task_card_watchers')->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('task_card_comments')) {
            DB::table('task_card_comments')->where('user_id', $user->id)->delete();
        }

        DB::table('manager_user')->where('manager_id', $user->id)->delete();

        DB::table('users')->where('created_by', $user->id)->update(['created_by' => null]);
        DB::table('users')->where('updated_by', $user->id)->update(['updated_by' => null]);
        DB::table('users')->where('deleted_by', $user->id)->update(['deleted_by' => null]);
    }
}
