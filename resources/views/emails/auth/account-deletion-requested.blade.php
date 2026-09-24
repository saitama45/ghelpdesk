<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We received your account deletion request</title>
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
            <h1>Deletion request received</h1>
        </div>

        <div class="content">
            <p>Hello {{ $user->name }},</p>
            <p>We received your request to delete your The Coffee Bean &amp; Tea Leaf Rewards account and its data. Your reference number is:</p>

            <div class="ref">{{ $ticketKey }}</div>

            <p>Your account will be closed within <strong>30 days</strong>. After that you will no longer be able to sign in, and your member QR code will stop working. We will email you when it is done.</p>
            <p class="meta">Changed your mind? Reply to this email and quote the reference number above — we can cancel the request while your account is still open.</p>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
