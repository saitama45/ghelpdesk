<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Weekly helpdesk ticket tally for the DAVID app's Success Rate dashboard.
 *
 * Items opt in through `items.report_key` = "david.{module}". Per ISO week
 * (Monday-Sunday) and module:
 *   incoming = tickets created that week, any status
 *   closed   = those same tickets whose status is now "closed"
 * so closed can never exceed incoming and DAVID's Close Rate stays <= 100%.
 *
 * Scoped to one entity (companies.code, matching DAVID's entities.code).
 * Partner-escalation child tickets (parent_id set) are excluded so one concern
 * is never counted twice, matching the dashboard's own tallies.
 */
class DavidTicketTallyService
{
    public const KEY_PREFIX = 'david.';

    public const MODULES = ['order', 'commit', 'receiving', 'wastage', 'mec', 'sales_upload', 'admin'];

    /**
     * @return array{entity: array, date_from: string, date_to: string, modules: array, weeks: array}|null
     *         null when the entity code is unknown
     */
    public function tally(string $entityCode, Carbon $dateFrom, Carbon $dateTo): ?array
    {
        $company = DB::table('companies')
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($entityCode))])
            ->first(['id', 'code', 'name']);

        if (! $company) {
            return null;
        }

        // Whole weeks only: a partial bucket would understate that week's counts.
        $from = $dateFrom->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $to = $dateTo->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();

        $weeks = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addWeek()) {
            $weeks[$cursor->toDateString()] = [
                'week_start' => $cursor->toDateString(),
                'week_end' => $cursor->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
                'modules' => array_fill_keys(self::MODULES, ['incoming' => 0, 'closed' => 0]),
            ];
        }

        // Narrow columns only - never pull ticket LOB columns over the wire.
        $tickets = DB::table('tickets')
            ->join('items', 'items.id', '=', 'tickets.item_id')
            ->where('tickets.company_id', $company->id)
            ->whereNull('tickets.deleted_at')
            ->whereNull('tickets.parent_id')
            ->where('items.report_key', 'like', self::KEY_PREFIX.'%')
            ->whereBetween('tickets.created_at', [$from, $to])
            ->get(['tickets.created_at', 'tickets.status', 'items.report_key']);

        foreach ($tickets as $ticket) {
            $module = substr((string) $ticket->report_key, strlen(self::KEY_PREFIX));
            $weekStart = Carbon::parse($ticket->created_at)->startOfWeek(Carbon::MONDAY)->toDateString();

            if (! in_array($module, self::MODULES, true) || ! isset($weeks[$weekStart])) {
                continue;
            }

            $weeks[$weekStart]['modules'][$module]['incoming']++;

            if ($ticket->status === 'closed') {
                $weeks[$weekStart]['modules'][$module]['closed']++;
            }
        }

        return [
            'entity' => ['code' => $company->code, 'name' => $company->name],
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'modules' => self::MODULES,
            'weeks' => array_values($weeks),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
