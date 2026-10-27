<?php

namespace Tests\Feature;

use App\Mail\NewTicketCreated;
use App\Mail\TicketAssigned;
use App\Mail\TicketCommentAdded;
use App\Mail\TicketMergedNotification;
use App\Mail\TicketStatusChanged;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Ticket emails are queued instead of sent inside the request. These run the real
 * queue path (phpunit.xml: QUEUE_CONNECTION=sync, MAIL_MAILER=array), so every mail
 * is serialized and rebuilt the way the production worker does it, then delivered
 * to the array transport where its headers and attachments can be read.
 */
class TicketMailQueueTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->company = Company::create(['name' => 'Test Company', 'code' => 'TC', 'is_active' => true]);
        $this->reporter = User::factory()->create([
            'company_id' => $this->company->id,
            'email' => 'reporter@example.test',
        ]);
    }

    public function test_a_queued_reply_reaches_the_mail_server_with_its_attachment_and_thread_headers(): void
    {
        Storage::fake('public');
        Permission::create(['name' => 'tickets.edit']);
        $agent = User::factory()->create(['company_id' => $this->company->id]);
        $agent->givePermissionTo('tickets.edit');
        $ticket = $this->ticket();

        $processed = [];
        Queue::after(function (JobProcessed $event) use (&$processed) {
            $processed[] = $event->job->resolveName();
        });

        $this->actingAs($agent)->post(route('tickets.bulk-response'), [
            'ticket_ids' => [$ticket->id],
            'comment_text' => 'The replacement unit is on its way.',
            'attachments' => [UploadedFile::fake()->image('proof.jpg')->size(32)],
        ])->assertSessionHasNoErrors();

        $this->assertContains(TicketCommentAdded::class, $processed, 'The reply mail did not go through the queue.');

        $message = $this->deliveredTo('reporter@example.test');
        $this->assertSame("Re: [{$ticket->fresh()->ticket_key}] {$ticket->title}", $message->getSubject());
        $this->assertTrue($message->getHeaders()->has('In-Reply-To'), 'Thread headers were lost in the queue.');
        $this->assertSame(['proof.jpg'], collect($message->getAttachments())->map->getFilename()->all());
    }

    public function test_every_queued_ticket_email_builds_without_a_logged_in_user(): void
    {
        // The worker has no session and no user, which the request always had.
        $this->assertGuest();
        Storage::fake('public');
        Storage::disk('public')->put('ticket-attachments/note.txt', 'see attached');

        $ticket = $this->ticket();
        $child = $this->ticket(['title' => 'Merged duplicate']);
        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'comment_text' => 'Update from the desk.',
            'is_internal' => false,
            'user_id' => $this->reporter->id,
            'created_at' => now('Asia/Manila'),
        ]);
        $attachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'comment_id' => $comment->id,
            'file_name' => 'note.txt',
            'file_storage_path' => 'ticket-attachments/note.txt',
            'file_size_bytes' => 12,
        ]);

        $mails = [
            'assigned@example.test' => new TicketAssigned($ticket, 'Agent'),
            'status@example.test' => new TicketStatusChanged($ticket, 'Customer', 'open', 'resolved'),
            'created@example.test' => new NewTicketCreated($ticket, 'Customer'),
            'merged@example.test' => new TicketMergedNotification($ticket, Ticket::whereKey($child->id)->get(), 'Customer'),
            'commented@example.test' => new TicketCommentAdded($ticket, $comment, 'Customer', collect([$attachment])),
        ];

        foreach ($mails as $to => $mail) {
            Mail::to($to)->queue($mail->afterCommit());
        }

        foreach (array_keys($mails) as $to) {
            $this->assertStringContainsString($ticket->ticket_key, $this->deliveredTo($to)->getSubject());
        }
        $this->assertSame(
            ['note.txt'],
            collect($this->deliveredTo('commented@example.test')->getAttachments())->map->getFilename()->all()
        );
    }

    public function test_saving_settings_tells_the_queue_workers_to_restart(): void
    {
        Permission::create(['name' => 'settings.edit']);
        $admin = User::factory()->create(['company_id' => $this->company->id]);
        $admin->givePermissionTo('settings.edit');

        $this->assertNull(Cache::get('illuminate:queue:restart'));

        $this->actingAs($admin)
            ->put(route('settings.update'), ['mail_from_name' => 'TAS Service Center'])
            ->assertSessionHasNoErrors();

        // queue:restart's signal: workers compare it after each job and exit, and
        // startup.sh's loop starts them again with the new mail settings.
        $this->assertNotNull(Cache::get('illuminate:queue:restart'));
    }

    private function deliveredTo(string $address): Email
    {
        $message = collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->first(fn (Email $email) => collect($email->getTo())->contains(fn ($to) => $to->getAddress() === $address));

        $this->assertNotNull($message, "No message was delivered to {$address}.");

        return $message;
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
            'reporter_id' => $this->reporter->id,
            'company_id' => $this->company->id,
        ], $overrides));
    }
}
