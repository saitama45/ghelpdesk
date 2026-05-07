<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Google Registration</title>
    <style>
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f3f4f6; color: #111827; line-height: 1.6; }
        .wrapper { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #2563eb; padding: 24px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 22px; }
        .content { padding: 32px 24px; }
        .detail-card { background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin: 24px 0; }
        .label { font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 700; }
        .value { font-size: 15px; color: #111827; font-weight: 600; margin-bottom: 12px; }
        .button { display: inline-block; background-color: #1d4ed8; color: #ffffff !important; padding: 12px 20px; border-radius: 6px; text-decoration: none; font-weight: 700; }
        .footer { padding: 24px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>Pending Google Registration</h1>
        </div>

        <div class="content">
            <p>Hello,</p>
            <p>A Google registration is waiting for administrator approval.</p>

            <div class="detail-card">
                <div class="label">Name</div>
                <div class="value">{{ $user->name }}</div>
                <div class="label">Email</div>
                <div class="value">{{ $user->email }}</div>
            </div>

            <p>Review the account, complete any needed user details, assign a role, and activate the account.</p>

            <p>
                <a href="{{ route('users.index') }}" class="button">Review User</a>
            </p>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
