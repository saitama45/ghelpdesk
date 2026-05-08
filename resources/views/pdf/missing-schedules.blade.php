<!DOCTYPE html>
<html>
<head>
    <title>Missing Schedules Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            color: #333;
            margin: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background-color: #f8fafc;
            color: #64748b;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 8pt;
            letter-spacing: 0.05em;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #1e40af;
            font-size: 20pt;
            text-transform: uppercase;
        }
        .header p {
            margin: 5px 0 0;
            color: #64748b;
            font-size: 10pt;
        }
        .range-info {
            margin-bottom: 15px;
            font-weight: bold;
            color: #475569;
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 8pt;
            color: #94a3b8;
            padding: 10px 0;
        }
        .sub-unit-cell {
            font-weight: bold;
            color: #64748b;
            width: 11%;
        }
        .name-cell {
            font-weight: bold;
            color: #1e293b;
            width: 14%;
        }
        .days-cell {
            color: #dc2626;
            font-size: 9pt;
            line-height: 1.4;
        }
        .location-cell {
            color: #b45309;
            font-size: 9pt;
            line-height: 1.4;
        }
        .time-in-cell {
            color: #047857;
            font-size: 9pt;
            line-height: 1.4;
        }
        .time-out-cell {
            color: #ea580c;
            font-size: 9pt;
            line-height: 1.4;
        }
        .empty-cell {
            color: #cbd5e1;
            font-style: italic;
        }
        .count-cell {
            font-weight: bold;
            text-align: center;
            width: 8%;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Missing Schedules Report</h1>
        <p>Generated on {{ now()->format('F d, Y h:i A') }}</p>
    </div>

    <div class="range-info">
        Period: {{ $rangeStart->format('F d, Y') }} to {{ $rangeEnd->format('F d, Y') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Sub-Unit</th>
                <th>User Name</th>
                <th>Missing Days</th>
                <th>Missing Location</th>
                <th>Missing Actual Time In</th>
                <th>Missing Actual Time Out</th>
                <th style="text-align: center;">Count</th>
            </tr>
        </thead>
        <tbody>
            @forelse($users as $user)
                @php
                    $missingDays = $user->missing_days ?? [];
                    $missingLocations = $user->missing_locations ?? [];
                    $missingActualTimeIns = $user->missing_actual_time_ins ?? [];
                    $missingActualTimeOuts = $user->missing_actual_time_outs ?? [];
                @endphp
                <tr>
                    <td class="sub-unit-cell">{{ $user->sub_unit ?? '-' }}</td>
                    <td class="name-cell">{{ $user->name }}</td>
                    <td class="days-cell">
                        @if(count($missingDays))
                            {{ implode(', ', $missingDays) }}
                        @else
                            <span class="empty-cell">-</span>
                        @endif
                    </td>
                    <td class="location-cell">
                        @if(count($missingLocations))
                            {{ implode(', ', $missingLocations) }}
                        @else
                            <span class="empty-cell">-</span>
                        @endif
                    </td>
                    <td class="time-in-cell">
                        @if(count($missingActualTimeIns))
                            {{ implode(', ', $missingActualTimeIns) }}
                        @else
                            <span class="empty-cell">-</span>
                        @endif
                    </td>
                    <td class="time-out-cell">
                        @if(count($missingActualTimeOuts))
                            {{ implode(', ', $missingActualTimeOuts) }}
                        @else
                            <span class="empty-cell">-</span>
                        @endif
                    </td>
                    <td class="count-cell">{{ $user->missing_total_count ?? $user->missing_days_count }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center; padding: 20px; color: #94a3b8; font-style: italic;">
                        No missing schedule records found for this period.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        &copy; {{ date('Y') }} Help Desk System. All rights reserved.
    </div>
</body>
</html>
