<?php

namespace Tests\Feature;

use App\Mail\TicketCommentAdded;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TicketBulkResponseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'tickets.edit']);

        $this->company = Company::create([
            'name' => 'Test Company',
            'code' => 'TC',
            'is_active' => true,
        ]);

        $this->agent = User::factory()->create(['company_id' => $this->company->id]);
        $this->agent->givePermissionTo('tickets.edit');
    }

    public function test_bulk_response_creates_public_comment_for_each_selected_ticket(): void
    {
        Mail::fake();

        $firstTicket = $this->ticket(['title' => 'First issue']);
        $secondTicket = $this->ticket(['title' => 'Second issue']);

        $response = $this->actingAs($this->agent)->post(route('tickets.bulk-response'), [
            'ticket_ids' => [$firstTicket->id, $secondTicket->id],
            'comment_text' => 'We are checking this now.',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $firstTicket->id,
            'comment_text' => 'We are checking this now.',
            'is_internal' => false,
            'user_id' => $this->agent->id,
        ]);
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $secondTicket->id,
            'comment_text' => 'We are checking this now.',
            'is_internal' => false,
            'user_id' => $this->agent->id,
        ]);
    }

    public function test_bulk_response_rejects_all_selected_tickets_when_any_ticket_is_closed(): void
    {
        Mail::fake();

        $openTicket = $this->ticket(['status' => 'open']);
        $closedTicket = $this->ticket(['status' => 'closed']);

        $response = $this->actingAs($this->agent)->post(route('tickets.bulk-response'), [
            'ticket_ids' => [$openTicket->id, $closedTicket->id],
            'comment_text' => 'This should not be saved.',
        ]);

        $response->assertSessionHasErrors('bulk_response');

        $this->assertDatabaseCount('ticket_comments', 0);
        Mail::assertNothingSent();
    }

    public function test_bulk_response_accepts_attachment_without_text_and_copies_it_to_each_ticket(): void
    {
        Mail::fake();
        Storage::fake('public');

        $firstTicket = $this->ticket();
        $secondTicket = $this->ticket();
        $file = UploadedFile::fake()->image('proof.jpg')->size(32);

        $response = $this->actingAs($this->agent)->post(route('tickets.bulk-response'), [
            'ticket_ids' => [$firstTicket->id, $secondTicket->id],
            'attachments' => [$file],
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ticket_comments', 2);
        $this->assertDatabaseCount('ticket_attachments', 2);
        $this->assertDatabaseHas('ticket_attachments', [
            'ticket_id' => $firstTicket->id,
            'file_name' => 'proof.jpg',
        ]);
        $this->assertDatabaseHas('ticket_attachments', [
            'ticket_id' => $secondTicket->id,
            'file_name' => 'proof.jpg',
        ]);
    }

    public function test_bulk_response_updates_first_response_sla_and_notifies_reporter(): void
    {
        Mail::fake();

        $reporter = User::factory()->create(['company_id' => $this->company->id]);
        $ticket = $this->ticket(['reporter_id' => $reporter->id]);

        $this->actingAs($this->agent)->post(route('tickets.bulk-response'), [
            'ticket_ids' => [$ticket->id],
            'comment_text' => 'This is the first support response.',
        ])->assertSessionHasNoErrors();

        $ticket->refresh();

        $this->assertNotNull($ticket->slaMetric->first_response_at);
        Mail::assertSent(TicketCommentAdded::class, function (TicketCommentAdded $mail) use ($reporter, $ticket) {
            return $mail->hasTo($reporter->email)
                && $mail->ticket->is($ticket);
        });
    }

    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'title' => 'POS issue',
            'description' => 'The terminal needs support.',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'company_id' => $this->company->id,
        ], $overrides));
    }
}
