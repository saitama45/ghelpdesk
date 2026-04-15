<!DOCTYPE html>
<html>
<head>
    <title>Scheduling Report View</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 8pt;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 14px;
        }
        .header h1 {
            margin: 0;
            color: #1e3a8a;
            font-size: 16pt;
        }
        .meta {
            margin-top: 6px;
            font-size: 8pt;
            color: #555;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        th, td {
            border: 1px solid #d1d5db;
            padding: 4px 3px;
            text-align: center;
            word-wrap: break-word;
        }
        th {
            background: #f3f4f6;
            font-size: 7pt;
            text-transform: uppercase;
        }
        .year-head {
            background: #334155;
            color: #fff;
            font-weight: bold;
        }
        .left {
            text-align: left;
        }
        .unit-col {
            width: 8%;
        }
        .name-col {
            width: 14%;
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 7pt;
            color: #777;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>SCHEDULING REPORT VIEW</h1>
        <div class="meta">
            Years:
            {{ empty($pivotYears) ? 'None' : implode(', ', $pivotYears) }}
            |
            Sub-Unit: {{ $filters['sub_unit'] ?: 'All' }}
            |
            Store ID: {{ $filters['store_id'] ?: 'All' }}
        </div>
        <div class="meta">Generated on {{ now()->format('F d, Y h:i A') }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="2" class="unit-col left">Unit</th>
                <th rowspan="2" class="name-col left">Name</th>
                @foreach($pivotYears as $year)
                    <th colspan="{{ count($pivotStatuses) }}" class="year-head">{{ $year }}</th>
                @endforeach
            </tr>
            <tr>
                @foreach($pivotYears as $year)
                    @foreach($pivotStatuses as $status)
                        <th>{{ $status === 'Restday' ? 'RD' : $status }}</th>
                    @endforeach
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($pivotData as $row)
                <tr>
                    <td class="left">{{ $row['unit'] ?: '-' }}</td>
                    <td class="left">{{ $row['name'] }}</td>
                    @foreach($pivotYears as $year)
                        @foreach($pivotStatuses as $status)
                            <td>{{ $row['years'][$year][$status] ?? 0 }}</td>
                        @endforeach
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ 2 + (count($pivotYears) * count($pivotStatuses)) }}">No schedule data found for the selected report filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        &copy; {{ date('Y') }} Help Desk. All rights reserved.
    </div>
</body>
</html>
