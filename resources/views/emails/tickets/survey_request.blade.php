<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Satisfaction Survey</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f3f4f6; color: #1f2937; line-height: 1.6; }
        .wrapper { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #2563eb; padding: 24px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 600; }
        .content { padding: 32px 24px; text-align: center; }
        .greeting { font-size: 18px; font-weight: 600; margin-bottom: 16px; color: #111827; }
        .message { font-size: 16px; color: #4b5563; margin-bottom: 32px; }
        .action-button { display: inline-block; background-color: #1e40af; color: #ffffff !important; padding: 16px 32px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 18px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .footer { padding: 24px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>TAS Support</h1>
        </div>
        <div class="content">
            <p class="greeting">Hi {{ $recipientName }},</p>
            <p class="message">
                Thank you for your time. Your feedback matters! <br>
                Please take a moment to rate your recent support experience for ticket <strong>{{ $ticket->ticket_key }}</strong>.<br>
                This survey should take under a minute to complete.
            </p>
            
            <a href="{{ route('public.survey', $ticket->survey_token) }}" class="action-button">
                Take the survey
            </a>
            
            <p style="margin-top: 32px; color: #9ca3af; font-size: 14px;">
                Regards,<br>
                TAS Support
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
