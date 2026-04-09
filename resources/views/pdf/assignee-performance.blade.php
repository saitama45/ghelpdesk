<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Assignee Performance Report</title>
    <style>
        body {
            font-family: sans-serif;
            font-size: 9pt;
            color: #333;
            line-height: 1.4;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 16pt;
            color: #1a202c;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header p {
            margin: 3px 0 0;
            color: #718096;
            font-size: 9pt;
        }
        .user-section {
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
            page-break-inside: avoid;
        }
        .user-header {
            background-color: #f8fafc;
            padding: 10px 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        .user-header h2 {
            margin: 0;
            font-size: 12pt;
            color: #1a202c;
            font-weight: 900;
        }
        .user-header p {
            margin: 1px 0 0;
            font-size: 7pt;
            color: #64748b;
            font-weight: bold;
            text-transform: uppercase;
        }
        .totals-box {
            text-align: right;
        }
        .total-value {
            font-size: 14pt;
            font-weight: 900;
            line-height: 1;
        }
        .total-label {
            font-size: 6pt;
            font-weight: 900;
            color: #94a3b8;
            text-transform: uppercase;
            margin-top: 2px;
        }
        .total-blue  { color: #2563eb; }
        .total-green { color: #059669; }
        .cols-table {
            width: 100%;
            border-collapse: collapse;
        }
        .col-cell {
            width: 33.33%;
            padding: 12px 14px;
            vertical-align: top;
            border-right: 1px solid #f1f5f9;
        }
        .col-cell:last-child {
            border-right: none;
        }
        .metric-title {
            font-size: 7.5pt;
            font-weight: 900;
            color: #475569;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .percentage {
            font-size: 18pt;
            font-weight: 900;
            display: inline-block;
        }
        .progress-container {
            width: 100%;
            background-color: #f1f5f9;
            height: 7px;
            border-radius: 4px;
            margin: 6px 0 8px;
        }
        .progress-bar {
            height: 7px;
            border-radius: 4px;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
        }
        .details-table td {
            padding: 5px 3px;
            background-color: #fbfcfd;
            border: 1px solid #f1f5f9;
            text-align: center;
        }
        .stat-label {
            font-size: 6pt;
            font-weight: 900;
            color: #64748b;
            text-transform: uppercase;
            display: block;
            margin-bottom: 2px;
        }
        .stat-value {
            font-size: 9pt;
            font-weight: 800;
        }
        .avg-rating-box {
            padding: 6px 8px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            margin-bottom: 8px;
            text-align: center;
        }
        .avg-rating-value {
            font-size: 20pt;
            font-weight: 900;
        }
        .avg-rating-label {
            font-size: 7pt;
            font-weight: 900;
            text-transform: uppercase;
            display: block;
        }
        .survey-meta {
            font-size: 7pt;
            color: #94a3b8;
            text-align: center;
            margin-top: 4px;
        }
        .no-survey {
            font-size: 8pt;
            color: #94a3b8;
            font-style: italic;
            text-align: center;
            padding: 20px 0;
        }
        .text-green  { color: #166534; }
        .bg-green    { background-color: #22c55e; }
        .text-yellow { color: #854d0e; }
        .bg-yellow   { background-color: #eab308; }
        .text-red    { color: #991b1b; }
        .bg-red      { background-color: #ef4444; }
        .text-blue   { color: #1e40af; }
        @page { margin: 1cm; }
    </style>
</head>
<body>
    @php
        function apPdfColor($pct) {
            if ($pct >= 95) return 'green';
            if ($pct >= 85) return 'yellow';
            return 'red';
        }
        function apRatingColor($avg) {
            if ($avg >= 3.5) return '#166534';
            if ($avg >= 2.5) return '#1e40af';
            if ($avg >= 1.5) return '#854d0e';
            return '#991b1b';
        }
        function apRatingLabel($avg) {
            if ($avg >= 3.5) return 'Excellent';
            if ($avg >= 2.5) return 'Good';
            if ($avg >= 1.5) return 'Fair';
            if ($avg > 0)    return 'Poor';
            return 'No Surveys';
        }
        function apRatingEmoji($rating) {
            if ($rating >= 4) return 'Excellent';
            if ($rating >= 3) return 'Good';
            if ($rating >= 2) return 'Fair';
            return 'Poor';
        }
    @endphp

    <div class="header">
        <h1>Assignee Performance Report</h1>
        <p>Period: {{ $dateRange }}</p>
        @if($subUnit && $subUnit !== 'all')
            <p>Sub-Unit: {{ $subUnit }}</p>
        @endif
        @if($userName)
            <p>Assignee: {{ $userName }}</p>
        @endif
    </div>

    @foreach($reportData as $user)
        <div class="user-section">
            <!-- User Header -->
            <div class="user-header">
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td width="55%">
                            <h2>{{ $user['user_name'] }}</h2>
                            <p>{{ $user['sub_unit'] ?: 'No Sub-Unit' }}</p>
                        </td>
                        <td width="45%" class="totals-box">
                            <table cellpadding="0" cellspacing="0" style="display:inline-table;">
                                <tr>
                                    <td style="padding-right:16px;">
                                        <div class="total-value total-blue">{{ $user['total_tickets'] }}</div>
                                        <div class="total-label">Total Tickets</div>
                                    </td>
                                    <td>
                                        <div class="total-value total-green">{{ $user['closed_tickets'] }}</div>
                                        <div class="total-label">Closed/Resolved</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 3 Columns -->
            <table class="cols-table" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <!-- SLA Response -->
                    <td class="col-cell">
                        <div class="metric-title">Target Response</div>
                        @php $rc = apPdfColor($user['sla']['response']['percentage']); @endphp
                        <div class="percentage text-{{ $rc }}">{{ $user['sla']['response']['percentage'] }}%</div>
                        <div class="progress-container">
                            <div class="progress-bar bg-{{ $rc }}" style="width:{{ $user['sla']['response']['percentage'] }}%"></div>
                        </div>
                        <table class="details-table" width="100%">
                            <tr>
                                <td><span class="stat-label">Met</span><span class="stat-value" style="color:#166534;">{{ $user['sla']['response']['met'] }}</span></td>
                                <td><span class="stat-label">Breached</span><span class="stat-value" style="color:#991b1b;">{{ $user['sla']['response']['breached'] }}</span></td>
                                <td><span class="stat-label">Pending</span><span class="stat-value" style="color:#1e40af;">{{ $user['sla']['response']['pending'] }}</span></td>
                            </tr>
                        </table>
                    </td>

                    <!-- SLA Resolution -->
                    <td class="col-cell">
                        <div class="metric-title">Target Resolution</div>
                        @php $sc = apPdfColor($user['sla']['resolution']['percentage']); @endphp
                        <div class="percentage text-{{ $sc }}">{{ $user['sla']['resolution']['percentage'] }}%</div>
                        <div class="progress-container">
                            <div class="progress-bar bg-{{ $sc }}" style="width:{{ $user['sla']['resolution']['percentage'] }}%"></div>
                        </div>
                        <table class="details-table" width="100%">
                            <tr>
                                <td><span class="stat-label">Met</span><span class="stat-value" style="color:#166534;">{{ $user['sla']['resolution']['met'] }}</span></td>
                                <td><span class="stat-label">Breached</span><span class="stat-value" style="color:#991b1b;">{{ $user['sla']['resolution']['breached'] }}</span></td>
                                <td><span class="stat-label">Pending</span><span class="stat-value" style="color:#1e40af;">{{ $user['sla']['resolution']['pending'] }}</span></td>
                            </tr>
                        </table>
                    </td>

                    <!-- Survey Rating -->
                    <td class="col-cell">
                        <div class="metric-title">Survey Rating</div>
                        @if($user['survey']['total'] > 0)
                            <div class="avg-rating-box">
                                <div class="avg-rating-value" style="color:{{ apRatingColor($user['survey']['avg_rating']) }}">
                                    {{ $user['survey']['avg_rating'] }} / 4
                                </div>
                                <span class="avg-rating-label" style="color:{{ apRatingColor($user['survey']['avg_rating']) }}">
                                    {{ apRatingLabel($user['survey']['avg_rating']) }}
                                </span>
                            </div>
                            <table class="details-table" width="100%">
                                <tr>
                                    <td><span class="stat-label">Excellent</span><span class="stat-value" style="color:#166534;">{{ $user['survey']['excellent'] }}</span></td>
                                    <td><span class="stat-label">Good</span><span class="stat-value" style="color:#1e40af;">{{ $user['survey']['good'] }}</span></td>
                                    <td><span class="stat-label">Fair</span><span class="stat-value" style="color:#854d0e;">{{ $user['survey']['fair'] }}</span></td>
                                    <td><span class="stat-label">Poor</span><span class="stat-value" style="color:#991b1b;">{{ $user['survey']['poor'] }}</span></td>
                                </tr>
                            </table>
                            <div class="survey-meta">{{ $user['survey']['total'] }} survey(s) from {{ $user['total_tickets'] }} ticket(s)</div>
                        @else
                            <div class="no-survey">No surveys submitted yet</div>
                        @endif
                    </td>
                </tr>
            </table>

            <!-- Feedback Section -->
            @if(count($user['survey']['feedbacks'] ?? []) > 0)
                <div style="padding: 10px 15px; background-color: #fff; border-top: 1px solid #f1f5f9;">
                    <div class="metric-title" style="margin-bottom: 8px; color: #64748b;">Recent Feedback</div>
                    @foreach($user['survey']['feedbacks'] as $feedback)
                        <div style="margin-bottom: 10px; padding: 8px; background-color: #f8fafc; border-radius: 4px; border: 1px solid #f1f5f9;">
                            <div style="margin-bottom: 4px; overflow: hidden;">
                                <span style="float: left; font-weight: 900; font-size: 7pt; color: {{ apRatingColor($feedback['rating']) }}; text-transform: uppercase;">
                                    {{ apRatingEmoji($feedback['rating']) }}
                                </span>
                                <span style="float: right; color: #94a3b8; font-size: 7pt;">{{ $feedback['date'] }}</span>
                                <div style="clear: both;"></div>
                            </div>
                            <div style="color: #475569; font-style: italic; font-size: 8.5pt;">"{{ $feedback['text'] }}"</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach

    <div style="text-align:center;font-size:8pt;color:#a0aec0;margin-top:20px;">
        Generated on {{ now()->format('F d, Y h:i A') }}
    </div>
</body>
</html>
