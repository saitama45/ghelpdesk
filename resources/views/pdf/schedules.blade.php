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
        .col-tech      { width: 11%; }
        .col-store     { width: 11%; }
        .col-status    { width: 8%; }
        .col-time      { width: 13%; }
        .col-pickup    { width: 11%; }
        .col-backlogs  { width: 11%; }
        .col-remarks   { width: 17%; }
        .col-actual    { width: 9%; }
        .actual-in     { color: #16a34a; font-weight: bold; }
        .actual-out    { color: #ea580c; font-weight: bold; }
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
                <th class="col-time">Sched. Time</th>
                <th class="col-pickup">Pickup</th>
                <th class="col-backlogs">Backlogs</th>
                <th class="col-remarks">Off-site Remarks</th>
                <th class="col-actual">Actual In</th>
                <th class="col-actual">Actual Out</th>
            </tr>
        </thead>
        <tbody>
            @foreach($groupedRows as $date => $dayRows)
                <tr>
                    <td colspan="9" class="date-row">
                        {{ \Carbon\Carbon::parse($date)->format('l, F d, Y') }}
                    </td>
                </tr>
                @foreach($dayRows as $row)
                    <tr>
                        <td>{{ $row['user'] }}</td>
                        <td>{{ $row['store'] }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td class="nowrap">
                            {{ \Carbon\Carbon::parse($row['start_time'])->format('h:i A') }} - {{ \Carbon\Carbon::parse($row['end_time'])->format('h:i A') }}
                        </td>
                        <td class="nowrap">
                            @if($row['pickup_start'])
                                {{ \Carbon\Carbon::parse($row['pickup_start'])->format('h:i A') }} - {{ \Carbon\Carbon::parse($row['pickup_end'])->format('h:i A') }}
                            @else
                                -
                            @endif
                        </td>
                        <td class="nowrap">
                            @if($row['backlogs_start'])
                                {{ \Carbon\Carbon::parse($row['backlogs_start'])->format('h:i A') }} - {{ \Carbon\Carbon::parse($row['backlogs_end'])->format('h:i A') }}
                            @else
                                -
                            @endif
                        </td>
                        <td>{{ $row['remarks'] ?? '-' }}</td>
                        <td class="nowrap actual-in">
                            {{ $row['actual_time_in'] ? \Carbon\Carbon::parse($row['actual_time_in'])->format('h:i A') : '-' }}
                        </td>
                        <td class="nowrap actual-out">
                            {{ $row['actual_time_out'] ? \Carbon\Carbon::parse($row['actual_time_out'])->format('h:i A') : '-' }}
                        </td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        &copy; {{ date('Y') }} Help Desk. All rights reserved.
    </div>
</body>
</html>
