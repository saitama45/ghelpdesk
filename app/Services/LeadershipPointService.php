<?php

namespace App\Services;

use App\Models\AgentPointTransaction;
use App\Models\AgentQuestProgress;
use App\Models\Quest;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketSurvey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeadershipPointService
{
    private array $settings = [];

    public function awardPointsForClosedTicket(Ticket $ticket): void
    {
        if (!$ticket->assignee_id) {
            return;
        }

        // Prevent double-awarding: check if we already gave points for this ticket
        if (AgentPointTransaction::where('ticket_id', $ticket->id)
            ->whereIn('type', ['fast_resolution', 'ontime_resolution', 'late_resolution'])
            ->exists()) {
            return;
        }

        $this->loadSettings();

        DB::transaction(function () use ($ticket) {
            $this->awardResolutionPoints($ticket);
            $this->awardFcrBonus($ticket);
            $this->awardSatisfactionBonus($ticket);
            $this->checkQuestProgress($ticket);
        });
    }

    private function awardResolutionPoints(Ticket $ticket): void
    {
        $sla = $ticket->slaMetric;
        $resolvedAt = $sla?->resolved_at ?? now();

        $minutesTaken = $ticket->created_at->diffInMinutes($resolvedAt);

        if ($minutesTaken <= 60) {
            $type = 'fast_resolution';
            $points = $this->setting('fast_points', 10);
        } elseif ($sla && !$sla->is_resolution_breached) {
            $type = 'ontime_resolution';
            $points = $this->setting('ontime_points', 5);
        } else {
            $type = 'late_resolution';
            $points = $this->setting('late_points', -5);
        }

        $this->createTransaction($ticket, $type, $points);
    }

    private function awardFcrBonus(Ticket $ticket): void
    {
        if (!$this->isFcr($ticket)) {
            return;
        }

        $this->createTransaction($ticket, 'fcr_bonus', $this->setting('fcr_bonus', 5));
    }

    private function awardSatisfactionBonus(Ticket $ticket): void
    {
        $survey = TicketSurvey::where('ticket_id', $ticket->id)->first();
        if (!$survey) {
            return;
        }

        if ($survey->rating >= 3) {
            $this->createTransaction($ticket, 'happy_customer', $this->setting('happy_customer_bonus', 10));
        } elseif ($survey->rating <= 2) {
            $this->createTransaction($ticket, 'unhappy_customer', $this->setting('unhappy_customer_penalty', -10));
        }
    }

    private function isFcr(Ticket $ticket): bool
    {
        // Find the first agent comment (comment by any user other than reporter, not internal)
        $firstAgentComment = TicketComment::where('ticket_id', $ticket->id)
            ->where('is_internal', false)
            ->where(function ($q) use ($ticket) {
                $q->where('user_id', '!=', $ticket->reporter_id)
                  ->orWhereNull('user_id');
            })
            ->whereNotNull('user_id')
            ->orderBy('created_at')
            ->first();

        if (!$firstAgentComment) {
            return false;
        }

        // Check if the reporter replied after the first agent comment
        $customerReplied = TicketComment::where('ticket_id', $ticket->id)
            ->where('is_internal', false)
            ->where('created_at', '>', $firstAgentComment->created_at)
            ->where(function ($q) use ($ticket) {
                $q->where('user_id', $ticket->reporter_id)
                  ->orWhere('sender_email', $ticket->sender_email);
            })
            ->exists();

        return !$customerReplied;
    }

    private function checkQuestProgress(Ticket $ticket): void
    {
        $activeQuests = Quest::active()->get();

        foreach ($activeQuests as $quest) {
            $progress = AgentQuestProgress::firstOrCreate(
                ['agent_id' => $ticket->assignee_id, 'quest_id' => $quest->id],
                ['progress' => 0]
            );

            if ($progress->completed_at !== null) {
                continue;
            }

            $increment = $this->questIncrementFor($quest, $ticket);
            if ($increment === 0) {
                continue;
            }

            $progress->increment('progress', $increment);
            $progress->refresh();

            if ($progress->progress >= $quest->criteria_value) {
                $progress->update(['completed_at' => now()]);
                $this->createTransaction($ticket, 'quest_bonus', $quest->bonus_points);
            }
        }
    }

    private function questIncrementFor(Quest $quest, Ticket $ticket): int
    {
        $sla = $ticket->slaMetric;

        return match ($quest->criteria_type) {
            'tickets_resolved' => 1,
            'tickets_resolved_fast' => ($ticket->created_at->diffInMinutes($sla?->resolved_at ?? now()) <= 60) ? 1 : 0,
            'tickets_fcr' => $this->isFcr($ticket) ? 1 : 0,
            'tickets_with_awesome_rating' => $this->hasAwesomeRating($ticket) ? 1 : 0,
            default => 0,
        };
    }

    private function hasAwesomeRating(Ticket $ticket): bool
    {
        return TicketSurvey::where('ticket_id', $ticket->id)->where('rating', 4)->exists();
    }

    private function createTransaction(Ticket $ticket, string $type, int $points): void
    {
        AgentPointTransaction::create([
            'agent_id'   => $ticket->assignee_id,
            'ticket_id'  => $ticket->id,
            'type'       => $type,
            'points'     => $points,
            'awarded_at' => now(),
        ]);
    }

    private function loadSettings(): void
    {
        $keys = [
            'leadership.fast_points',
            'leadership.ontime_points',
            'leadership.late_points',
            'leadership.fcr_bonus',
            'leadership.happy_customer_bonus',
            'leadership.unhappy_customer_penalty',
        ];

        $this->settings = Setting::whereIn('key', $keys)->pluck('value', 'key')->toArray();
    }

    private function setting(string $suffix, int $default): int
    {
        return (int) ($this->settings["leadership.{$suffix}"] ?? $default);
    }
}
