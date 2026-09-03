<?php

namespace App\Services;

use App\Mail\NewTicketCreated;
use App\Mail\TicketAssigned;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/** Sends the email fan-out that follows creation of a ticket. */
class TicketCreationNotifier
{
    public function send(Ticket $ticket, bool $notifyRequester = true): void
    {
        $ticket->loadMissing(['reporter', 'assignee', 'company']);
        $sentTo = [];

        if ($notifyRequester) {
            if ($ticket->reporter?->email) {
                $pending = Mail::to($ticket->reporter->email);
                $cc = $this->attachTicketCcs($pending, $ticket, [$ticket->reporter->email]);
                $pending->send(new NewTicketCreated($ticket, $ticket->reporter->name));
                $sentTo[] = strtolower($ticket->reporter->email);
                $sentTo = array_merge($sentTo, $cc);
            } elseif ($ticket->sender_email) {
                $pending = Mail::to($ticket->sender_email);
                $cc = $this->attachTicketCcs($pending, $ticket, [$ticket->sender_email]);
                $pending->send(new NewTicketCreated($ticket, $ticket->sender_name ?? 'External User'));
                $sentTo[] = strtolower($ticket->sender_email);
                $sentTo = array_merge($sentTo, $cc);
            }
        }

        $assigneeEmail = strtolower((string) $ticket->assignee?->email);
        if ($assigneeEmail !== '' && $ticket->assignee_id !== $ticket->reporter_id) {
            $shouldNotifyAssignee = $ticket->assignee->roles()
                ->where('notify_on_ticket_assign', true)
                ->exists();

            if ($shouldNotifyAssignee && ! in_array($assigneeEmail, $sentTo, true)) {
                Mail::to($assigneeEmail)->send(new NewTicketCreated($ticket, $ticket->assignee->name));
                $sentTo[] = $assigneeEmail;
            }
        }

        $this->notifyWatchers($ticket, $sentTo);

        if (strtolower((string) $ticket->priority) === 'urgent') {
            $urgentWatchers = User::whereHas('roles', fn ($query) => $query
                ->where('notify_on_urgent_ticket', true))
                ->get();

            foreach ($urgentWatchers as $watcher) {
                $email = strtolower((string) $watcher->email);

                if ($email !== '' && ! in_array($email, $sentTo, true)) {
                    Mail::to($email)->send(new TicketAssigned($ticket, $watcher->name));
                    $sentTo[] = $email;
                }
            }
        }
    }

    private function attachTicketCcs($pendingMail, Ticket $ticket, array $alreadySentTo = []): array
    {
        $excluded = collect($alreadySentTo)
            ->map(fn ($email) => strtolower((string) $email))
            ->all();
        $ccEmails = $ticket->deliverableCcs()->pluck('email');

        if ($ticket->parent_id && ($requester = $ticket->effectiveRequesterRecipient())) {
            $ccEmails->push($requester['email']);
        }

        $ccEmails = $ccEmails
            ->filter()
            ->map(fn ($email) => strtolower((string) $email))
            ->unique()
            ->reject(fn ($email) => in_array($email, $excluded, true))
            ->values()
            ->all();

        if ($ccEmails !== []) {
            $pendingMail->cc($ccEmails);
        }

        return $ccEmails;
    }

    private function notifyWatchers(Ticket $ticket, array &$sentTo): void
    {
        $usersToNotify = User::active()
            ->whereHas('roles', fn ($query) => $query->where('notify_on_ticket_create', true))
            ->with('roles.companies')
            ->get();

        foreach ($usersToNotify as $user) {
            $email = strtolower((string) $user->email);

            if ($email === '' || in_array($email, $sentTo, true) || ! $this->canReceive($user, $ticket)) {
                continue;
            }

            Mail::to($email)->send(new NewTicketCreated($ticket, $user->name));
            $sentTo[] = $email;
        }
    }

    private function canReceive(User $user, Ticket $ticket): bool
    {
        if (! $user->email || ! $user->is_active) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        if ($user->id === $ticket->reporter_id || $user->id === $ticket->assignee_id) {
            return false;
        }

        $allowedCompanyIds = $user->roles
            ->flatMap(fn ($role) => $role->companies?->pluck('id') ?? [])
            ->when($user->company_id, fn ($ids) => $ids->push($user->company_id))
            ->unique();

        return $allowedCompanyIds->contains($ticket->company_id);
    }
}
