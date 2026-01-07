<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Ticket Created</title>
    <style>
        /* Base styles */
        body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f3f4f6; color: #1f2937; line-height: 1.6; }
        table { border-collapse: collapse; width: 100%; }
        
        /* Container */
        .wrapper { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        
        /* Header */
        .header { background-color: #2563eb; padding: 24px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 600; letter-spacing: 0.5px; }
        
        /* Content */
        .content { padding: 32px 24px; }
        .greeting { font-size: 18px; font-weight: 600; margin-bottom: 24px; color: #111827; }
        
        /* Ticket Card */
        .ticket-card { background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 24px; }
        .ticket-key { color: #2563eb; font-weight: 700; font-size: 14px; text-transform: uppercase; margin-bottom: 8px; display: block; }
        .ticket-title { font-size: 20px; font-weight: 700; color: #111827; margin: 0 0 16px 0; line-height: 1.3; }
        
        /* Details Grid */
        .details-table td { padding-bottom: 12px; vertical-align: top; }
        .label { font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 600; padding-right: 12px; width: 80px; }
        .value { font-size: 14px; color: #374151; font-weight: 500; }
        
        /* Badges */
        .badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
        .badge-priority-urgent { background-color: #fecaca; color: #991b1b; }
        .badge-priority-high { background-color: #fee2e2; color: #991b1b; }
        .badge-priority-medium { background-color: #fef3c7; color: #92400e; }
        .badge-priority-low { background-color: #d1fae5; color: #065f46; }
        
        /* Button */
        .action-button { display: inline-block; background-color: #1e40af; color: #ffffff !important; padding: 14px 28px; border-radius: 6px; text-decoration: none; font-weight: 700; text-align: center; margin-top: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); transition: background-color 0.2s; }
        .action-button:hover { background-color: #1e3a8a; }
        
        /* Footer */
        .footer { padding: 24px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; background-color: #ffffff; }
        .footer p { margin: 4px 0; }
        
        /* Responsive */
        @media only screen and (max-width: 600px) {
            .wrapper { width: 100% !important; }
            .content { padding: 20px 16px; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Header -->
        <div class="header">
            <h1>Help Desk Notification</h1>
        </div>

        <!-- Main Content -->
        <div class="content">
            <p class="greeting">Hello {{ $recipientName }},</p>
            
            <p style="margin-bottom: 24px;">A new ticket has been created and requires attention.</p>
            
            <div class="ticket-card">
                <span class="ticket-key">{{ $ticket->ticket_key }}</span>
                <h2 class="ticket-title">{{ $ticket->title }}</h2>
                
                <table class="details-table">
                    <tr>
                        <td class="label">Status</td>
                        <td class="value">{{ ucfirst(str_replace('_', ' ', $ticket->status)) }}</td>
                    </tr>
                    <tr>
                        <td class="label">Priority</td>
                        <td class="value">
                            <span class="badge badge-priority-{{ $ticket->priority }}">
                                {{ ucfirst($ticket->priority) }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="label">Type</td>
                        <td class="value">{{ ucfirst($ticket->type) }}</td>
                    </tr>
                     <tr>
                        <td class="label">Reporter</td>
                        <td class="value">{{ $ticket->reporter->name }}</td>
                    </tr>
                    @if($ticket->assignee)
                    <tr>
                        <td class="label">Assignee</td>
                        <td class="value">{{ $ticket->assignee->name }}</td>
                    </tr>
                    @endif
                </table>
                
                @if($ticket->description)
                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5e7eb;">
                    <span class="label" style="display: block; margin-bottom: 8px;">Description</span>
                    <div style="font-size: 14px; color: #4b5563; white-space: pre-wrap;">{{ Str::limit($ticket->description, 150) }}</div>
                </div>
                @endif
            </div>

            <div style="text-align: center;">
                <a href="{{ route('tickets.edit', $ticket->id) }}" class="action-button">
                    View Ticket Details
                </a>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
            <p>This is an automated message, please do not reply directly to this email.</p>
        </div>
    </div>
</body>
</html>
