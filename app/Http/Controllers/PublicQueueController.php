<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AutoAssigneeService;
use App\Services\QueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PublicQueueController extends Controller
{
    private const PRIORITY_RANK = ['low' => 1, 'medium' => 2, 'high' => 3, 'urgent' => 4];

    public function __construct(
        private QueueService $queue,
        private AutoAssigneeService $autoAssignee
    ) {}

    // ----------------------------------------------------------------------
    // Big-screen lobby board (masked, tokenised, no auth)
    // ----------------------------------------------------------------------

    public function board(string $token)
    {
        $companyId = $this->companyIdForToken('board', $token);

        return Inertia::render('Public/QueueBoard', [
            'board' => $this->maskedBoard($companyId),
            'token' => $token,
            'refreshSeconds' => (int) Setting::get('queue_refresh_seconds', 7),
            'orgName' => Setting::get('queue_board_title', 'Support Queue'),
        ]);
    }

    public function boardData(string $token)
    {
        $companyId = $this->companyIdForToken('board', $token);

        return response()->json($this->maskedBoard($companyId));
    }

    /**
     * Reduce the full board to lobby-safe fields (no titles, names, items).
     */
    private function maskedBoard(int $companyId): array
    {
        $board = $this->queue->board($companyId);
        $hidden = $this->maskFields();

        $board['lanes'] = array_map(function ($lane) use ($hidden) {
            $lane['now_serving'] = array_map(fn ($c) => $this->maskCard($c, $hidden), $lane['now_serving']);
            $lane['waiting'] = array_map(fn ($c) => $this->maskCard($c, $hidden), $lane['waiting']);
            // On-hold is shown as a count only on the public board.
            unset($lane['on_hold']);

            return $lane;
        }, $board['lanes']);

        return $board;
    }

    /** @return array<int,string> */
    private function maskFields(): array
    {
        $raw = Setting::get('queue_public_mask', '["title","requester","assignee","item"]');
        $fields = is_array($raw) ? $raw : (json_decode($raw, true) ?: ['title', 'requester', 'assignee', 'item']);

        return array_values(array_filter(array_map('strval', $fields)));
    }

    private function maskCard(array $card, array $hidden): array
    {
        // Drop the internal UUID and any configured sensitive fields.
        unset($card['id']);
        foreach ($hidden as $field) {
            unset($card[$field]);
        }

        return $card;
    }

    // ----------------------------------------------------------------------
    // Track my ticket (per-requester, tokenised, no auth)
    // ----------------------------------------------------------------------

    public function track(string $token)
    {
        $ticket = $this->ticketByToken($token);

        return Inertia::render('Public/QueueTrack', [
            'token' => $token,
            'ticketKey' => $ticket->ticket_key,
            'info' => $this->queue->positionInfoFor($ticket, $ticket->company_id),
            'refreshSeconds' => (int) Setting::get('queue_refresh_seconds', 7),
        ]);
    }

    public function trackData(string $token)
    {
        $ticket = $this->ticketByToken($token);

        return response()->json([
            'ticketKey' => $ticket->ticket_key,
            'info' => $this->queue->positionInfoFor($ticket, $ticket->company_id),
        ]);
    }

    private function ticketByToken(string $token): Ticket
    {
        return Ticket::withoutGlobalScope(\App\Models\Scopes\ActiveEntityScope::class)
            ->where('queue_track_token', $token)
            ->whereNull('deleted_at')
            ->firstOrFail();
    }

    // ----------------------------------------------------------------------
    // Walk-in kiosk (no auth) — creates a real ticket + issues a number
    // ----------------------------------------------------------------------

    public function kiosk(string $token)
    {
        $companyId = $this->companyIdForToken('kiosk', $token);

        return Inertia::render('Public/QueueKiosk', [
            'token' => $token,
            'orgName' => Setting::get('queue_board_title', 'Support Queue'),
            'stores' => Store::where('is_active', true)
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'name', 'company_id']),
            'items' => Item::where('is_active', true)
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'name', 'priority']),
            'requireEmail' => (bool) Setting::get('queue_kiosk_require_email', false),
        ]);
    }

    public function kioskStore(Request $request, string $token)
    {
        $companyId = $this->companyIdForToken('kiosk', $token);

        $requireEmail = (bool) Setting::get('queue_kiosk_require_email', false);

        $validated = $request->validate([
            'sender_name' => 'required|string|max:255',
            'sender_email' => ($requireEmail ? 'required' : 'nullable') . '|email|max:255',
            'department' => 'nullable|string|max:255',
            'store_id' => [
                'nullable',
                Rule::exists('stores', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('is_active', true)),
            ],
            'item_id' => [
                'nullable',
                Rule::exists('items', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('is_active', true)),
            ],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
        ]);

        $ticket = DB::transaction(function () use ($validated, $companyId) {
            $data = [
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'type' => 'task',
                'status' => 'open',
                'severity' => 'minor',
                'priority' => 'medium',
                'channel' => 'walk_in',
                'reporter_id' => null,
                'sender_name' => $validated['sender_name'],
                'sender_email' => $validated['sender_email'] ?? null,
                'department' => $validated['department'] ?? null,
                'company_id' => $companyId,
                'store_id' => $validated['store_id'] ?? null,
                'item_id' => $validated['item_id'] ?? null,
                'created_at' => now('Asia/Manila'),
            ];

            // Item drives priority/category/sub-category (mirrors TicketController@store).
            if (!empty($data['item_id'])) {
                $item = Item::find($data['item_id']);
                if ($item) {
                    $data['priority'] = strtolower($item->priority);
                    $data['category_id'] = $item->category_id;
                    $data['sub_category_id'] = $item->sub_category_id;
                }
            }

            // Walk-ins are physically present: never let them fall below the floor.
            $data['priority'] = $this->applyPriorityFloor($data['priority']);

            // Auto-assign (by email rule → defaults). Resolving an assignee also
            // gives the ticket its department lane.
            $lookupEmail = $data['sender_email'] ?? '';
            if ($lookupEmail) {
                $resolved = $this->autoAssignee->resolveAssignee($lookupEmail);
                if ($resolved['assignee_id'] && User::whereKey($resolved['assignee_id'])->exists()) {
                    $data['assignee_id'] = $resolved['assignee_id'];
                }
            }

            return Ticket::create($data);
        });

        // Redirect to the live "Track my ticket" page — this is the kiosk slip.
        return redirect()->route('public.queue.track', $ticket->ensureTrackToken());
    }

    private function applyPriorityFloor(string $priority): string
    {
        $floor = strtolower((string) Setting::get('queue_walkin_priority_floor', 'medium'));
        $floorRank = self::PRIORITY_RANK[$floor] ?? 2;
        $rank = self::PRIORITY_RANK[strtolower($priority)] ?? 2;

        return $rank >= $floorRank ? $priority : $floor;
    }

    // ----------------------------------------------------------------------

    private function companyIdForToken(string $type, string $token): int
    {
        $prefix = $type === 'board'
            ? 'queue_board_token_company_'
            : 'queue_kiosk_token_company_';

        $setting = Setting::where('key', 'like', $prefix . '%')
            ->where('value', $token)
            ->first();

        abort_if(!$setting || !hash_equals((string) $setting->value, $token), 404);

        $companyId = (int) substr($setting->key, strlen($prefix));
        abort_if($companyId <= 0, 404);

        return $companyId;
    }
}
