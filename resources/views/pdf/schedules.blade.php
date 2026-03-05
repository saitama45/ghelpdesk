<!DOCTYPE html>
<html>
<head>
    <title>Scheduling Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 9pt;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            table-layout: fixed;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 6px 4px;
            text-align: left;
            word-wrap: break-word;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 8pt;
        }
        .date-row {
            background-color: #e9ecef;
            font-weight: bold;
            text-align: center;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #2563eb;
            font-size: 18pt;
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 7pt;
            color: #777;
        }
        .nowrap {
            white-space: nowrap;
        }
        /* Column Widths (Total 100%) */
        .col-tech { width: 13%; }
        .col-store { width: 13%; }
        .col-status { width: 9%; }
        .col-time { width: 16%; }
        .col-pickup { width: 13%; }
        .col-backlogs { width: 13%; }
        .col-remarks { width: 23%; }
    </style>
</head>
<body>
    <div class="header">
        <h1>SCHEDULING REPORT</h1>
        <p>Generated on {{ now()->format('F d, Y h:i A') }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th class="col-tech">Assigned Tech</th>
                <th class="col-store">Store</th>
                <th class="col-status">Status</th>
                <th class="col-time">Time</th>
                <th class="col-pickup">Pickup</th>
                <th class="col-backlogs">Backlogs</th>
                <th class="col-remarks">Off-site Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($groupedSchedules as $date => $daySchedules)
                <tr>
                    <td colspan="7" class="date-row">
                        {{ \Carbon\Carbon::parse($date)->format('l, F d, Y') }}
                    </td>
                </tr>
                @foreach($daySchedules as $schedule)
                    <tr>
                        <td>{{ $schedule->user->name }}</td>
                        <td>{{ $schedule->store->name ?? '-' }}</td>
                        <td>{{ $schedule->status }}</td>
                        <td class="nowrap">
                            {{ \Carbon\Carbon::parse($schedule->start_time)->format('h:i A') }} - {{ \Carbon\Carbon::parse($schedule->end_time)->format('h:i A') }}
                        </td>
                        <td class="nowrap">
                            @if($schedule->pickup_start)
                                {{ \Carbon\Carbon::parse($schedule->pickup_start)->format('h:i A') }} - {{ \Carbon\Carbon::parse($schedule->pickup_end)->format('h:i A') }}
                            @else
                                -
                            @endif
                        </td>
                        <td class="nowrap">
                            @if($schedule->backlogs_start)
                                {{ \Carbon\Carbon::parse($schedule->backlogs_start)->format('h:i A') }} - {{ \Carbon\Carbon::parse($schedule->backlogs_end)->format('h:i A') }}
                            @else
                                -
                            @endif
                        </td>
                        <td>{{ $schedule->remarks ?? '-' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        &copy; {{ date('Y') }} Amalgated Help Desk. All rights reserved.
    </div>
</body>
</html>
