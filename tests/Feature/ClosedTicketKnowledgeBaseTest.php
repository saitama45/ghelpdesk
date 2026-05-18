<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Item;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClosedTicketKnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Category $category;
    private Item $item;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Test Company',
            'code' => 'TC',
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'name' => 'Hardware',
            'is_active' => true,
        ]);

        $this->item = Item::create([
            'category_id' => $this->category->id,
            'name' => 'POS Terminal',
            'description' => 'POS device issues',
            'priority' => 'medium',
            'concern_type' => 'Incident',
            'requires_rca_on_resolve' => true,
            'is_active' => true,
        ]);

        $this->agent = User::factory()->create(['company_id' => $this->company->id]);
    }

    public function test_closing_ticket_creates_draft_kb_article_under_item_category(): void
    {
        Mail::fake();

        $ticket = $this->ticket();

        $response = $this->actingAs($this->agent)->post(route('tickets.comments.store', $ticket->id), [
            'status' => 'closed',
            'action_taken' => 'Replaced the defective LAN cable and restarted the terminal.',
            'root_cause_analysis' => 'LAN cable had intermittent continuity.',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Comment added, status updated, and KB draft created.');

        $kbCategory = KbCategory::where('name', $this->item->name)->firstOrFail();

        $this->assertDatabaseHas('kb_articles', [
            'title' => $ticket->title,
            'kb_category_id' => $kbCategory->id,
            'author_id' => $this->agent->id,
            'source_item_id' => $this->item->id,
            'source_ticket_id' => $ticket->id,
            'is_ticket_generated' => true,
            'is_published' => false,
        ]);

        $article = KbArticle::firstOrFail();
        $this->assertStringContainsString('Action Taken', $article->content);
        $this->assertStringContainsString('Root Cause Analysis', $article->content);
    }

    public function test_resolved_status_does_not_create_kb_article(): void
    {
        Mail::fake();

        $ticket = $this->ticket();

        $this->actingAs($this->agent)->post(route('tickets.comments.store', $ticket->id), [
            'status' => 'resolved',
            'action_taken' => 'Restarted the terminal.',
            'root_cause_analysis' => 'Application service was hung.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('kb_articles', 0);
    }

    public function test_closing_ticket_without_item_skips_kb_article(): void
    {
        Mail::fake();

        $ticket = $this->ticket(['item_id' => null]);

        $response = $this->actingAs($this->agent)->post(route('tickets.comments.store', $ticket->id), [
            'status' => 'closed',
            'action_taken' => 'Closed after user confirmation.',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Comment added and status updated. KB draft skipped because no Item is selected.');
        $this->assertDatabaseCount('kb_articles', 0);
    }

    public function test_duplicate_concern_and_resolution_for_same_item_skips_second_kb_article(): void
    {
        Mail::fake();

        $firstTicket = $this->ticket();
        $secondTicket = $this->ticket();
        $payload = [
            'status' => 'closed',
            'action_taken' => 'Replaced the defective LAN cable and restarted the terminal.',
            'root_cause_analysis' => 'LAN cable had intermittent continuity.',
        ];

        $this->actingAs($this->agent)
            ->post(route('tickets.comments.store', $firstTicket->id), $payload)
            ->assertSessionHas('success', 'Comment added, status updated, and KB draft created.');

        $this->actingAs($this->agent)
            ->post(route('tickets.comments.store', $secondTicket->id), $payload)
            ->assertSessionHas('success', 'Comment added and status updated. KB draft skipped because an existing article already covers this concern.');

        $this->assertDatabaseCount('kb_articles', 1);
    }

    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'title' => 'POS terminal offline',
            'description' => 'The POS terminal cannot connect to the network.',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $this->company->id,
            'category_id' => $this->category->id,
            'item_id' => $this->item->id,
        ], $overrides));
    }
}
