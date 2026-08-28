<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Item;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Services\TicketKnowledgeBaseService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClosedTicketKnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Category $category;
    private Item $item;
    private Store $store;
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

        $this->store = Store::create([
            'code' => 'TC1',
            'name' => 'Test Store',
            'sector' => 1,
            'area' => 'North',
            'brand' => 'Test',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $this->agent = User::factory()->create(['company_id' => $this->company->id]);
        $this->agent->givePermissionTo(
            Permission::findOrCreate('tickets.resolve', 'web'),
            Permission::findOrCreate('tickets.close', 'web'),
        );
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

        // The composer no longer lets an itemless ticket be answered at all, so the
        // skip branch is exercised where it is still reachable: on the service itself.
        $ticket = $this->ticket(['item_id' => null]);
        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'comment_text' => 'Closed after user confirmation.',
            'is_internal' => false,
            'user_id' => $this->agent->id,
            'action_taken' => 'Closed after user confirmation.',
        ]);

        $status = app(TicketKnowledgeBaseService::class)->createDraftFromClosedTicket($ticket, $comment);

        $this->assertSame(TicketKnowledgeBaseService::SKIPPED_NO_ITEM, $status);
        $this->assertDatabaseCount('kb_articles', 0);
    }

    public function test_response_is_rejected_until_the_ticket_is_classified(): void
    {
        Mail::fake();

        $ticket = $this->ticket(['item_id' => null, 'store_id' => null, 'assignee_id' => null, 'department' => null]);

        $response = $this->actingAs($this->agent)->post(route('tickets.comments.store', $ticket->id), [
            'comment_text' => 'Looking into this now.',
        ]);

        $response->assertSessionHasErrors('classification');
        $this->assertDatabaseCount('ticket_comments', 0);
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
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'item_id' => $this->item->id,
            'assignee_id' => $this->agent->id,
            'department' => 'Information Technology',
        ], $overrides));
    }
}
