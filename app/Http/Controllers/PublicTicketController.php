<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketSurvey;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PublicTicketController extends Controller
{
    /**
     * Close the ticket from a signed email link.
     */
    public function close(Request $request, Ticket $ticket)
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired closure link.');
        }

        if ($ticket->status !== 'closed') {
            $ticket->update([
                'status' => 'closed',
                'survey_token' => Str::random(32),
            ]);

            // Add history log for closure
            \App\Models\TicketHistory::create([
                'ticket_id' => $ticket->id,
                'user_id' => $ticket->reporter_id,
                'column_changed' => 'status',
                'old_value' => 'resolved',
                'new_value' => 'closed',
                'changed_at' => now('Asia/Manila'),
            ]);
        } elseif (!$ticket->survey_token) {
            $ticket->update(['survey_token' => Str::random(32)]);
        }

        // If already surveyed, show completed page
        if (TicketSurvey::where('ticket_id', $ticket->id)->exists()) {
            return Inertia::render('Public/SurveyCompleted');
        }

        // Redirect directly to survey — no survey email needed
        return redirect()->route('public.survey', $ticket->survey_token);
    }

    /**
     * Show the public survey page.
     */
    public function showSurvey($token)
    {
        $ticket = Ticket::where('survey_token', $token)->firstOrFail();
        
        // Check if already surveyed
        if (TicketSurvey::where('ticket_id', $ticket->id)->exists()) {
            return Inertia::render('Public/SurveyCompleted');
        }

        return Inertia::render('Public/Survey', [
            'ticket_id' => $ticket->id,
            'ticket_key' => $ticket->ticket_key,
            'token' => $token,
        ]);
    }

    /**
     * Submit the public survey.
     */
    public function submitSurvey(Request $request, $token)
    {
        $ticket = Ticket::where('survey_token', $token)->firstOrFail();

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:4',
            'feedback' => 'nullable|string|max:5000',
        ]);

        TicketSurvey::create([
            'ticket_id' => $ticket->id,
            'rating' => $validated['rating'],
            'feedback' => $validated['feedback'],
        ]);

        return redirect()->route('public.survey.thankyou');
    }
}
