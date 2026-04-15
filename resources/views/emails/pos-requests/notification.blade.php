<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Request Notification</title>
    <style>
        /* RESET & BASE */
        body { margin: 0; padding: 0; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; color: #0f172a; line-height: 1.5; }
        table { border-collapse: collapse; width: 100%; }
        
        /* CONTAINER */
        .wrapper { max-width: 640px; margin: 40px auto; background-color: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); border: 1px solid #e2e8f0; }
        
        /* HEADER */
        .header { background-color: #4f46e5; padding: 48px 40px; text-align: left; color: #ffffff; position: relative; }
        .header-badge { display: inline-block; background-color: rgba(255,255,255,0.2); padding: 6px 16px; border-radius: 99px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 16px; }
        .header h1 { margin: 0; font-size: 32px; font-weight: 900; letter-spacing: -0.025em; line-height: 1.1; }
        
        /* CONTENT */
        .content { padding: 48px 40px; }
        .greeting { font-size: 20px; font-weight: 800; color: #1e293b; margin-bottom: 8px; }
        .intro-text { font-size: 16px; color: #64748b; margin-bottom: 40px; }
        
        /* SUMMARY CARD */
        .summary-card { background-color: #f8fafc; border-radius: 20px; padding: 32px; border: 1px solid #f1f5f9; margin-bottom: 48px; }
        .summary-title { font-size: 14px; font-weight: 800; color: #4f46e5; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 24px; display: block; }
        
        .data-row { margin-bottom: 20px; }
        .data-label { font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 4px; }
        .data-value { font-size: 16px; font-weight: 700; color: #1e293b; }
        .data-value.important { color: #4f46e5; font-size: 18px; }
        
        /* STORE BADGE */
        .stores-container { background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-top: 12px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; color: #334155; font-weight: 600; }

        /* LINE ITEMS */
        .section-header { font-size: 18px; font-weight: 900; color: #1e293b; margin-bottom: 24px; display: flex; align-items: center; }
        .section-header span { background-color: #4f46e5; color: #white; width: 24px; height: 24px; display: inline-block; border-radius: 6px; text-align: center; margin-right: 12px; font-size: 14px; }

        .item-card { border: 2px solid #f1f5f9; border-radius: 20px; padding: 28px; margin-bottom: 24px; transition: border-color 0.2s; }
        .item-number { color: #94a3b8; font-weight: 800; font-size: 12px; margin-bottom: 12px; display: block; }
        .item-title { font-size: 18px; font-weight: 800; color: #1e293b; margin-bottom: 4px; }
        .item-subtitle { font-size: 14px; font-weight: 600; color: #64748b; margin-bottom: 20px; display: block; }
        
        .item-grid { display: table; width: 100%; border-top: 1px solid #f1f5f9; padding-top: 20px; }
        .item-col { display: table-cell; width: 50%; padding-right: 10px; }
        
        .tech-box { background-color: #fdf2f8; border-radius: 12px; padding: 16px; margin-top: 20px; border: 1px solid #fce7f3; }
        .tech-text { font-size: 12px; color: #be185d; font-weight: 700; text-transform: uppercase; letter-spacing: 0.025em; }

        /* BUTTON */
        .footer-cta { text-align: center; margin-top: 48px; }
        .btn { display: inline-block; background-color: #0f172a; color: #ffffff !important; padding: 18px 40px; border-radius: 16px; text-decoration: none; font-weight: 800; font-size: 16px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
        
        /* FOOTER */
        .footer { padding: 48px 40px; text-align: center; border-top: 1px solid #f1f5f9; background-color: #f8fafc; }
        .footer-text { font-size: 13px; color: #94a3b8; font-weight: 500; }
        
        /* STATUS BADGE */
        .status-pill { display: inline-block; padding: 4px 12px; border-radius: 8px; font-size: 12px; font-weight: 800; text-transform: uppercase; }
        .status-open { background-color: #dbeafe; color: #1e40af; }
        .status-approved { background-color: #dcfce7; color: #15803d; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <div class="header-badge">#{{ $posRequest->id }} • Priority Notice</div>
            <h1>{{ $posRequest->requestType->name }}</h1>
        </div>

        <div class="content">
            @if($isRequester)
                <p class="greeting">Hello {{ $posRequest->user ? $posRequest->user->name : $posRequest->requester_name }},</p>
                <p class="intro-text">Thank you! Your POS System request has been successfully submitted and is now pending review.</p>
            @else
                <p class="greeting">Hello Team,</p>
                <p class="intro-text">A POS System request has been <strong>{{ $action }}</strong> and is now pending your attention in the help desk portal.</p>
            @endif

            <div class="summary-card">
                <span class="summary-title">Request Summary</span>
                
                <table role="presentation">
                    <tr>
                        <td width="50%" style="vertical-align: top;">
                            <div class="data-row">
                                <span class="data-label">Requester Name</span>
                                <span class="data-value">{{ $posRequest->user ? $posRequest->user->name : $posRequest->requester_name }}</span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">Company Entity</span>
                                <span class="data-value">{{ $posRequest->company->name }}</span>
                            </div>
                        </td>
                        <td width="50%" style="vertical-align: top;">
                            <div class="data-row">
                                <span class="data-label">Launch Date</span>
                                <span class="data-value important">{{ $posRequest->launch_date->format('F d, Y') }}</span>
                            </div>
                            <div class="data-row">
                                <span class="data-label">Current Status</span>
                                <span class="status-pill {{ $posRequest->status === 'Approved' ? 'status-approved' : 'status-open' }}">
                                    {{ $posRequest->status }}
                                </span>
                            </div>
                        </td>
                    </tr>
                </table>

                <div class="data-row" style="margin-top: 10px;">
                    <span class="data-label">Email Address</span>
                    <span class="data-value" style="color: #64748b; font-size: 14px;">{{ $posRequest->user ? $posRequest->user->email : $posRequest->requester_email }}</span>
                </div>

                <div class="data-row" style="margin-top: 24px;">
                    <span class="data-label">Target Stores</span>
                    <div class="stores-container">
                        @if(in_array('all', $posRequest->stores_covered))
                            ALL OPERATIONAL LOCATIONS
                        @else
                            {{ implode(', ', $posRequest->stores_covered) }}
                        @endif
                    </div>
                </div>
            </div>

            {{-- ── Schema-driven items (form_data['items']) ──────────────────── --}}
            @if($hasSchemaItems)
                <h2 class="section-header">Line Item Details ({{ $resolvedItems->count() }})</h2>

                @foreach($resolvedItems as $index => $itemFields)
                    <div class="item-card">
                        <span class="item-number">ITEM #{{ $index + 1 }}</span>
                        <table role="presentation" style="width:100%; border-top: 1px solid #f1f5f9; padding-top: 16px; margin-top: 12px;">
                            @foreach(array_chunk($itemFields, 2) as $colRow)
                                <tr>
                                    @foreach($colRow as $field)
                                        <td width="50%" style="vertical-align: top; padding: 0 10px 16px 0;">
                                            <span class="data-label">{{ $field['label'] }}</span>
                                            <span class="data-value" style="font-size: 14px;">{{ $field['value'] }}</span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endforeach

            {{-- ── Legacy hard-coded items (pos_request_details table) ────────── --}}
            @elseif($posRequest->details->count())
                <h2 class="section-header">Line Item Details ({{ $posRequest->details->count() }})</h2>

                @foreach($posRequest->details as $index => $detail)
                    <div class="item-card">
                        <span class="item-number">PRODUCT #{{ $index + 1 }}</span>
                        <div class="item-title">{{ $detail->product_name }}</div>
                        <span class="item-subtitle">POS Alias: {{ $detail->pos_name }}</span>

                        <div class="item-grid">
                            <div class="item-col">
                                <span class="data-label">Price & Type</span>
                                <span class="data-value">₱{{ number_format($detail->price_amount, 2) }} <span style="font-weight: 400; color: #94a3b8; font-size: 12px;">({{ $detail->price_type }})</span></span>
                            </div>
                            <div class="item-col">
                                <span class="data-label">Validity</span>
                                <span class="data-value">{{ $detail->validity_date ? $detail->validity_date->format('M d, Y') : 'Immediate' }}</span>
                            </div>
                        </div>

                        <div class="item-grid" style="margin-top: 16px;">
                            <div class="item-col">
                                <span class="data-label">Category / Sub</span>
                                <span class="data-value" style="font-size: 14px;">{{ $detail->category }} <span style="color: #cbd5e1;">➔</span> {{ $detail->sub_category }}</span>
                            </div>
                            <div class="item-col">
                                <span class="data-label">Printer / SKU</span>
                                <span class="data-value" style="font-size: 14px;">{{ $detail->printer }} <span style="color: #cbd5e1;">|</span> {{ $detail->item_code ?? 'N/A' }}</span>
                            </div>
                        </div>

                        @if($detail->remarks_mechanics)
                        <div class="data-row" style="margin-top: 20px; padding: 16px; background-color: #fffbeb; border-radius: 12px; border: 1px solid #fef3c7;">
                            <span class="data-label" style="color: #92400e;">Remarks & Mechanics</span>
                            <div style="font-size: 14px; color: #78350f; font-weight: 500;">{{ $detail->remarks_mechanics }}</div>
                        </div>
                        @endif

                        <div class="tech-box">
                            <div class="tech-text">
                                SC: <span style="color: #0f172a;">{{ $detail->sc }}</span> &nbsp;|&nbsp;
                                TAX: <span style="color: #0f172a;">{{ $detail->local_tax }}%</span> &nbsp;|&nbsp;
                                MGR MEAL: <span style="color: #0f172a;">{{ $detail->mgr_meal ? 'YES' : 'NO' }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif

            <div class="footer-cta">
                <a href="{{ route('pos-requests.show', $posRequest->id) }}" class="btn">
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
