<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your verification code</title>
    <style>
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f3f4f6; color: #111827; line-height: 1.6; }
        .wrapper { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #b45309; padding: 24px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 22px; }
        .content { padding: 32px 24px; text-align: center; }
        .code { display: inline-block; margin: 16px 0; padding: 16px 28px; background-color: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #78350f; }
        .meta { color: #6b7280; font-size: 14px; }
        .footer { padding: 24px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>Verify it's you</h1>
        </div>

        <div class="content">
            <p>Hello {{ $user->name }},</p>
            <p>Enter this code to finish signing in:</p>

            <div class="code">{{ $code }}</div>

            <p class="meta">This code expires in {{ $validForMinutes }} minutes.</p>
            <p class="meta">If you didn't try to sign in, you can ignore this email.</p>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
