<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Status Changed</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f3f4f6; color: #1f2937; line-height: 1.6; }
        table { border-collapse: collapse; width: 100%; }
        .wrapper { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #0ea5e9; padding: 24px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 600; letter-spacing: 0.5px; }
        .content { padding: 32px 24px; }
        .greeting { font-size: 18px; font-weight: 600; margin-bottom: 24px; color: #111827; }
        .ticket-card { background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 24px; }
        .ticket-key { color: #0ea5e9; font-weight: 700; font-size: 14px; text-transform: uppercase; margin-bottom: 8px; display: block; }
        .ticket-title { font-size: 20px; font-weight: 700; color: #111827; margin: 0 0 16px 0; line-height: 1.3; }
        .transition { text-align: center; margin: 24px 0; padding: 16px; border-radius: 8px; border: 1px dashed #e5e7eb; }
        .transition-label { font-size: 12px; font-weight: 800; color: #6b7280; text-transform: uppercase; margin-bottom: 8px; display: block; letter-spacing: 1px; }
        .pill { display: inline-block; padding: 6px 14px; border-radius: 999px; font-weight: 700; font-size: 13px; text-transform: uppercase; }
        .pill-old { background-color: #e5e7eb; color: #374151; }
        .pill-new { background-color: #dbeafe; color: #1e40af; }
        .arrow { color: #9ca3af; margin: 0 8px; font-size: 18px; }
        .details-table td { padding-bottom: 12px; vertical-align: top; }
        .label { font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 600; padding-right: 12px; width: 80px; }
        .value { font-size: 14px; color: #374151; font-weight: 500; }
        .action-button { display: inline-block; background-color: #0369a1; color: #ffffff !important; padding: 14px 28px; border-radius: 6px; text-decoration: none; font-weight: 700; text-align: center; margin-top: 16px; }
        .footer { padding: 24px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; background-color: #ffffff; }
        .footer p { margin: 4px 0; }
        @media only screen and (max-width: 600px) {
            .wrapper { width: 100% !important; }
            .content { padding: 20px 16px; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>Ticket Status Changed</h1>
        </div>

        <div class="content">
            <p class="greeting">Hello {{ $recipientName }},</p>

            <p>The status of the following ticket has been updated.</p>

            <div class="transition">
                <span class="transition-label">Status Update</span>
                <span class="pill pill-old">{{ strtoupper(str_replace('_', ' ', $oldStatus)) ?: '—' }}</span>
                <span class="arrow">&rarr;</span>
                <span class="pill pill-new">{{ strtoupper(str_replace('_', ' ', $newStatus)) ?: '—' }}</span>
            </div>

            <div class="ticket-card">
                <span class="ticket-key">{{ $ticket->ticket_key }}</span>
                <h2 class="ticket-title">{{ $ticket->title }}</h2>

                <table class="details-table">
                    <tr>
                        <td class="label">Priority</td>
                        <td class="value">{{ ucfirst($ticket->priority) }}</td>
                    </tr>
                    <tr>
                        <td class="label">Type</td>
                        <td class="value">{{ ucfirst($ticket->type) }}</td>
                    </tr>
                    <tr>
                        <td class="label">Reporter</td>
                        <td class="value">{{ $ticket->reporter->name ?? $ticket->sender_name ?? 'External User' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Assignee</td>
                        <td class="value">{{ $ticket->assignee->name ?? 'Unassigned' }}</td>
                    </tr>
                </table>
            </div>

            <div style="text-align: center;">
                <a href="{{ route('tickets.edit', $ticket->id) }}" class="action-button">
                    View Ticket Details
                </a>
            </div>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
            <p>You are receiving this email because you are CC'd on this ticket or you are the requester.</p>
        </div>
    </div>
</body>
</html>
