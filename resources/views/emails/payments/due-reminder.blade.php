<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Due Reminder</title>
</head>
<body style="font-family: Arial, sans-serif; color:#333; background:#f6f7f9; padding:24px;">
    <div style="max-width:640px; margin:0 auto; background:#fff; border-radius:8px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
        <h2 style="margin-top:0;">Payment Reminder</h2>
        <p>This is an automated reminder for the following {{ $payableType }}:</p>

        <table cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%; margin:16px 0;">
            <tr style="background:#f1f3f5;"><td><strong>Vendor</strong></td><td>{{ $vendorName ?? '—' }}</td></tr>
            <tr><td><strong>Description</strong></td><td>{{ $payableData['title'] ?? '—' }}</td></tr>
            <tr><td><strong>Amount Due</strong></td><td>₱{{ number_format((float)($payableData['amount'] ?? 0), 2) }}</td></tr>
            <tr><td><strong>Due Date</strong></td><td>{{ $payableData['due_date'] ?? '—' }}</td></tr>
            <tr><td><strong>Status</strong></td>
                <td>
                    @if($reminderType === 'overdue')
                        <span style="color:#c92a2a; font-weight:bold;">OVERDUE</span>
                    @elseif($reminderType === 'due')
                        <span style="color:#e67700; font-weight:bold;">DUE TODAY</span>
                    @elseif($reminderType === '1d')
                        Due Tomorrow
                    @elseif($reminderType === '7d')
                        Due in 7 days
                    @elseif($reminderType === '30d')
                        Due in 30 days
                    @endif
                </td>
            </tr>
        </table>

        <p style="margin-top:24px; color:#666; font-size:12px;">
            This is a system-generated message. Please log in to the helpdesk to review and process this payment.
        </p>
    </div>
</body>
</html>
