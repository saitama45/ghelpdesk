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
            height: 75px;
            text-align: center;
            vertical-align: middle;
            font-size: 14pt;
            font-weight: bold;
            border: 1px solid #e2e8f0;
        }
        .health-breakdown {
            width: 100%;
            margin-top: 6px;
            font-size: 7pt;
            font-weight: bold;
            color: #4a5568;
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
        function getHealthColor($count, $thresholdBands) {
            $colors = ['green' => '#22c55e', 'yellow' => '#eab308', 'orange' => '#f97316', 'red' => '#ef4444'];
            foreach ($thresholdBands as $band) {
                $withinMaximum = $band['max'] === null || $count <= $band['max'];
                if ($count >= $band['min'] && $withinMaximum) {
                    return $colors[$band['key']];
                }
            }
            return '#cbd5e0';
        }
        $thresholdColors = ['green' => '#22c55e', 'yellow' => '#eab308', 'orange' => '#f97316', 'red' => '#ef4444'];
        $criticalMinimum = collect($thresholdBands)->firstWhere('key', 'red')['min'] ?? 1;
    @endphp

    <div class="header">
        <h1>Store Health Report</h1>
        <p>As of {{ $asOfDate }}</p>
        <div style="font-size: 8pt; color: #4a5568; margin-top: 5px;">
            @if($filters['sub_unit'] !== 'all') Department: {{ $filters['sub_unit'] }} | @endif
            @if($filters['user_id'] !== 'all') User: {{ $reportData->first()->name ?? 'N/A' }} | @endif
            @if($filters['store_id'] !== 'all') Store: {{ $filters['store_id'] }} @endif
        </div>
    </div>

    <!-- Legend -->
    <div style="margin-bottom: 20px; border: 1px solid #e2e8f0; padding: 10px; border-radius: 5px;">
        <table width="100%" style="border-collapse: collapse;">
            <tr>
                <td width="15%" style="font-weight: bold; font-size: 8pt; text-transform: uppercase; color: #4a5568;">Legend:</td>
                @foreach($thresholdBands as $band)
                    <td width="20%">
                        <div style="display: inline-block; width: 10px; height: 10px; background-color: {{ $thresholdColors[$band['key']] }}; margin-right: 5px; border-radius: 2px;"></div>
                        <span style="font-size: 8pt; color: #4a5568;">
                            @if($band['max'] === null)
                                {{ $band['min'] }}+
                            @elseif($band['min'] === $band['max'])
                                {{ $band['min'] }}
                            @else
                                {{ $band['min'] }}-{{ $band['max'] }}
                            @endif
                            ({{ $band['label'] }})
                        </span>
                    </td>
                @endforeach
            </tr>
        </table>
    </div>

    @if(empty($summary['is_ct_mode']))
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
                        <td class="color-cell" style="background-color: #ffffff; color: #1a202c">
                            <div style="font-size: 8pt; color: #718096; text-transform: uppercase;">Affected Stores</div>
                            <div>{{ $item->store_count ?? 0 }}</div>
                            <div style="font-size: 7pt; color: #718096;">{{ $item->total_tickets ?? 0 }} tickets</div>
                            <table class="health-breakdown">
                                <tr>
                                    <td><span style="color:#22c55e;">G</span> {{ data_get($item, 'health_store_counts.green', 0) }} stores / {{ data_get($item, 'health_ticket_counts.green', 0) }} tickets</td>
                                    <td><span style="color:#eab308;">Y</span> {{ data_get($item, 'health_store_counts.yellow', 0) }} stores / {{ data_get($item, 'health_ticket_counts.yellow', 0) }} tickets</td>
                                </tr>
                                <tr>
                                    <td><span style="color:#f97316;">O</span> {{ data_get($item, 'health_store_counts.orange', 0) }} stores / {{ data_get($item, 'health_ticket_counts.orange', 0) }} tickets</td>
                                    <td><span style="color:#ef4444;">R</span> {{ data_get($item, 'health_store_counts.red', 0) }} stores / {{ data_get($item, 'health_ticket_counts.red', 0) }} tickets</td>
                                </tr>
                            </table>
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
                        <td class="color-cell" style="background-color: #ffffff; color: #1a202c">
                            <div style="font-size: 8pt; color: #718096; text-transform: uppercase;">Affected Stores</div>
                            <div>{{ $item->store_count ?? 0 }}</div>
                            <div style="font-size: 7pt; color: #718096;">{{ $item->total_tickets ?? 0 }} tickets</div>
                            <table class="health-breakdown">
                                <tr>
                                    <td><span style="color:#22c55e;">G</span> {{ data_get($item, 'health_store_counts.green', 0) }} stores / {{ data_get($item, 'health_ticket_counts.green', 0) }} tickets</td>
                                    <td><span style="color:#eab308;">Y</span> {{ data_get($item, 'health_store_counts.yellow', 0) }} stores / {{ data_get($item, 'health_ticket_counts.yellow', 0) }} tickets</td>
                                </tr>
                                <tr>
                                    <td><span style="color:#f97316;">O</span> {{ data_get($item, 'health_store_counts.orange', 0) }} stores / {{ data_get($item, 'health_ticket_counts.orange', 0) }} tickets</td>
                                    <td><span style="color:#ef4444;">R</span> {{ data_get($item, 'health_store_counts.red', 0) }} stores / {{ data_get($item, 'health_ticket_counts.red', 0) }} tickets</td>
                                </tr>
                            </table>
                        </td>
                    @endforeach
                </tr>
            </tbody>
        </table>
    @else
        @if(count($summary['ct']) > 0)
        <!-- CT Area Summary -->
        <table class="summary-table">
            <thead>
                <tr>
                    <th colspan="{{ count($summary['ct']) }}" class="summary-header">C O R P O R A T E &nbsp;&nbsp; T E C H N O L O G Y</th>
                </tr>
                <tr>
                    @foreach($summary['ct'] as $item)
                        <th class="sector-header">{{ $item->store_code }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
                    @foreach($summary['ct'] as $item)
                        <td class="user-cell">{{ $item->store_name }}</td>
                    @endforeach
                </tr>
                <tr>
                    @foreach($summary['ct'] as $item)
                        <td class="color-cell" style="background-color: #ffffff; color: #1a202c">
                            <div style="font-size: 8pt; color: #718096; text-transform: uppercase;">Affected Stores</div>
                            <div>{{ $item->store_count ?? 0 }}</div>
                            <div style="font-size: 7pt; color: #718096;">{{ $item->total_tickets ?? 0 }} tickets</div>
                            <table class="health-breakdown">
                                <tr>
                                    <td><span style="color:#22c55e;">G</span> {{ data_get($item, 'health_store_counts.green', 0) }} stores / {{ data_get($item, 'health_ticket_counts.green', 0) }} tickets</td>
                                    <td><span style="color:#eab308;">Y</span> {{ data_get($item, 'health_store_counts.yellow', 0) }} stores / {{ data_get($item, 'health_ticket_counts.yellow', 0) }} tickets</td>
                                </tr>
                                <tr>
                                    <td><span style="color:#f97316;">O</span> {{ data_get($item, 'health_store_counts.orange', 0) }} stores / {{ data_get($item, 'health_ticket_counts.orange', 0) }} tickets</td>
                                    <td><span style="color:#ef4444;">R</span> {{ data_get($item, 'health_store_counts.red', 0) }} stores / {{ data_get($item, 'health_ticket_counts.red', 0) }} tickets</td>
                                </tr>
                            </table>
                        </td>
                    @endforeach
                </tr>
            </tbody>
        </table>
        @endif
    @endif

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
                                    <div class="health-bar-fill" style="width: {{ min(100, ($store->ticket_count / max(1, $criticalMinimum)) * 100) }}%; background-color: {{ getHealthColor($store->ticket_count, $thresholdBands) }};"></div>
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
