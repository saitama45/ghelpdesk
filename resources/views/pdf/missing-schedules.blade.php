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
            width: 25%;
        }
        .name-cell {
            font-weight: bold;
            color: #1e293b;
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
                <th>Email Address</th>
            </tr>
        </thead>
        <tbody>
            @forelse($users as $user)
                <tr>
                    <td class="sub-unit-cell">{{ $user->sub_unit ?? '-' }}</td>
                    <td class="name-cell">{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" style="text-align: center; padding: 20px; color: #94a3b8; font-style: italic;">
                        All users have schedules for this period.
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
