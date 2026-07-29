<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send your request to the right department</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; }
        .header { background-color: #f8fafc; padding: 15px; border-bottom: 2px solid #e2e8f0; text-align: center; border-radius: 8px 8px 0 0; }
        .content { padding: 20px; }
        .footer { font-size: 12px; color: #718096; text-align: center; margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 15px; }
        .alert { background-color: #fffaf0; border: 1px solid #f6ad55; color: #975a16; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        table.dirs { width: 100%; border-collapse: collapse; margin: 15px 0; }
        table.dirs th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #718096; border-bottom: 1px solid #e2e8f0; padding: 6px 8px; }
        table.dirs td { padding: 8px; border-bottom: 1px solid #edf2f7; vertical-align: top; }
        table.dirs td.addr { font-family: monospace; }
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
                <strong>Your message was not logged as a request.</strong>
            </div>

            <p>
                We received your email
                @if ($originalSubject !== '')
                    regarding <strong>"{{ $originalSubject }}"</strong>
                @endif
                at <strong>{{ $sharedAddress }}</strong>. That address is not monitored for new
                requests, so nothing has been created and no one has been assigned.
            </p>

            <p>Please resend your message to the department that handles it:</p>

            <table class="dirs">
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Send requests to</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($departments as $name => $address)
                        <tr>
                            <td>{{ $name }}</td>
                            <td class="addr"><a href="mailto:{{ $address }}">{{ $address }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <p>
                Your original message is not saved anywhere, so please include the full details again
                when you resend it. Once it reaches the right address you will receive a ticket
                number and updates as it progresses.
            </p>

            <p>Thank you for your understanding.</p>
        </div>
        <div class="footer">
            <p>This is an automated message &mdash; please do not reply to it.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
