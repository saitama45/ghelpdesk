<?php

namespace App\Services;

use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class TicketKnowledgeBaseService
{
    public const CREATED = 'created';
    public const DUPLICATE = 'duplicate';
    public const SKIPPED_NO_ITEM = 'skipped_no_item';

    public function createDraftFromClosedTicket(Ticket $ticket, TicketComment $comment): string
    {
        $ticket->loadMissing('item');

        if (!$ticket->item_id || !$ticket->item) {
            return self::SKIPPED_NO_ITEM;
        }

        $actionTaken = trim((string) $comment->action_taken);
        $rootCauseAnalysis = trim((string) $comment->root_cause_analysis);

        if ($actionTaken === '') {
            return self::SKIPPED_NO_ITEM;
        }

        $title = $this->buildTitle($ticket);
        $content = $this->buildContent($ticket, $actionTaken, $rootCauseAnalysis);
        $fingerprint = $this->fingerprint($title, $actionTaken, $rootCauseAnalysis);

        $category = KbCategory::firstOrCreate(['name' => $ticket->item->name]);

        if ($this->isDuplicate($ticket, $category, $title, $content, $fingerprint)) {
            return self::DUPLICATE;
        }

        try {
            KbArticle::create([
                'title' => $title,
                'content' => $content,
                'kb_category_id' => $category->id,
                'author_id' => $comment->user_id ?: auth()->id(),
                'source_item_id' => $ticket->item_id,
                'source_ticket_id' => $ticket->id,
                'source_ticket_comment_id' => $comment->id,
                'source_content_fingerprint' => $fingerprint,
                'is_ticket_generated' => true,
                'is_published' => false,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return self::DUPLICATE;
            }

            throw $exception;
        }

        return self::CREATED;
    }

    private function isDuplicate(Ticket $ticket, KbCategory $category, string $title, string $content, string $fingerprint): bool
    {
        if (KbArticle::where('source_ticket_id', $ticket->id)->exists()) {
            return true;
        }

        if (KbArticle::where('source_item_id', $ticket->item_id)
            ->where('source_content_fingerprint', $fingerprint)
            ->exists()) {
            return true;
        }

        $normalizedTitle = $this->normalize($title);
        $normalizedContent = $this->normalize($content);

        return KbArticle::where('kb_category_id', $category->id)
            ->get(['title', 'content'])
            ->contains(function (KbArticle $article) use ($normalizedTitle, $normalizedContent) {
                return $this->normalize($article->title) === $normalizedTitle
                    && $this->normalize($article->content) === $normalizedContent;
            });
    }

    private function buildTitle(Ticket $ticket): string
    {
        return Str::limit(trim((string) $ticket->title), 255, '');
    }

    private function buildContent(Ticket $ticket, string $actionTaken, string $rootCauseAnalysis): string
    {
        $description = trim((string) $ticket->description);

        $sections = [
            '<h2>Concern</h2>',
            '<p>' . e($ticket->title) . '</p>',
        ];

        if ($description !== '') {
            $sections[] = '<h2>Description</h2>';
            $sections[] = '<p>' . nl2br(e($description)) . '</p>';
        }

        $sections[] = '<h2>Action Taken</h2>';
        $sections[] = '<p>' . nl2br(e($actionTaken)) . '</p>';

        if ($rootCauseAnalysis !== '') {
            $sections[] = '<h2>Root Cause Analysis</h2>';
            $sections[] = '<p>' . nl2br(e($rootCauseAnalysis)) . '</p>';
        }

        return implode("\n", $sections);
    }

    private function fingerprint(string $title, string $actionTaken, string $rootCauseAnalysis): string
    {
        return hash('sha256', implode('|', [
            $this->normalize($title),
            $this->normalize($actionTaken),
            $this->normalize($rootCauseAnalysis),
        ]));
    }

    private function normalize(string $value): string
    {
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = Str::lower($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true);
    }
}
