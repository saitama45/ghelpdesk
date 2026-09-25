<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AccountClosedMail;
use App\Services\AccountArchiveService;
use App\Services\AccountDeletionRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Member-initiated account deletion from inside the mobile loyalty app.
 *
 * App Store Review Guideline 5.1.1(v) requires an app that lets people create
 * an account to let them delete it from inside the app. The public
 * `/account-deletion` page stays published — Google Play links to it, and it
 * is the route for someone who has lost access to the app — but a member with
 * the app in their hand no longer has to leave it.
 *
 * **Why this closes the account and the web page does not.** The web page has
 * to email a one-time code because whoever filled the form is anonymous, and
 * the desk archives afterwards from Settings → Account Archive → Deletion
 * Requests. A caller here already holds a valid session token AND has just
 * re-entered their password, which is stronger proof of ownership than that
 * code, so there is nothing left for a human to verify and queueing it would
 * only delay a deletion Apple expects to take effect. It still files the same
 * `Account Deletion Request` ticket — the desk needs the record and the thread
 * to reply on — but the ticket says the account is already archived.
 *
 * Archiving is a soft delete of the `users` + `customers` pair, exactly what
 * the delete icon on /users does. Permanent removal happens afterwards from
 * Settings → Account Archive once the published retention window has passed,
 * which is what `/account-deletion` promises; keep the two in step.
 */
class AccountController extends Controller
{
    public function destroy(
        Request $request,
        AccountArchiveService $archive,
        AccountDeletionRequestService $requests,
    ) {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();

        // Self-deletion is for loyalty members only, and a member is a login
        // with a `customers` row behind it — the same definition the public
        // page (`PublicAccountDeletionController::findMember`) and the archive
        // desk (`AccountArchiveController::deletionRequestsQuery`) both use.
        //
        // Deliberately NOT "has no roles": a member who also holds a role is
        // still a member, and refusing them here would let an unrelated
        // permission grant silently take away a deletion right Apple requires.
        // A pure staff login has no `customers` row and is closed from /users
        // by an administrator instead.
        if ($user->customer_id === null) {
            return response()->json([
                'message' => 'This account is not a loyalty membership. Staff accounts are closed by your administrator.',
            ], 403);
        }

        if (! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'That password is incorrect.',
            ], 422);
        }

        // The ticket is raised BEFORE the archive: it reads the member's
        // customer record for the phone number and customer id, and archiving
        // soft-deletes that row out from under the relation.
        $ticket = DB::transaction(function () use ($user, $validated, $request, $requests, $archive) {
            $ticket = $requests->openFor(
                $user,
                $validated['reason'] ?? null,
                $request->ip(),
                AccountDeletionRequestService::CHANNEL_APP,
            );

            $archive->archiveUser($user, $user->id);

            return $ticket;
        });

        // Every device the member was signed in on, not just this one — the
        // account is closed, so no token that names it may keep working.
        $user->tokens()->delete();

        try {
            Mail::to($user->email)->send(new AccountClosedMail($user, $ticket->ticket_key));
        } catch (\Throwable $e) {
            // The account really is closed; a failed notification must not turn
            // that into an error the member sees and retries.
            Log::error("Account deletion: closure mail for {$ticket->ticket_key} failed: {$e->getMessage()}");
        }

        Log::info('Account closed from the mobile app', [
            'user_id' => $user->id,
            'ticket' => $ticket->ticket_key,
        ]);

        return response()->json([
            'message' => 'Your account has been closed.',
            'reference' => $ticket->ticket_key,
        ]);
    }
}
