<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your account has been closed</title>
    <style>
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f3f4f6; color: #111827; line-height: 1.6; }
        .wrapper { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #b45309; padding: 24px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 22px; }
        .content { padding: 32px 24px; }
        .ref { display: inline-block; margin: 8px 0 16px; padding: 10px 18px; background-color: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; font-size: 20px; font-weight: 700; letter-spacing: 1px; color: #78350f; }
        .meta { color: #6b7280; font-size: 14px; }
        .footer { padding: 24px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>Your account has been closed</h1>
        </div>

        <div class="content">
            <p>Hello {{ $user->name }},</p>
            <p>Your The Coffee Bean &amp; Tea Leaf Rewards account was deleted from the app just now, and it is already closed. Your reference number is:</p>

            <div class="ref">{{ $ticketKey }}</div>

            <p>You can no longer sign in, and your member QR code has stopped working. Any unredeemed stamps and rewards have been forfeited.</p>
            <p class="meta">Rewards you had already redeemed are financial records, so they are kept in a restricted archive for our published retention period and then permanently deleted.</p>
            <p class="meta"><strong>If this was not you</strong>, reply to this email and quote the reference number above as soon as possible.</p>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
