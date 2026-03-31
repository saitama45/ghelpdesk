<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Store Health Report</title>
    <style>
        body {
            font-family: sans-serif;
            font-size: 10pt;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 18pt;
            color: #1a202c;
        }
        .header p {
            margin: 5px 0 0;
            color: #718096;
        }
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            table-layout: fixed;
        }
        .summary-header {
            background-color: #1a202c;
            color: white;
            text-align: center;
            font-weight: bold;
            padding: 8px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .sector-header {
            background-color: #f7fafc;
            text-align: center;
            font-weight: bold;
            font-size: 8pt;
            padding: 5px;
            border: 1px solid #e2e8f0;
            color: #4a5568;
        }
        .user-cell {
            text-align: center;
            font-weight: bold;
            font-size: 8pt;
            padding: 8px 4px;
            border: 1px solid #e2e8f0;
            color: #2b6cb0;
            height: 30px;
        }
        .color-cell {
            height: 50px;
            text-align: center;
            vertical-align: middle;
            font-size: 14pt;
            font-weight: bold;
            border: 1px solid #e2e8f0;
        }
        .report-section {
            margin-top: 30px;
            page-break-inside: avoid;
        }
        .user-header {
            background-color: #f7fafc;
            padding: 10px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 10px;
        }
        .user-header h3 {
            margin: 0;
            font-size: 12pt;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .data-table th {
            background-color: #f7fafc;
            text-align: left;
            padding: 8px;
            font-size: 8pt;
            text-transform: uppercase;
            color: #718096;
            border-bottom: 1px solid #e2e8f0;
        }
        .data-table td {
            padding: 8px;
            border-bottom: 1px solid #f0f4f8;
            font-size: 9pt;
        }
        .health-bar-bg {
            width: 100%;
            background-color: #edf2f7;
            height: 10px;
            border-radius: 5px;
        }
        .health-bar-fill {
            height: 10px;
            border-radius: 5px;
        }
        .text-white { color: white; }
        .text-dark { color: #1a202c; }
        
        @page {
            margin: 1cm;
        }
    </style>
</head>
<body>
    @php
        function getBoxStyle($maxTickets, $thresholds) {
            if ($maxTickets == 0) return ['bg' => '#ffffff', 'text' => '#333'];
            
            $th = [
                'green_max' => (int)($thresholds['threshold_green_max'] ?? 2),
                'yellow_min' => (int)($thresholds['threshold_yellow_min'] ?? 3),
                'orange_min' => (int)($thresholds['threshold_orange_min'] ?? 4),
                'red_min' => (int)($thresholds['threshold_red_min'] ?? 5),
            ];

            if ($maxTickets >= $th['red_min']) return ['bg' => '#ef4444', 'text' => '#ffffff'];
            if ($maxTickets >= $th['orange_min']) return ['bg' => '#f97316', 'text' => '#ffffff'];
            if ($maxTickets >= $th['yellow_min']) return ['bg' => '#eab308', 'text' => '#1a202c'];
            if ($maxTickets >= 1) return ['bg' => '#22c55e', 'text' => '#ffffff'];
            
            return ['bg' => '#ffffff', 'text' => '#333'];
        }

        function getHealthColor($count, $thresholds) {
            $th = [
                'green_max' => (int)($thresholds['threshold_green_max'] ?? 2),
                'yellow_min' => (int)($thresholds['threshold_yellow_min'] ?? 3),
                'orange_min' => (int)($thresholds['threshold_orange_min'] ?? 4),
                'red_min' => (int)($thresholds['threshold_red_min'] ?? 5),
            ];

            if ($count >= $th['red_min']) return '#ef4444';
            if ($count >= $th['orange_min']) return '#f97316';
            if ($count >= $th['yellow_min']) return '#eab308';
            if ($count >= 1) return '#22c55e';
            return '#cbd5e0';
        }
    @endphp

    <div class="header">
        <h1>Store Health Report</h1>
        <p>As of {{ $asOfDate }}</p>
        <div style="font-size: 8pt; color: #4a5568; margin-top: 5px;">
            @if($filters['sub_unit'] !== 'all') Sub-Unit: {{ $filters['sub_unit'] }} | @endif
            @if($filters['user_id'] !== 'all') User: {{ $reportData->first()->name ?? 'N/A' }} | @endif
            @if($filters['store_id'] !== 'all') Store: {{ $filters['store_id'] }} @endif
        </div>
    </div>

    <!-- Legend -->
    <div style="margin-bottom: 20px; border: 1px solid #e2e8f0; padding: 10px; border-radius: 5px;">
        <table width="100%" style="border-collapse: collapse;">
            <tr>
                <td width="15%" style="font-weight: bold; font-size: 8pt; text-transform: uppercase; color: #4a5568;">Legend:</td>
                <td width="20%">
                    <div style="display: inline-block; width: 10px; height: 10px; background-color: #22c55e; margin-right: 5px; border-radius: 2px;"></div>
                    <span style="font-size: 8pt; color: #4a5568;">
                        @if(($thresholds['threshold_green_min'] ?? 1) == ($thresholds['threshold_green_max'] ?? 2))
                            {{ $thresholds['threshold_green_min'] ?? 1 }}
                        @else
                            {{ $thresholds['threshold_green_min'] ?? 1 }}-{{ $thresholds['threshold_green_max'] ?? 2 }}
                        @endif
                        ({{ $thresholds['threshold_green_label'] ?? 'Healthy' }})
                    </span>
                </td>
                <td width="20%">
                    <div style="display: inline-block; width: 10px; height: 10px; background-color: #eab308; margin-right: 5px; border-radius: 2px;"></div>
                    <span style="font-size: 8pt; color: #4a5568;">
                        @if(($thresholds['threshold_yellow_min'] ?? 3) == ($thresholds['threshold_yellow_max'] ?? 3))
                            {{ $thresholds['threshold_yellow_min'] ?? 3 }}
                        @else
                            {{ $thresholds['threshold_yellow_min'] ?? 3 }}-{{ $thresholds['threshold_yellow_max'] ?? 3 }}
                        @endif
                        ({{ $thresholds['threshold_yellow_label'] ?? 'Warning' }})
                    </span>
                </td>
                <td width="20%">
                    <div style="display: inline-block; width: 10px; height: 10px; background-color: #f97316; margin-right: 5px; border-radius: 2px;"></div>
                    <span style="font-size: 8pt; color: #4a5568;">
                        @if(($thresholds['threshold_orange_min'] ?? 4) == ($thresholds['threshold_orange_max'] ?? 4))
                            {{ $thresholds['threshold_orange_min'] ?? 4 }}
                        @else
                            {{ $thresholds['threshold_orange_min'] ?? 4 }}-{{ $thresholds['threshold_orange_max'] ?? 4 }}
                        @endif
                        ({{ $thresholds['threshold_orange_label'] ?? 'At-risk' }})
                    </span>
                </td>
                <td width="25%">
                    <div style="display: inline-block; width: 10px; height: 10px; background-color: #ef4444; margin-right: 5px; border-radius: 2px;"></div>
                    <span style="font-size: 8pt; color: #4a5568;">{{ $thresholds['threshold_red_min'] ?? 5 }}+ ({{ $thresholds['threshold_red_label'] ?? 'Critical' }})</span>
                </td>
            </tr>
        </table>
    </div>

    <!-- North Area Summary -->
    <table class="summary-table">
        <thead>
            <tr>
                <th colspan="4" class="summary-header">N O R T H &nbsp;&nbsp; A R E A</th>
            </tr>
            <tr>
                @foreach($summary['north'] as $item)
                    <th class="sector-header">Sector {{ $item->sector }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr>
                @foreach($summary['north'] as $item)
                    <td class="user-cell">{{ $item->user }}</td>
                @endforeach
            </tr>
            <tr>
                @foreach($summary['north'] as $item)
                    @php $style = getBoxStyle($item->max_tickets, $thresholds); @endphp
                    <td class="color-cell" style="background-color: {{ $style['bg'] }}; color: {{ $style['text'] }}">
                        {{ $item->max_tickets }}
                    </td>
                @endforeach
            </tr>
        </tbody>
    </table>

    <!-- South Area Summary -->
    <table class="summary-table">
        <thead>
            <tr>
                <th colspan="4" class="summary-header">S O U T H &nbsp;&nbsp; A R E A</th>
            </tr>
            <tr>
                @foreach($summary['south'] as $item)
                    <th class="sector-header">Sector {{ $item->sector }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr>
                @foreach($summary['south'] as $item)
                    <td class="user-cell">{{ $item->user }}</td>
                @endforeach
            </tr>
            <tr>
                @foreach($summary['south'] as $item)
                    @php $style = getBoxStyle($item->max_tickets, $thresholds); @endphp
                    <td class="color-cell" style="background-color: {{ $style['bg'] }}; color: {{ $style['text'] }}">
                        {{ $item->max_tickets }}
                    </td>
                @endforeach
            </tr>
        </tbody>
    </table>

    <!-- Detailed Report -->
    <div style="margin-top: 40px;"></div>
    @foreach($reportData as $userData)
        <div class="report-section">
            <div class="user-header">
                <h3>{{ $userData->name }}</h3>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th width="20%">Store Code</th>
                        <th width="15%">Section #</th>
                        <th width="15%">IT Area</th>
                        <th width="15%">Ticket Count</th>
                        <th width="35%">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($userData->stores as $store)
                        <tr>
                            <td style="font-weight: bold; color: #2b6cb0;">{{ $store->code }}</td>
                            <td>Sector {{ $store->sector }}</td>
                            <td>{{ $store->area }}</td>
                            <td style="text-align: center; font-weight: bold;">{{ $store->ticket_count }}</td>
                            <td>
                                <div class="health-bar-bg">
                                    <div class="health-bar-fill" style="width: {{ min(100, ($store->ticket_count / 10) * 100) }}%; background-color: {{ getHealthColor($store->ticket_count, $thresholds) }};"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

</body>
</html>
