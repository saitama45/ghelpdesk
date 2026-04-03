<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SAP Request Notification</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; color: #0f172a; line-height: 1.5; }
        table { border-collapse: collapse; width: 100%; }
        .wrapper { max-width: 640px; margin: 40px auto; background-color: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .header { background-color: #0f766e; padding: 48px 40px; text-align: left; color: #ffffff; }
        .header-badge { display: inline-block; background-color: rgba(255,255,255,0.2); padding: 6px 16px; border-radius: 99px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 16px; }
        .header h1 { margin: 0; font-size: 28px; font-weight: 900; letter-spacing: -0.025em; line-height: 1.2; }
        .content { padding: 48px 40px; }
        .greeting { font-size: 20px; font-weight: 800; color: #1e293b; margin-bottom: 8px; }
        .intro-text { font-size: 16px; color: #64748b; margin-bottom: 40px; }
        .summary-card { background-color: #f8fafc; border-radius: 20px; padding: 32px; border: 1px solid #f1f5f9; margin-bottom: 40px; }
        .summary-title { font-size: 14px; font-weight: 800; color: #0f766e; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 24px; display: block; }
        .data-row { margin-bottom: 16px; }
        .data-label { font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 4px; }
        .data-value { font-size: 15px; font-weight: 700; color: #1e293b; }
        .data-value.important { color: #0f766e; }
        .fields-card { background-color: #f0fdfa; border: 1px solid #ccfbf1; border-radius: 16px; padding: 24px; margin-bottom: 32px; }
        .fields-title { font-size: 13px; font-weight: 800; color: #0f766e; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 16px; display: block; }
        .field-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e0fdf4; }
        .field-row:last-child { border-bottom: none; }
        .field-key { font-size: 12px; font-weight: 700; color: #0d9488; min-width: 160px; }
        .field-val { font-size: 13px; font-weight: 600; color: #1e293b; text-align: right; }
        .items-section { margin-bottom: 32px; }
        .item-card { border: 2px solid #f0fdfa; border-radius: 16px; padding: 20px; margin-bottom: 16px; }
        .item-number { color: #94a3b8; font-weight: 800; font-size: 11px; margin-bottom: 10px; display: block; text-transform: uppercase; letter-spacing: 0.1em; }
        .footer-cta { text-align: center; margin-top: 40px; }
        .btn { display: inline-block; background-color: #0f766e; color: #ffffff !important; padding: 18px 40px; border-radius: 16px; text-decoration: none; font-weight: 800; font-size: 15px; }
        .footer { padding: 40px; text-align: center; border-top: 1px solid #f1f5f9; background-color: #f8fafc; }
        .footer-text { font-size: 13px; color: #94a3b8; font-weight: 500; }
        .status-pill { display: inline-block; padding: 4px 12px; border-radius: 8px; font-size: 12px; font-weight: 800; text-transform: uppercase; }
        .status-open { background-color: #dbeafe; color: #1e40af; }
        .status-approved { background-color: #dcfce7; color: #15803d; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <div class="header-badge">SAP Request #{{ $sapRequest->id }} • {{ $sapRequest->requestType->name }}</div>
            <h1>{{ $action === 'created' ? 'New SAP Request Submitted' : 'SAP Request Updated' }}</h1>
        </div>

        <div class="content">
            <p class="greeting">Hello Team,</p>
            <p class="intro-text">
                An SAP data request has been <strong>{{ $action }}</strong> and requires attention.
            </p>

            <div class="summary-card">
                <span class="summary-title">Request Summary</span>

                <table role="presentation">
                    <tr>
                        <td width="50%" style="vertical-align: top; padding-right: 16px;">
                            <div class="data-row">
                                <span class="data-label">Requester</span>
                                <span class="data-value">{{ $sapRequest->user ? $sapRequest->user->name : $sapRequest->requester_name }}</span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">Entity / Company</span>
                                <span class="data-value important">{{ $sapRequest->company->name }}</span>
                            </div>
                        </td>
                        <td width="50%" style="vertical-align: top;">
                            <div class="data-row">
                                <span class="data-label">Request Type</span>
                                <span class="data-value">{{ $sapRequest->requestType->name }}</span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">Status</span>
                                <span class="status-pill {{ $sapRequest->status === 'Approved' ? 'status-approved' : 'status-open' }}">
                                    {{ $sapRequest->status }}
                                </span>
                            </div>
                        </td>
                    </tr>
                </table>

                <div class="data-row" style="margin-top: 10px;">
                    <span class="data-label">Email Address</span>
                    <span class="data-value" style="font-size: 14px; color: #64748b;">
                        {{ $sapRequest->user ? $sapRequest->user->email : $sapRequest->requester_email }}
                    </span>
                </div>
            </div>

            @php $formData = $sapRequest->form_data ?? []; @endphp
            @if(!empty($formData))
            <div class="fields-card">
                <span class="fields-title">Form Details</span>
                @foreach($formData as $key => $value)
                    @php
                        $displayVal = is_array($value) ? implode(', ', $value) : ($value ?? '—');
                        $label = ucwords(str_replace('_', ' ', $key));
                    @endphp
                    <div class="field-row">
                        <span class="field-key">{{ $label }}</span>
                        <span class="field-val">{{ $displayVal }}</span>
                    </div>
                @endforeach
            </div>
            @endif

            @if($sapRequest->items && $sapRequest->items->count() > 0)
            <div class="items-section">
                <h2 style="font-size: 16px; font-weight: 900; color: #1e293b; margin-bottom: 16px;">
                    Items ({{ $sapRequest->items->count() }})
                </h2>
                @foreach($sapRequest->items as $index => $item)
                <div class="item-card">
                    <span class="item-number">Item #{{ $index + 1 }}</span>
                    @foreach($item->item_data as $key => $value)
                        @php
                            $displayVal = is_array($value) ? implode(', ', $value) : ($value ?? '—');
                            $label = ucwords(str_replace('_', ' ', $key));
                        @endphp
                        <div class="field-row" style="border-bottom: 1px solid #f1f5f9; padding: 8px 0;">
                            <span class="field-key">{{ $label }}</span>
                            <span class="field-val">{{ $displayVal }}</span>
                        </div>
                    @endforeach
                </div>
                @endforeach
            </div>
            @endif

            <div class="footer-cta">
                <a href="{{ route('sap-requests.show', $sapRequest->id) }}" class="btn">
                    View Full Details in Portal
                </a>
            </div>
        </div>

        <div class="footer">
            <p class="footer-text">
                &copy; {{ date('Y') }} TAS IT Support System. This is an automated notification.<br>
                Please do not reply to this email.
            </p>
        </div>
    </div>
</body>
</html>
