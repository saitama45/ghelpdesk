<?php

namespace App\Http\Controllers;

use App\Mail\AccountDeletionCodeMail;
use App\Mail\AccountDeletionRequestedMail;
use App\Models\Company;
use App\Models\OtpCode;
use App\Models\Scopes\ActiveEntityScope;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AutoAssigneeService;
use App\Services\EmailCodeService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The public account-deletion page for the mobile loyalty app (Google Play's
 * "Delete account URL", opened from the app's Profile → Delete Account).
 *
 * The page files the request itself instead of telling the member to write
 * an email: they enter their email, receive a one-time code, enter it, and a
 * ticket is raised — the same ticket an emailed request would have become,
 * so the support desk handles it exactly as before. The code is what proves
 * the requester owns the account; without it anyone could file a deletion
 * for anyone.
 *
 * Deliberately plain Blade forms (post/redirect/get, session-held step), not
 * Inertia: Google's reviewer must be able to read and use it with no JS.
 * `/code` answers an unknown email exactly like a real one, so the page
 * cannot be used to discover who is a member.
 */
class PublicAccountDeletionController extends Controller
{
    private const VALID_MINUTES = 10;
    private const RESEND_COOLDOWN_SECONDS = 30;
    private const HOURLY_SEND_LIMIT = 5;

    public const TICKET_TITLE = 'Account Deletion Request';

    public function __construct(private readonly EmailCodeService $codes) {}

    public function show(Request $request)
    {
        // The contact address is whatever mailbox is configured on /settings → Mail:
        // the IMAP account is the one whose inbound mail becomes tickets. Still
        // shown for follow-ups, and as the fallback if the form is unavailable.
        $supportEmail = config('imap.accounts.default.username')
            ?: config('mail.from.address')
            ?: 'tgiservices@tablegroup.com';

        // Quotes the same window AccountArchiveController::retention() enforces: an
        // archived account can't be purged before it passes, so the page must state
        // the live value. A settings failure must not take this public page down.
        try {
            $retentionValue = max(1, (int) Setting::get('account_retention_value', 6));
            $retentionUnit = Setting::get('account_retention_unit', 'months');
        } catch (\Throwable $e) {
            [$retentionValue, $retentionUnit] = [6, 'months'];
        }
        $retentionUnit = in_array($retentionUnit, ['months', 'years'], true) ? $retentionUnit : 'months';
        $retention = $retentionValue.' '.($retentionValue === 1 ? rtrim($retentionUnit, 's') : $retentionUnit);

        $view = resource_path('views/public/account-deletion.blade.php');
        $step = $request->session()->get('account_deletion.step', 'email');

        return response()
            ->view('public.account-deletion', [
                'supportEmail' => $supportEmail,
                'developer' => 'Table Group Inc.',
                'retention' => $retention,
                'updatedAt' => date('F j, Y', is_file($view) ? filemtime($view) : time()),
                'step' => $step,
                'requestEmail' => $request->session()->get('account_deletion.email'),
                'ticketKey' => $request->session()->get('account_deletion.ticket_key'),
                'codeMinutes' => self::VALID_MINUTES,
            ])
            // Now stateful per visitor (the form's step lives in the session).
            ->header('Cache-Control', 'private, no-store');
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);
        $email = mb_strtolower(trim($validated['email']));

        // Keyed on the address, not the account, so an unknown email is
        // throttled exactly like a real one.
        $emailKey = sha1($email);
        $cooldownKey = "account-deletion-send-cooldown:{$emailKey}";
        $hourlyKey = "account-deletion-send-hourly:{$emailKey}";

        foreach ([$cooldownKey => 1, $hourlyKey => self::HOURLY_SEND_LIMIT] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return back()->withInput()->withErrors([
                    'email' => 'Please wait '.RateLimiter::availableIn($key).' seconds before requesting another code.',
                ]);
            }
        }

        $member = $this->findMember($email);
        if ($member) {
            $code = $this->codes->issue($member, OtpCode::PURPOSE_ACCOUNT_DELETION, self::VALID_MINUTES);

            try {
                Mail::to($member->email)->send(new AccountDeletionCodeMail($member, $code, self::VALID_MINUTES));
            } catch (\Throwable $e) {
                Log::error("Account deletion: code mail to {$member->email} failed: {$e->getMessage()}");

                return back()->withInput()->withErrors([
                    'email' => 'We could not send the code right now. Please try again in a few minutes.',
                ]);
            }
        }

        RateLimiter::hit($cooldownKey, self::RESEND_COOLDOWN_SECONDS);
        RateLimiter::hit($hourlyKey, 3600);

        $request->session()->put('account_deletion.step', 'code');
        $request->session()->put('account_deletion.email', $email);
        $request->session()->forget('account_deletion.ticket_key');

        return redirect()->to(route('public.account-deletion').'#request');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $email = $request->session()->get('account_deletion.email');
        if (! $email || $request->session()->get('account_deletion.step') !== 'code') {
            return $this->restart($request);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Tick the box to confirm you want your account deleted.',
        ]);

        $member = $this->findMember($email);
        $result = $this->codes->check($member, OtpCode::PURPOSE_ACCOUNT_DELETION, $validated['code']);

        if ($result['status'] !== EmailCodeService::OK) {
            $message = match ($result['status']) {
                EmailCodeService::WRONG => "Incorrect code. {$result['remaining']} ".($result['remaining'] === 1 ? 'attempt' : 'attempts').' left.',
                EmailCodeService::LOCKED => 'Too many attempts. Request a new code.',
                default => 'That code has expired. Request a new one.',
            };

            return redirect()->to(route('public.account-deletion').'#request')
                ->withInput($request->except('code'))
                ->withErrors(['code' => $message]);
        }

        $ticket = DB::transaction(function () use ($result, $member, $validated, $request) {
            $result['otp']->update(['consumed_at' => now()]);

            return $this->openTicketFor($member, $validated['reason'] ?? null, $request->ip());
        });

        try {
            Mail::to($member->email)->send(new AccountDeletionRequestedMail($member, $ticket->ticket_key));
        } catch (\Throwable $e) {
            // The ticket is the record that matters; a missed confirmation mail
            // must not make the member think the request failed.
            Log::error("Account deletion: confirmation mail for {$ticket->ticket_key} failed: {$e->getMessage()}");
        }

        $request->session()->put('account_deletion.step', 'done');
        $request->session()->put('account_deletion.ticket_key', $ticket->ticket_key);

        return redirect()->to(route('public.account-deletion').'#request');
    }

    public function restart(Request $request): RedirectResponse
    {
        $request->session()->forget(['account_deletion.step', 'account_deletion.email', 'account_deletion.ticket_key']);

        return redirect()->to(route('public.account-deletion').'#request');
    }

    /**
     * Reuses the member's still-open request instead of stacking duplicates —
     * a member who submits twice should see one ticket on the desk.
     */
    private function openTicketFor(User $member, ?string $reason, ?string $ip): Ticket
    {
        $existing = Ticket::withoutGlobalScope(ActiveEntityScope::class)
            ->where('sender_email', $member->email)
            ->where('title', self::TICKET_TITLE)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->latest('created_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        $customer = $member->customer;
        $lines = [
            'A member requested deletion of their loyalty app account and all associated data.',
            'The request was verified with a one-time code sent to the registered email address.',
            '',
            'Name: '.$member->name,
            'Email: '.$member->email,
            'Mobile: '.($customer?->phone ?: '—'),
            'User ID: '.$member->id,
            'Customer ID: '.($customer?->id ?? '—'),
            'Submitted: '.now('Asia/Manila')->format('M j, Y g:i A').' (Asia/Manila) from '.($ip ?: 'unknown IP'),
        ];
        if ($reason !== null && trim($reason) !== '') {
            $lines[] = '';
            $lines[] = 'Reason given: '.trim($reason);
        }
        $lines[] = '';
        $lines[] = 'Process it on Settings → Account Archive (archive now, purge after the retention period).';

        // Same defaults an emailed request to the support mailbox gets
        // (EmailTicketService): TGI entity, shared intake pool, the sender's
        // auto-assign rules. Key generation is left to TicketObserver.
        $companyId = Company::where('code', CompanyContext::DEFAULT_COMPANY_CODE)->value('id')
            ?? Company::orderBy('id')->value('id');

        $ticket = Ticket::create([
            'title' => self::TICKET_TITLE,
            'description' => implode("\n", $lines),
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'reporter_id' => $member->id,
            'sender_email' => $member->email,
            'sender_name' => mb_substr($member->name, 0, 255),
            'company_id' => $companyId,
        ]);

        $resolved = app(AutoAssigneeService::class)->resolveAssignee($member->email);
        $update = [];
        if ($resolved['assignee_id'] && User::whereKey($resolved['assignee_id'])->exists()) {
            $update['assignee_id'] = $resolved['assignee_id'];
        }
        if ($resolved['company_id']) {
            $update['company_id'] = $resolved['company_id'];
        }
        if ($update) {
            $ticket->update($update);
        }

        return $ticket->refresh();
    }

    /** Loyalty members only — staff accounts are closed through HR, not this page. */
    private function findMember(string $email): ?User
    {
        return User::where('email', $email)
            ->where('is_active', true)
            ->whereNotNull('customer_id')
            ->first();
    }
}
