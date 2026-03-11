<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>SLA Performance Report</title>
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
            margin-bottom: 25px;
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
        .total-box {
            text-align: right;
        }
        .total-value {
            font-size: 16pt;
            font-weight: 900;
            color: #2563eb;
            line-height: 1;
        }
        .total-label {
            font-size: 6pt;
            font-weight: 900;
            color: #94a3b8;
            text-transform: uppercase;
            margin-top: 2px;
        }
        .stats-grid {
            width: 100%;
            border-collapse: collapse;
        }
        .metric-cell {
            width: 50%;
            padding: 15px;
            vertical-align: top;
        }
        .metric-title {
            font-size: 8pt;
            font-weight: 900;
            color: #475569;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .percentage-row {
            margin-bottom: 10px;
        }
        .percentage {
            font-size: 20pt;
            font-weight: 900;
            display: inline-block;
            vertical-align: middle;
        }
        .progress-container {
            width: 100%;
            background-color: #f1f5f9;
            height: 8px;
            border-radius: 4px;
            margin: 8px 0;
        }
        .progress-bar {
            height: 8px;
            border-radius: 4px;
        }
        .details-table {
            width: 100%;
            margin-top: 10px;
            border-collapse: collapse;
        }
        .details-table td {
            padding: 6px 4px;
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
        .text-green { color: #166534; }
        .bg-green { background-color: #22c55e; }
        .text-yellow { color: #854d0e; }
        .bg-yellow { background-color: #eab308; }
        .text-red { color: #991b1b; }
        .bg-red { background-color: #ef4444; }
        
        @page {
            margin: 1cm;
        }
    </style>
</head>
<body>
    @php
        function getPdfColor($percentage) {
            if ($percentage >= 95) return 'green';
            if ($percentage >= 85) return 'yellow';
            return 'red';
        }
    @endphp

    <div class="header">
        <h1>SLA Performance Report</h1>
        <p>Period: {{ $dateRange }}</p>
        @if($subUnit !== 'all')
            <p>Sub-Unit: {{ $subUnit }}</p>
        @endif
    </div>

    @foreach($reportData as $user)
        <div class="user-section">
            <div class="user-header">
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td width="70%">
                            <h2>{{ $user['user_name'] }}</h2>
                            <p>{{ $user['sub_unit'] ?: 'No Sub-Unit' }}</p>
                        </td>
                        <td width="30%" class="total-box">
                            <div class="total-value">{{ $user['total_tickets'] }}</div>
                            <div class="total-label">Total Tickets</div>
                        </td>
                    </tr>
                </table>
            </div>

            <table class="stats-grid" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <!-- Response Section -->
                    <td class="metric-cell" style="border-right: 1px solid #f1f5f9;">
                        <div class="metric-title">Target Response</div>
                        @php $resColor = getPdfColor($user['response']['percentage']); @endphp
                        <div class="percentage text-{{ $resColor }}">{{ $user['response']['percentage'] }}%</div>
                        <div class="progress-container">
                            <div class="progress-bar bg-{{ $resColor }}" style="width: {{ $user['response']['percentage'] }}%"></div>
                        </div>
                        <table class="details-table" width="100%">
                            <tr>
                                <td>
                                    <span class="stat-label">Met</span>
                                    <span class="stat-value" style="color: #166534;">{{ $user['response']['met'] }}</span>
                                </td>
                                <td>
                                    <span class="stat-label">Breached</span>
                                    <span class="stat-value" style="color: #991b1b;">{{ $user['response']['breached'] }}</span>
                                </td>
                                <td>
                                    <span class="stat-label">Pending</span>
                                    <span class="stat-value" style="color: #1e40af;">{{ $user['response']['pending'] }}</span>
                                </td>
                            </tr>
                        </table>
                    </td>

                    <!-- Resolution Section -->
                    <td class="metric-cell">
                        <div class="metric-title">Target Resolution</div>
                        @php $slvColor = getPdfColor($user['resolution']['percentage']); @endphp
                        <div class="percentage text-{{ $slvColor }}">{{ $user['resolution']['percentage'] }}%</div>
                        <div class="progress-container">
                            <div class="progress-bar bg-{{ $slvColor }}" style="width: {{ $user['resolution']['percentage'] }}%"></div>
                        </div>
                        <table class="details-table" width="100%">
                            <tr>
                                <td>
                                    <span class="stat-label">Met</span>
                                    <span class="stat-value" style="color: #166534;">{{ $user['resolution']['met'] }}</span>
                                </td>
                                <td>
                                    <span class="stat-label">Breached</span>
                                    <span class="stat-value" style="color: #991b1b;">{{ $user['resolution']['breached'] }}</span>
                                </td>
                                <td>
                                    <span class="stat-label">Pending</span>
                                    <span class="stat-value" style="color: #1e40af;">{{ $user['resolution']['pending'] }}</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>
    @endforeach

    <div style="text-align: center; font-size: 8pt; color: #a0aec0; margin-top: 20px;">
        Generated on {{ now()->format('F d, Y h:i A') }}
    </div>
</body>
</html>
