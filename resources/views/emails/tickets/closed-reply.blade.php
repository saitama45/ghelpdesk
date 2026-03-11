<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Already Closed</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; }
        .header { background-color: #f8fafc; padding: 15px; border-bottom: 2px solid #e2e8f0; text-align: center; border-radius: 8px 8px 0 0; }
        .content { padding: 20px; }
        .footer { font-size: 12px; color: #718096; text-align: center; margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 15px; }
        .button { display: inline-block; padding: 10px 20px; background-color: #3182ce; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; margin-top: 15px; }
        .alert { background-color: #fff5f5; border: 1px solid #feb2b2; color: #c53030; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin:0; color: #2d3748;">Help Desk Notification</h2>
        </div>
        <div class="content">
            <p>Dear {{ $recipientName }},</p>
            
            <div class="alert">
                <strong>Ticket #{{ $ticket->ticket_key }} is already closed.</strong>
            </div>

            <p>We received your message regarding ticket <strong>"{{ $ticket->title }}"</strong>. However, this ticket has already been finalized and closed.</p>
            
            <p>To ensure your request is tracked and handled properly, please create a <strong>new ticket</strong> by sending a fresh email to our support address.</p>

            <p>Thank you for your understanding.</p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
