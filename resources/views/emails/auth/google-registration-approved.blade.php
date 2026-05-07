<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Approved</title>
    <style>
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f3f4f6; color: #111827; line-height: 1.6; }
        .wrapper { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #16a34a; padding: 24px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 22px; }
        .content { padding: 32px 24px; }
        .button { display: inline-block; background-color: #15803d; color: #ffffff !important; padding: 12px 20px; border-radius: 6px; text-decoration: none; font-weight: 700; }
        .footer { padding: 24px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>Account Approved</h1>
        </div>

        <div class="content">
            <p>Hello {{ $user->name }},</p>
            <p>Your TAS Service Center account has been approved. You can now sign in using Google.</p>

            <p>
                <a href="{{ route('login') }}" class="button">Sign In</a>
            </p>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
