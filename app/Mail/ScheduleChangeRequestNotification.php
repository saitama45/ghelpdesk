<?php

namespace App\Mail;

use App\Models\ScheduleChangeRequest;
use App\Models\Store;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Queue\SerializesModels;

class ScheduleChangeRequestNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ScheduleChangeRequest $changeRequest,
        public string $action = 'submitted',
        public bool $isApprover = false
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->action) {
            'approved' => 'Schedule Change Approved',
            'rejected' => 'Schedule Change Rejected',
            default => $this->isApprover ? 'Schedule Change Approval Required' : 'Schedule Change Request Submitted',
        };

        return new Envelope(
            subject: "[Schedule Request #{$this->changeRequest->id}] {$subject}",
        );
    }

    public function content(): Content
    {
        $requestedStores = collect($this->changeRequest->requested_payload['stores'] ?? []);

        return new Content(
            view: 'emails.schedules.change-request',
            with: [
                'storeNamesById' => $this->storeNamesById($requestedStores),
                'ticketLabelsById' => $this->ticketLabelsById($requestedStores),
            ],
        );
    }

    private function storeNamesById(Collection $requestedStores): Collection
    {
        $storeIds = $requestedStores
            ->pluck('store_id')
            ->filter()
            ->unique()
            ->values();

        if ($storeIds->isEmpty()) {
            return collect();
        }

        return Store::query()
            ->whereIn('id', $storeIds)
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Store $store) => [
                $store->id => trim(collect([$store->code, $store->name])->filter()->implode(' - ')),
            ]);
    }

    private function ticketLabelsById(Collection $requestedStores): Collection
    {
        $ticketIds = $requestedStores
            ->pluck('ticket_id')
            ->filter()
            ->unique()
            ->values();

        if ($ticketIds->isEmpty()) {
            return collect();
        }

        return Ticket::query()
            ->whereIn('id', $ticketIds)
            ->pluck('ticket_key', 'id');
    }

    public function attachments(): array
    {
        return [];
    }
}
