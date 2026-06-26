<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Setting;
use App\Services\QueueService;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class QueueController extends Controller
{
    public function __construct(private QueueService $queue) {}

    /**
     * Internal detailed queue board + agent console.
     */
    public function index(Request $request)
    {
        $companyId = CompanyContext::activeCompanyId();
        $this->ensureTokens($companyId);
        $canManage = $request->user()->can('settings.edit');

        return Inertia::render('Queue/Index', [
            'board' => $this->queue->board(),
            'config' => $this->config($companyId),
            'canOperate' => $request->user()->can('queue.operate'),
            'canManage' => $canManage,
            'settings' => $canManage ? $this->managedSettings() : null,
            'companies' => $canManage
                ? Company::where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    /**
     * JSON board for live polling (internal, detailed).
     */
    public function data()
    {
        return response()->json($this->queue->board());
    }

    /**
     * Pull the next waiting ticket in a lane: set it In Progress, take ownership,
     * stamp called_at. The TicketObserver handles SLA resume + side effects.
     */
    public function callNext(Request $request)
    {
        $validated = $request->validate([
            'lane' => 'required|string|max:50',
        ]);

        $ticket = $this->queue->claimNextWaitingTicket($validated['lane'], $request->user());

        if (!$ticket) {
            return redirect()->back()->with('info', 'No one is waiting in this lane.');
        }

        return redirect()->back()->with('success', "Now serving {$ticket->ticket_key}.");
    }

    /**
     * Rotate the public board or kiosk token (invalidates the old link).
     */
    public function regenerateToken(Request $request)
    {
        abort_unless($request->user()->can('settings.edit'), 403);

        $validated = $request->validate([
            'type' => 'required|in:board,kiosk',
        ]);

        $companyId = CompanyContext::activeCompanyId();
        abort_unless($companyId, 422, 'No active company is selected.');

        $key = $this->tokenKey($validated['type'], $companyId);
        Setting::set($key, Str::random(48), 'queue');

        return redirect()->back()->with('success', ucfirst($validated['type']) . ' link regenerated.');
    }

    /**
     * Generate the public board + kiosk tokens once so the links work out of the box.
     */
    private function ensureTokens(?int $companyId): void
    {
        if (!$companyId) {
            return;
        }

        if (!Setting::get($this->tokenKey('board', $companyId))) {
            Setting::set($this->tokenKey('board', $companyId), Str::random(48), 'queue');
        }
        if (!Setting::get($this->tokenKey('kiosk', $companyId))) {
            Setting::set($this->tokenKey('kiosk', $companyId), Str::random(48), 'queue');
        }
    }

    /**
     * Public-facing URLs + refresh cadence handed to the board UI.
     */
    private function config(?int $companyId): array
    {
        $boardToken = $companyId ? Setting::get($this->tokenKey('board', $companyId)) : null;
        $kioskToken = $companyId ? Setting::get($this->tokenKey('kiosk', $companyId)) : null;

        return [
            'refresh_seconds' => (int) Setting::get('queue_refresh_seconds', 7),
            'public_board_url' => $boardToken ? route('public.queue.board', $boardToken) : null,
            'kiosk_url' => $kioskToken ? route('public.queue.kiosk', $kioskToken) : null,
        ];
    }

    /**
     * Editable queue settings surfaced to managers in the board's settings modal.
     */
    private function managedSettings(): array
    {
        return [
            'queue_board_title' => Setting::get('queue_board_title', 'Support Queue'),
            'queue_refresh_seconds' => (int) Setting::get('queue_refresh_seconds', 7),
            'queue_lane_nodes' => Setting::get('queue_lane_nodes', '["SO","CS"]'),
            'queue_walkin_company_id' => Setting::get('queue_walkin_company_id'),
            'queue_walkin_priority_floor' => Setting::get('queue_walkin_priority_floor', 'medium'),
            'queue_kiosk_require_email' => (bool) Setting::get('queue_kiosk_require_email', false),
        ];
    }

    private function tokenKey(string $type, int $companyId): string
    {
        return $type === 'board'
            ? "queue_board_token_company_{$companyId}"
            : "queue_kiosk_token_company_{$companyId}";
    }
}
