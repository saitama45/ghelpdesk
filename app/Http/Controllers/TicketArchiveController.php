<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class TicketArchiveController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:settings.view', only: ['index']),
            new Middleware('can:tickets.edit', only: ['restore', 'bulkRestore']),
            new Middleware('can:settings.edit', only: ['purge', 'bulkPurge']),
            new Middleware('can:tickets.delete', only: ['purge', 'bulkPurge']),
        ];
    }

    public function index(Request $request)
    {
        $retention = $this->retention();

        $query = Ticket::onlyTrashed()
            ->where('is_deleted', true)
            ->with([
                'reporter:id,name',
                'assignee:id,name',
                'company:id,name',
                'parent' => fn ($q) => $q->withTrashed()->select('id', 'ticket_key', 'title'),
            ])
            ->withCount([
                'children as archived_children_count' => fn ($q) => $q->withTrashed()->where('is_deleted', true),
                'children as active_children_count' => fn ($q) => $q->where('is_deleted', false),
            ]);

        $this->applyTicketVisibility($query, $request);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('ticket_key', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('reporter', fn ($rq) => $rq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('assignee', fn ($aq) => $aq->where('name', 'like', "%{$search}%"));
            });
        }

        $tickets = $query
            ->orderByDesc('deleted_at')
            ->paginate($request->integer('per_page', 10))
            ->withQueryString();

        $tickets->getCollection()->transform(fn (Ticket $ticket) => $this->serializeTicket($ticket, $retention));

        return Inertia::render('Settings/TicketArchive', [
            'tickets' => $tickets,
            'filters' => [
                'search' => $request->search,
                'per_page' => $request->integer('per_page', 10),
            ],
            'retention' => [
                'value' => $retention['value'],
                'unit' => $retention['unit'],
                'label' => $retention['label'],
                'cutoff' => $this->formatDate($retention['cutoff']),
            ],
        ]);
    }

    public function restore(Request $request, string $ticket)
    {
        $archiveTicket = $this->findArchivedTicket($request, $ticket);
        $count = $this->restoreTickets(collect([$archiveTicket]));

        return redirect()->back()->with('success', "{$count} ticket(s) restored successfully.");
    }

    public function bulkRestore(Request $request)
    {
        $validated = $request->validate([
            'ticket_ids' => 'required|array|min:1',
            'ticket_ids.*' => 'required|uuid|exists:tickets,id',
        ]);

        $tickets = $this->findArchivedTickets($request, $validated['ticket_ids']);

        if ($tickets->isEmpty()) {
            return redirect()->back()->withErrors(['restore' => 'No archived tickets were selected for restore.']);
        }

        $count = $this->restoreTickets($tickets);

        return redirect()->back()->with('success', "{$count} ticket(s) restored successfully.");
    }

    private function restoreTickets($archiveTickets): int
    {
        return DB::transaction(function () use ($archiveTickets) {
            $rootIds = $archiveTickets
                ->map(fn (Ticket $ticket) => $this->restoreRootTicket($ticket)->id)
                ->unique()
                ->values();

            $targets = Ticket::withTrashed()
                ->whereIn('id', $rootIds)
                ->orWhereIn('parent_id', $rootIds)
                ->get()
                ->unique('id');

            foreach ($targets as $target) {
                if (!$target->trashed() && !$target->is_deleted) {
                    continue;
                }

                $target->forceFill(['is_deleted' => false])->save();

                if ($target->trashed()) {
                    $target->restore();
                }
            }

            return $targets->count();
        });
    }

    private function restoreRootTicket(Ticket $ticket): Ticket
    {
        if (!$ticket->parent_id) {
            return $ticket;
        }

        $parent = Ticket::withTrashed()->find($ticket->parent_id);

        if ($parent && $parent->trashed() && $parent->is_deleted) {
            return $parent;
        }

        return $ticket;
    }

    public function purge(Request $request, string $ticket)
    {
        $archiveTicket = $this->findArchivedTicket($request, $ticket);
        $retention = $this->retention();
        [$targets, $blockedReason] = $this->purgeTargets(collect([$archiveTicket]), $retention);

        if ($blockedReason) {
            return redirect()->back()->withErrors([
                'purge' => $blockedReason,
            ]);
        }

        $count = $this->purgeTickets($targets);

        return redirect()->back()->with('success', "{$count} ticket(s) purged permanently.");
    }

    public function bulkPurge(Request $request)
    {
        $validated = $request->validate([
            'ticket_ids' => 'required|array|min:1',
            'ticket_ids.*' => 'required|uuid|exists:tickets,id',
        ]);

        $tickets = $this->findArchivedTickets($request, $validated['ticket_ids']);

        if ($tickets->isEmpty()) {
            return redirect()->back()->withErrors(['purge' => 'No archived tickets were selected for purge.']);
        }

        $retention = $this->retention();
        [$targets, $blockedReason] = $this->purgeTargets($tickets, $retention);

        if ($blockedReason) {
            return redirect()->back()->withErrors([
                'purge' => $blockedReason,
            ]);
        }

        $count = $this->purgeTickets($targets);

        return redirect()->back()->with('success', "{$count} ticket(s) purged permanently.");
    }

    private function findArchivedTicket(Request $request, string $ticketId): Ticket
    {
        $query = Ticket::onlyTrashed()->where('is_deleted', true)->where('id', $ticketId);

        $this->applyTicketVisibility($query, $request);

        return $query->firstOrFail();
    }

    private function findArchivedTickets(Request $request, array $ticketIds)
    {
        $query = Ticket::onlyTrashed()
            ->where('is_deleted', true)
            ->whereIn('id', $ticketIds);

        $this->applyTicketVisibility($query, $request);

        return $query->get();
    }

    private function purgeTargets($archiveTickets, array $retention): array
    {
        $rootIds = $archiveTickets->pluck('id')->unique()->values();

        $targets = Ticket::withTrashed()
            ->whereIn('id', $rootIds)
            ->orWhereIn('parent_id', $rootIds)
            ->get()
            ->unique('id');

        $notArchived = $targets->first(fn (Ticket $target) => !$target->trashed() || !$target->is_deleted);
        if ($notArchived) {
            return [
                $targets,
                "Ticket {$notArchived->ticket_key} is still active. Archive all child tickets before purging its parent.",
            ];
        }

        $notEligible = $targets->first(fn (Ticket $target) => !$this->isPurgeEligible($target, $retention));
        if ($notEligible) {
            $availableAt = $this->formatDate($this->purgeAvailableAt($notEligible, $retention));

            return [
                $targets,
                "Ticket {$notEligible->ticket_key} is not eligible for purge until {$availableAt}.",
            ];
        }

        return [$targets, null];
    }

    private function purgeTickets($targets): int
    {
        DB::transaction(function () use ($targets) {
            $targets
                ->sortByDesc(fn (Ticket $target) => $target->parent_id ? 1 : 0)
                ->each(fn (Ticket $target) => $this->purgeTicket($target));
        });

        return $targets->count();
    }

    private function applyTicketVisibility(Builder $query, Request $request): void
    {
        $user = $request->user();

        if ($user->hasRole('User')) {
            $query->where('reporter_id', $user->id);
            return;
        }

        if ($user->hasRole('Admin')) {
            return;
        }

        $user->loadMissing('roles.companies');
        $allowedCompanyIds = collect();

        foreach ($user->roles as $role) {
            if ($role->companies) {
                $allowedCompanyIds = $allowedCompanyIds->merge($role->companies->pluck('id'));
            }
        }

        if ($user->company_id) {
            $allowedCompanyIds->push($user->company_id);
        }

        $allowedCompanyIds = $allowedCompanyIds->unique();

        if ($allowedCompanyIds->isEmpty()) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('company_id', $allowedCompanyIds);
        }
    }

    private function purgeTicket(Ticket $ticket): void
    {
        $ticket->loadMissing('attachments');

        foreach ($ticket->attachments as $attachment) {
            Storage::disk('public')->delete($attachment->file_storage_path);
        }

        $this->detachTicketReferences($ticket->id);
        $ticket->forceDelete();
    }

    private function detachTicketReferences(string $ticketId): void
    {
        foreach (['pos_requests', 'sap_requests', 'schedule_stores', 'schedules'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'ticket_id')) {
                DB::table($table)->where('ticket_id', $ticketId)->update(['ticket_id' => null]);
            }
        }
    }

    private function retention(): array
    {
        $value = max(1, (int) Setting::get('ticket_retention_value', 6));
        $unit = Setting::get('ticket_retention_unit', 'months');
        $unit = in_array($unit, ['months', 'years'], true) ? $unit : 'months';

        $cutoff = now('Asia/Manila');
        if ($unit === 'years') {
            $cutoff = $cutoff->subYears($value);
        } else {
            $cutoff = $cutoff->subMonths($value);
        }

        $unitLabel = $value === 1 ? rtrim($unit, 's') : $unit;

        return [
            'value' => $value,
            'unit' => $unit,
            'label' => "{$value} {$unitLabel}",
            'cutoff' => $cutoff,
        ];
    }

    private function purgeAvailableAt(Ticket $ticket, array $retention)
    {
        if (!$ticket->deleted_at) {
            return null;
        }

        return $retention['unit'] === 'years'
            ? $ticket->deleted_at->copy()->addYears($retention['value'])
            : $ticket->deleted_at->copy()->addMonths($retention['value']);
    }

    private function isPurgeEligible(Ticket $ticket, array $retention): bool
    {
        return $ticket->deleted_at && $ticket->deleted_at->lte($retention['cutoff']);
    }

    private function serializeTicket(Ticket $ticket, array $retention): array
    {
        return [
            'id' => $ticket->id,
            'ticket_key' => $ticket->ticket_key,
            'title' => $ticket->title,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'deleted_at' => $this->formatDate($ticket->deleted_at),
            'created_at' => $this->formatDate($ticket->created_at),
            'purge_eligible' => $this->isPurgeEligible($ticket, $retention),
            'purge_available_at' => $this->formatDate($this->purgeAvailableAt($ticket, $retention)),
            'reporter' => $ticket->reporter ? ['id' => $ticket->reporter->id, 'name' => $ticket->reporter->name] : null,
            'assignee' => $ticket->assignee ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name] : null,
            'company' => $ticket->company ? ['id' => $ticket->company->id, 'name' => $ticket->company->name] : null,
            'parent' => $ticket->parent ? [
                'id' => $ticket->parent->id,
                'ticket_key' => $ticket->parent->ticket_key,
                'title' => $ticket->parent->title,
            ] : null,
            'archived_children_count' => $ticket->archived_children_count ?? 0,
            'active_children_count' => $ticket->active_children_count ?? 0,
        ];
    }

    private function formatDate($date): ?string
    {
        return $date ? $date->timezone('Asia/Manila')->format('Y-m-d H:i:s') : null;
    }
}
