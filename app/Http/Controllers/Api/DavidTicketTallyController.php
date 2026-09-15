<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DavidTicketTallyService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * GET /api/integrations/david/ticket-tally
 *
 * Called server-to-server by the DAVID app (X-Integration-Key) to fill its
 * Success Rate tab. See DavidTicketTallyService for the counting rules.
 */
class DavidTicketTallyController extends Controller
{
    public function __invoke(Request $request, DavidTicketTallyService $service)
    {
        $validated = $request->validate([
            'entity' => ['required', 'string', 'max:50'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $dateFrom = Carbon::parse($validated['date_from']);
        $dateTo = Carbon::parse($validated['date_to']);

        if ($dateFrom->diffInDays($dateTo) > 400) {
            return response()->json(['message' => 'The date range may not exceed 400 days.'], 422);
        }

        $tally = $service->tally($validated['entity'], $dateFrom, $dateTo);

        if ($tally === null) {
            return response()->json(['message' => "Unknown entity code [{$validated['entity']}]."], 422);
        }

        return response()->json($tally);
    }
}
