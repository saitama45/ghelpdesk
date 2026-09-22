<?php

namespace App\Support;

use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\TicketStatusVisibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The ticket status catalogue and its per-department visibility.
 *
 * The seven SYSTEM keys drive behaviour — SLA pausing, the schedule runner, the
 * queue and every report branch on them — so they are never renamed at the key
 * level, only relabelled. A CUSTOM status (References → Ticket Statuses) always
 * `behaves_like` one non-terminal system status, and every behavioural check
 * goes through behavior() / like() so the custom status follows it.
 *
 * Visibility is per department (a department belongs to one entity). A system
 * status is visible unless a department hides it; a custom status is hidden
 * unless a department switches it on.
 */
final class TicketStatuses
{
    /** System statuses in picker order; the fallback before the catalogue table exists. */
    public const LABELS = [
        'open' => 'Open',
        'for_schedule' => 'For Schedule',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        'waiting_service_provider' => 'Waiting for Service Provider',
        'waiting_client_feedback' => "Waiting for Client's Feedback",
    ];

    public const DEFAULT_COLORS = [
        'open' => 'blue', 'for_schedule' => 'teal', 'in_progress' => 'violet', 'resolved' => 'green',
        'closed' => 'slate', 'waiting_service_provider' => 'orange', 'waiting_client_feedback' => 'sky',
    ];

    /** Palette names the UI maps to classes (resources/js/Composables/useTicketStatuses.js). */
    public const COLORS = ['blue', 'sky', 'cyan', 'teal', 'green', 'lime', 'amber', 'orange', 'red', 'pink', 'violet', 'indigo', 'slate'];

    /**
     * The lifecycle's entry and exit: intake always creates `open`, and the
     * resolve → close loop drives resolution SLA and requester confirmation.
     * Hiding any of these would leave tickets with no way in or out.
     */
    public const REQUIRED = ['open', 'resolved', 'closed'];

    /** What a custom status may behave like: every non-terminal system status. */
    public const BEHAVIORS = ['open', 'for_schedule', 'in_progress', 'waiting_service_provider', 'waiting_client_feedback'];

    /** SLA clock stops while a ticket is in one of these (or a status behaving like one). */
    public const PAUSED = ['waiting_service_provider', 'waiting_client_feedback', 'for_schedule'];

    private static ?Collection $catalogue = null;

    /** @var array<int|string, list<string>> */
    private static array $hidden = [];

    /** Ordered catalogue rows: key, label, color, behaves_like, is_system, sort_order. */
    public static function all(): Collection
    {
        if (self::$catalogue === null) {
            self::$catalogue = Schema::hasTable('ticket_statuses')
                ? TicketStatus::orderBy('sort_order')->orderBy('id')
                    ->get(['id', 'key', 'label', 'color', 'behaves_like', 'is_system', 'sort_order'])
                : collect(self::LABELS)->map(fn ($label, $key) => new TicketStatus([
                    'key' => $key, 'label' => $label, 'color' => self::DEFAULT_COLORS[$key], 'is_system' => true,
                ]))->values();
        }

        return self::$catalogue;
    }

    public static function keys(): array
    {
        return self::all()->pluck('key')->all();
    }

    public static function label(?string $key): string
    {
        return (string) (self::all()->firstWhere('key', $key)?->label ?? ucwords(str_replace('_', ' ', (string) $key)));
    }

    public static function isSystem(?string $key): bool
    {
        return array_key_exists((string) $key, self::LABELS);
    }

    /** The system status whose behaviour this status shares (itself for a system status). */
    public static function behavior(?string $key): ?string
    {
        if (! $key || self::isSystem($key)) {
            return $key;
        }

        return self::all()->firstWhere('key', $key)?->behaves_like ?? $key;
    }

    /** The given system keys plus every custom status that behaves like one of them. */
    public static function like(array $systemKeys): array
    {
        $custom = self::all()
            ->filter(fn ($s) => ! $s->is_system && in_array($s->behaves_like, $systemKeys, true))
            ->pluck('key')->all();

        return array_values(array_unique([...$systemKeys, ...$custom]));
    }

    /** Shared with every page so labels and colours follow the catalogue. */
    public static function payload(): array
    {
        return self::all()->map(fn ($s) => [
            'key' => $s->key, 'label' => $s->label, 'color' => $s->color,
            'behaves_like' => $s->behaves_like, 'is_system' => (bool) $s->is_system,
        ])->values()->all();
    }

    /** Status keys the department's desk may not pick. */
    public static function hiddenFor(?int $departmentId): array
    {
        $cacheKey = $departmentId ?? 'none';

        if (! array_key_exists($cacheKey, self::$hidden)) {
            $rows = $departmentId && Schema::hasTable('ticket_status_visibilities')
                ? TicketStatusVisibility::where('department_id', $departmentId)->pluck('is_visible', 'status')
                : collect();

            self::$hidden[$cacheKey] = self::all()
                ->filter(fn ($s) => ! in_array($s->key, self::REQUIRED, true))
                // System: shown unless hidden. Custom: hidden unless switched on.
                ->filter(fn ($s) => $rows->has($s->key) ? ! $rows->get($s->key) : ! $s->is_system)
                ->pluck('key')->values()->all();
        }

        return self::$hidden[$cacheKey];
    }

    public static function flush(): void
    {
        self::$catalogue = null;
        self::$hidden = [];
    }

    /**
     * Refuse an unknown status, or one the ticket's department has hidden.
     * Keeping the current status is always allowed, and system transitions
     * (child tickets, the schedule runner) never pass through here.
     */
    public static function assertSelectable(?string $status, ?int $departmentId, ?string $currentStatus = null): void
    {
        if (! $status || $status === $currentStatus) {
            return;
        }

        if (! in_array($status, self::keys(), true)) {
            throw ValidationException::withMessages(['status' => 'Choose a valid ticket status.']);
        }

        if (in_array($status, self::hiddenFor($departmentId), true)) {
            throw ValidationException::withMessages([
                'status' => 'The "'.self::label($status).'" status is not used by this ticket\'s department.',
            ]);
        }
    }

    public static function assertSelectableForTicket(?string $status, Ticket $ticket): void
    {
        self::assertSelectable($status, TicketAccess::servingDepartmentId($ticket), $ticket->status);
    }
}
