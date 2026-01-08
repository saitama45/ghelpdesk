<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Comment Added</title>
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
        
        /* Comment Box */
        .comment-box { background-color: #ffffff; border-left: 4px solid #2563eb; padding: 16px; margin-bottom: 24px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .comment-author { font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px; }
        .comment-text { font-size: 16px; color: #111827; white-space: pre-wrap; }
        
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
            <h1>New Comment Activity</h1>
        </div>

        <!-- Main Content -->
        <div class="content">
            <p class="greeting">Hello {{ $recipientName }},</p>
            
            <p style="margin-bottom: 24px;">A new comment has been added to ticket <strong>{{ $ticket->ticket_key }}</strong>.</p>
            
            <div class="comment-box">
                <div class="comment-author">{{ $comment->user->name }} commented:</div>
                <div class="comment-text">{{ $comment->comment_text }}</div>
            </div>
            
            <div class="ticket-card">
                <span class="ticket-key">{{ $ticket->ticket_key }}</span>
                <h2 class="ticket-title">{{ $ticket->title }}</h2>
            </div>

            <div style="text-align: center;">
                <a href="{{ route('tickets.edit', $ticket->id) }}" class="action-button">
                    View Conversation
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
