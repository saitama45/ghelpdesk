@php
    $schedule = $changeRequest->schedule;
    $requester = $changeRequest->requester;
    $original = $changeRequest->original_payload ?? [];
    $requested = $changeRequest->requested_payload ?? [];
    $stores = collect($requested['stores'] ?? []);
    $storeNamesById = collect($storeNamesById ?? []);
    $ticketLabelsById = collect($ticketLabelsById ?? []);

    $formatDateTime = function ($value) {
        if (blank($value)) {
            return '-';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)
                ->timezone('Asia/Manila')
                ->format('M d, Y h:i A');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    };

    $formatTime = function ($value) {
        if (blank($value)) {
            return '-';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('h:i A');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    };

    $dateRange = $stores->isNotEmpty()
        ? $formatDateTime($stores->min('start_time')) . ' to ' . $formatDateTime($stores->max('end_time'))
        : '-';
@endphp

<div style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5;">
    <h2 style="margin: 0 0 12px;">Schedule Change Request</h2>
    <p style="margin: 0 0 12px;">
        Request #{{ $changeRequest->id }} is currently <strong>{{ ucfirst($changeRequest->status) }}</strong>.
    </p>
    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 640px;">
        <tr>
            <td style="font-weight: bold; width: 160px;">Requester</td>
            <td>{{ $requester?->name ?? 'Unknown' }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Schedule User</td>
            <td>{{ $schedule?->user?->name ?? 'Unknown' }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Requested Status</td>
            <td>{{ $original['status'] ?? '-' }} &rarr; {{ $requested['status'] ?? '-' }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Requested Dates</td>
            <td>{{ $dateRange }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Pickup Window</td>
            <td>{{ $formatTime($requested['pickup_start'] ?? null) }} to {{ $formatTime($requested['pickup_end'] ?? null) }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Backlogs Window</td>
            <td>{{ $formatTime($requested['backlogs_start'] ?? null) }} to {{ $formatTime($requested['backlogs_end'] ?? null) }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Remarks</td>
            <td>{{ $changeRequest->requester_remarks ?: '-' }}</td>
        </tr>
    </table>

    <h3 style="margin: 18px 0 8px;">Deployment Entries</h3>
    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 820px; border: 1px solid #e5e7eb;">
        <thead>
            <tr style="background: #f3f4f6;">
                <th align="left" style="border: 1px solid #e5e7eb;">#</th>
                <th align="left" style="border: 1px solid #e5e7eb;">Start</th>
                <th align="left" style="border: 1px solid #e5e7eb;">End</th>
                <th align="left" style="border: 1px solid #e5e7eb;">Location ID</th>
                <th align="left" style="border: 1px solid #e5e7eb;">Ticket ID</th>
                <th align="left" style="border: 1px solid #e5e7eb;">Grace</th>
                <th align="left" style="border: 1px solid #e5e7eb;">Activities / Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($stores as $index => $entry)
                <tr>
                    <td style="border: 1px solid #e5e7eb;">{{ $index + 1 }}</td>
                    <td style="border: 1px solid #e5e7eb;">{{ $formatDateTime($entry['start_time'] ?? null) }}</td>
                    <td style="border: 1px solid #e5e7eb;">{{ $formatDateTime($entry['end_time'] ?? null) }}</td>
                    <td style="border: 1px solid #e5e7eb;">
                        {{ $storeNamesById->get($entry['store_id'] ?? null) ?? ($entry['store_id'] ?? '-') }}
                    </td>
                    <td style="border: 1px solid #e5e7eb;">
                        {{ $ticketLabelsById->get($entry['ticket_id'] ?? null) ?? ($entry['ticket_id'] ?? '-') }}
                    </td>
                    <td style="border: 1px solid #e5e7eb;">{{ $entry['grace_period_minutes'] ?? 30 }} min</td>
                    <td style="border: 1px solid #e5e7eb;">{{ $entry['remarks'] ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="border: 1px solid #e5e7eb;">No deployment entries were submitted.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p style="margin-top: 16px;">
        Open the Scheduling page to review the request.
    </p>
</div>
