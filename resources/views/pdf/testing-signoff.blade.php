{{--
  Sign-off certificate for a QAT or UAT cycle.

  One template serves both modules: the two sign-offs record the same facts (who
  accepted what, when, with which reservations) and a certificate that differed
  between them would only invite the question of why. The caller supplies a
  $module label and the already-resolved rows, so this view does no lookups.

  dompdf notes: no flexbox/grid support, so layout is tables; the signature is a
  base64 data URI because dompdf cannot fetch an app URL.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $cycle->code }} — Sign-off Certificate</title>
    <style>
        @page { margin: 24mm 18mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            color: #1f2937;
            line-height: 1.5;
        }
        .masthead { border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 18px; }
        .eyebrow { font-size: 9px; letter-spacing: 1.4px; text-transform: uppercase; color: #6b7280; }
        h1 { font-size: 19px; margin: 4px 0 2px; }
        .sub { color: #4b5563; font-size: 11px; }

        table { width: 100%; border-collapse: collapse; }
        .facts td { padding: 5px 0; vertical-align: top; }
        .facts td.k { width: 34%; color: #6b7280; }
        .facts td.v { font-weight: bold; }

        h2 {
            font-size: 11px; text-transform: uppercase; letter-spacing: 1px;
            color: #374151; margin: 22px 0 8px;
            border-bottom: 1px solid #e5e7eb; padding-bottom: 4px;
        }

        .verdict {
            display: inline-block; padding: 5px 12px; border-radius: 3px;
            font-weight: bold; font-size: 12px;
        }
        .v-pass { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .v-reserve { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .v-reject { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        .tally td {
            border: 1px solid #e5e7eb; padding: 7px 9px; text-align: center; width: 20%;
        }
        .tally .n { font-size: 15px; font-weight: bold; display: block; }
        .tally .l { font-size: 8.5px; text-transform: uppercase; color: #6b7280; letter-spacing: .6px; }

        .rows th {
            text-align: left; font-size: 8.5px; text-transform: uppercase;
            letter-spacing: .6px; color: #6b7280; border-bottom: 1px solid #e5e7eb;
            padding: 5px 6px;
        }
        .rows td { padding: 5px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }

        .note {
            background: #f9fafb; border-left: 3px solid #d1d5db;
            padding: 8px 10px; margin-top: 6px; white-space: pre-wrap;
        }
        .waiver { background: #fffbeb; border-left-color: #f59e0b; }

        .sigbox { border: 1px solid #d1d5db; border-radius: 3px; padding: 10px 12px; }
        .sigimg { height: 62px; }
        .sigrule { border-top: 1px solid #9ca3af; margin-top: 6px; padding-top: 5px; }
        .signame { font-weight: bold; font-size: 11px; }
        .sigmeta { color: #6b7280; font-size: 9px; }
        .unsigned { color: #9ca3af; font-style: italic; padding: 20px 0 6px; }

        .foot {
            margin-top: 26px; border-top: 1px solid #e5e7eb; padding-top: 8px;
            font-size: 8.5px; color: #9ca3af;
        }
    </style>
</head>
<body>

<div class="masthead">
    <div class="eyebrow">{{ $module }} — Sign-off Certificate</div>
    <h1>{{ $cycle->code }} — {{ $cycle->title }}</h1>
    <div class="sub">{{ $cycle->system_name ?: 'System not specified' }} · {{ $cycle->environment }} · Cycle {{ $cycle->cycle_no }}</div>
</div>

<table class="facts">
    <tr>
        <td class="k">Entity</td>
        <td class="v">{{ $cycle->company->name ?? '—' }}</td>
        <td class="k">Department</td>
        <td class="v">{{ $cycle->department->name ?? 'Shared' }}</td>
    </tr>
    <tr>
        <td class="k">QA lead</td>
        <td class="v">{{ $cycle->qaLead->name ?? '—' }}</td>
        <td class="k">Dev lead</td>
        <td class="v">{{ $cycle->devLead->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">Testing started</td>
        <td class="v">{{ $cycle->start_date?->format('d M Y') ?? '—' }}</td>
        <td class="k">Target sign-off</td>
        <td class="v">{{ $cycle->target_signoff_date?->format('d M Y') ?? '—' }}</td>
    </tr>
</table>

<h2>Test Result</h2>

<table class="tally">
    <tr>
        <td><span class="n">{{ $statistics['total_cases'] }}</span><span class="l">Cases</span></td>
        <td><span class="n">{{ $statistics['passed'] }}</span><span class="l">Passed</span></td>
        <td><span class="n">{{ $statistics['failed'] }}</span><span class="l">Failed</span></td>
        <td><span class="n">{{ $statistics['blocked'] }}</span><span class="l">Blocked</span></td>
        <td><span class="n">{{ round($statistics['pass_rate'] * 100) }}%</span><span class="l">Pass rate</span></td>
    </tr>
</table>

<h2>Decision</h2>

@if ($signoff)
    @php
        $cls = match ($signoff->result) {
            'passed' => 'v-pass',
            'passed_with_reservation' => 'v-reserve',
            default => 'v-reject',
        };
        $label = $resultLabels[$signoff->result] ?? $signoff->result;
    @endphp

    <p><span class="verdict {{ $cls }}">{{ $label }}</span></p>

    @if ($signoff->remarks)
        <div class="note">{{ $signoff->remarks }}</div>
    @endif

    {{-- count(), not empty(): $waivedFindings is a Collection, and empty() on any
         object is false — which rendered this whole block, with an empty table,
         on every UAT certificate even though UAT has no waiver mechanism. --}}
    @if (count($waivedFindings))
        <h2>Findings accepted under waiver</h2>
        <p style="color:#92400e; margin:0 0 6px;">
            The following defects were still open when this cycle was signed off. The approver
            recorded a written reason for accepting them.
        </p>
        <table class="rows">
            <thead>
                <tr><th style="width:14%">Ref</th><th style="width:14%">Severity</th><th>Finding</th></tr>
            </thead>
            <tbody>
            @foreach ($waivedFindings as $finding)
                <tr>
                    <td>{{ $finding->reference }}</td>
                    <td>{{ ucfirst($finding->severity) }}</td>
                    <td>{{ $finding->title }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($signoff->waiver_reason ?? null)
            <div class="note waiver"><strong>Reason given:</strong> {{ $signoff->waiver_reason }}</div>
        @endif
    @endif

    <h2>Signature</h2>

    <table>
        <tr>
            <td style="width:58%; padding-right:14px;">
                <div class="sigbox">
                    @if ($signatureDataUri)
                        <img class="sigimg" src="{{ $signatureDataUri }}" alt="Signature">
                    @else
                        <div class="unsigned">No drawn signature on file — confirmed electronically.</div>
                    @endif
                    <div class="sigrule">
                        <div class="signame">{{ $signoff->confirmed_name ?? $signoff->confirmedBy->name ?? '—' }}</div>
                        <div class="sigmeta">
                            {{ $signoff->confirmed_email ?? $signoff->confirmedBy->email ?? '' }}
                        </div>
                        <div class="sigmeta">{{ $approverTitle }}</div>
                    </div>
                </div>
            </td>
            <td style="width:42%; vertical-align:top;">
                <table class="facts">
                    <tr><td class="k">Signed on</td></tr>
                    <tr><td class="v">{{ $signoff->confirmed_at?->timezone(config('app.timezone'))->format('d M Y, g:i A') ?? '—' }}</td></tr>
                    <tr><td class="k" style="padding-top:10px;">Recorded from</td></tr>
                    <tr><td class="v">{{ $signoff->ip_address ?? '—' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
@else
    <p class="unsigned">This cycle has not been signed off yet.</p>
@endif

@if (count($ledger) > 1)
    <h2>Sign-off history</h2>
    <table class="rows">
        <thead>
            <tr><th style="width:22%">Date</th><th style="width:26%">By</th><th style="width:22%">Result</th><th>Remarks</th></tr>
        </thead>
        <tbody>
        @foreach ($ledger as $row)
            <tr>
                <td>{{ $row->confirmed_at?->timezone(config('app.timezone'))->format('d M Y, g:i A') ?? '—' }}</td>
                <td>{{ $row->confirmed_name ?? '—' }}</td>
                <td>{{ $resultLabels[$row->result] ?? $row->result }}</td>
                <td>{{ $row->remarks ?? '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<div class="foot">
    Generated {{ now()->timezone(config('app.timezone'))->format('d M Y, g:i A') }}
    @if ($generatedBy) by {{ $generatedBy }} @endif
    · This certificate reflects the sign-off record held in the system at the time of printing.
</div>

</body>
</html>
