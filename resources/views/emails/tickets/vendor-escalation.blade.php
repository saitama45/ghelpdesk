<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Escalation</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f3f4f6; color: #1f2937; line-height: 1.6; }
        table { border-collapse: collapse; width: 100%; }
        .wrapper { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #b45309; padding: 24px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 22px; font-weight: 600; letter-spacing: 0.5px; }
        .content { padding: 32px 24px; }
        .greeting { font-size: 18px; font-weight: 600; margin-bottom: 16px; color: #111827; }
        .ticket-card { background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 24px; }
        .ticket-key { color: #b45309; font-weight: 700; font-size: 14px; text-transform: uppercase; margin-bottom: 8px; display: block; }
        .ticket-title { font-size: 18px; font-weight: 700; color: #111827; margin: 0 0 8px 0; line-height: 1.3; }
        .meta-row { font-size: 13px; color: #374151; margin: 4px 0; }
        .meta-label { font-weight: 700; color: #6b7280; }
        .message-box { background-color: #ffffff; border-left: 4px solid #b45309; padding: 16px; margin-bottom: 24px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .message-text { font-size: 16px; color: #111827; white-space: pre-wrap; }
        .reason-box { background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 16px; margin-bottom: 24px; }
        .reason-title { font-size: 12px; color: #92400e; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
        .reason-text { font-size: 14px; color: #1f2937; white-space: pre-wrap; }
        .attachment-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-bottom: 24px; }
        .attachment-title { font-size: 12px; color: #475569; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 8px; }
        .attachment-item { font-size: 14px; color: #1f2937; margin: 4px 0; }
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
        <p style="text-align: center; font-size: 10px; color: #9ca3af; margin: 10px 0;">### Please type your reply above this line ###</p>
        <div class="header">
            <h1>Service Escalation</h1>
        </div>

        <div class="content">
            <p class="greeting">Hello {{ $recipientName }},</p>

            <p>You are being engaged on the following service request. Please reply to this email with your update &mdash; all replies stay on this thread for tracking.</p>

            <div class="message-box">
                <div class="message-text">{{ $bodyMessage }}</div>
            </div>

            @if(!empty($escalationReason))
            <div class="reason-box">
                <div class="reason-title">Escalation Reason</div>
                <div class="reason-text">{{ $escalationReason }}</div>
            </div>
            @endif

            <div class="ticket-card">
                <span class="ticket-key">{{ $ticket->ticket_key }}</span>
                <h2 class="ticket-title">{{ $ticket->title }}</h2>
                @if($ticket->store)
                <div class="meta-row"><span class="meta-label">Location:</span> {{ $ticket->store->name }}</div>
                @endif
                @if($ticket->priority)
                <div class="meta-row"><span class="meta-label">Priority:</span> {{ ucfirst($ticket->priority) }}</div>
                @endif
            </div>

            @if($ticketAttachments->isNotEmpty())
            <div class="attachment-box">
                <div class="attachment-title">Attached Files</div>
                @foreach($ticketAttachments as $attachment)
                    <div class="attachment-item">{{ $attachment->file_name }}</div>
                @endforeach
            </div>
            @endif
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
            <p>You can reply directly to this email &mdash; your reply will be recorded on reference {{ $ticket->ticket_key }}.</p>
        </div>
    </div>
</body>
</html>
