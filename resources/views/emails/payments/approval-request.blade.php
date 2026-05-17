<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Approval Request</title>
</head>
<body style="font-family: Arial, sans-serif; color:#333; background:#f6f7f9; padding:24px;">
    <div style="max-width:640px; margin:0 auto; background:#fff; border-radius:8px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
        <h2 style="margin-top:0;">Payment Approval Request</h2>
        <p>Hi {{ $approverName }},</p>
        <p>A payment record requires your approval:</p>

        <table cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%; margin:16px 0;">
            <tr style="background:#f1f3f5;"><td><strong>Record #</strong></td><td>{{ $record->id }}</td></tr>
            <tr><td><strong>Payable</strong></td><td>{{ ucfirst($record->payable_type) }} #{{ $record->payable_id }}</td></tr>
            <tr><td><strong>Vendor</strong></td><td>{{ optional($record->vendor)->name ?? '—' }}</td></tr>
            <tr><td><strong>Amount</strong></td><td>₱{{ number_format((float)$record->amount, 2) }}</td></tr>
            <tr><td><strong>Approval Level</strong></td><td>{{ (int)$record->current_approval_level + 1 }}</td></tr>
            <tr><td><strong>Remarks</strong></td><td>{{ $record->remarks ?? '—' }}</td></tr>
        </table>

        <p style="margin-top:24px; color:#666; font-size:12px;">
            Log in to the helpdesk to review and act on this approval.
        </p>
    </div>
</body>
</html>
