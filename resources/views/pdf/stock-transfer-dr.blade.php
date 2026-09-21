<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Delivery Receipt {{ $drNo }}</title>
    <style>
        @page { margin: 28px 32px 40px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; margin: 0; }
        table { border-collapse: collapse; width: 100%; }
        .header td { vertical-align: middle; }
        .logo { max-height: 56px; max-width: 150px; }
        .company-name { font-size: 15px; font-weight: bold; }
        .muted { color: #555; }
        .doc-title { font-size: 18px; font-weight: bold; letter-spacing: 2px; text-align: right; }
        .dr-box { border: 1.5px solid #111; padding: 4px 8px; text-align: right; display: inline-block; margin-top: 4px; }
        .dr-no { font-size: 13px; font-weight: bold; color: #b91c1c; }
        .rule { border-top: 2px solid #111; margin: 10px 0 8px; }
        .info td { border: 1px solid #999; padding: 5px 7px; vertical-align: top; }
        .label { font-size: 8px; text-transform: uppercase; color: #555; letter-spacing: .5px; }
        .value { font-size: 10.5px; font-weight: bold; margin-top: 1px; }
        .items { margin-top: 10px; }
        .items th { background: #1f2937; color: #fff; font-size: 8.5px; text-transform: uppercase; padding: 6px 5px; border: 1px solid #1f2937; }
        .items td { border: 1px solid #999; padding: 5px; }
        .items tr:nth-child(even) td { background: #f5f5f5; }
        .items tfoot td { font-weight: bold; background: #e5e7eb; }
        .c { text-align: center; }
        .r { text-align: right; }
        .remarks { border: 1px solid #999; padding: 6px 8px; margin-top: 10px; min-height: 36px; }
        .note { font-size: 8.5px; margin-top: 8px; font-style: italic; color: #333; }
        .signs { margin-top: 26px; }
        .signs td { width: 25%; padding: 0 8px; vertical-align: bottom; }
        .sign-name { border-bottom: 1px solid #111; height: 28px; text-align: center; font-weight: bold; font-size: 10px; padding-bottom: 2px; }
        .sign-cap { text-align: center; font-size: 8px; text-transform: uppercase; color: #333; margin-top: 3px; }
        .sign-date { text-align: center; font-size: 8px; color: #555; margin-top: 10px; }
        .footer { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 7.5px; color: #777; }
    </style>
</head>
<body>
    @php
        $place = fn ($store, $raw) => $store
            ? ($store->name && strcasecmp($store->name, $store->code) !== 0 ? $store->code.' — '.$store->name : $store->code)
            : ($raw ?: '—');
    @endphp

    <div class="footer">
        <table><tr>
            <td>{{ $drNo }} · Status: {{ $transfer->status }}</td>
            <td class="r">Generated {{ $generatedAt->format('M d, Y h:i A') }}</td>
        </tr></table>
    </div>

    <table class="header">
        <tr>
            <td style="width: 60%;">
                <table><tr>
                    @if ($logo)
                        <td style="width: 1%; padding-right: 10px;"><img src="{{ $logo }}" class="logo"></td>
                    @endif
                    <td>
                        <div class="company-name">{{ $company?->name ?? config('app.name') }}</div>
                        @if ($origin?->address)
                            <div class="muted">{{ $origin->address }}</div>
                        @endif
                    </td>
                </tr></table>
            </td>
            <td style="width: 40%; text-align: right;">
                <div class="doc-title">DELIVERY RECEIPT</div>
                <div class="dr-box">
                    <span class="label">DR No.</span><br>
                    <span class="dr-no">{{ $drNo }}</span>
                </div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table class="info">
        <tr>
            <td style="width: 50%;">
                <div class="label">Deliver From (Origin)</div>
                <div class="value">{{ $place($origin, $transfer->origin_location) }}</div>
                @if ($origin?->address)<div class="muted">{{ $origin->address }}</div>@endif
            </td>
            <td style="width: 50%;">
                <div class="label">Deliver To (Destination)</div>
                <div class="value">{{ $place($destination, $transfer->destination_location) }}</div>
                @if ($destination?->address)<div class="muted">{{ $destination->address }}</div>@endif
            </td>
        </tr>
        <tr>
            <td>
                <table><tr>
                    <td style="border: 0; padding: 0; width: 50%;">
                        <div class="label">Transfer Date</div>
                        <div class="value">{{ $transfer->transfer_date?->format('M d, Y') ?? '—' }}</div>
                    </td>
                    <td style="border: 0; padding: 0;">
                        <div class="label">Status</div>
                        <div class="value">{{ $transfer->status }}</div>
                    </td>
                </tr></table>
            </td>
            <td>
                <table><tr>
                    <td style="border: 0; padding: 0; width: 50%;">
                        <div class="label">Requested By</div>
                        <div class="value">{{ $transfer->requested_by ?: '—' }}</div>
                    </td>
                    <td style="border: 0; padding: 0;">
                        <div class="label">Posted</div>
                        <div class="value">{{ $transfer->posted_by ?: '—' }}</div>
                        @if ($transfer->posted_date)<div class="muted">{{ $transfer->posted_date->format('M d, Y h:i A') }}</div>@endif
                    </td>
                </tr></table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 13%;">Item Code</th>
                <th>Description</th>
                <th style="width: 17%;">Serial No.</th>
                <th style="width: 15%;">Barcode</th>
                <th style="width: 8%;">Cond.</th>
                <th style="width: 6%;">Qty</th>
                <th style="width: 6%;">Unit</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $i => $line)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $line['item_code'] ?: '—' }}</td>
                    <td>{{ $line['description'] ?: '—' }}</td>
                    <td>{{ $line['serial_no'] ?: '—' }}</td>
                    <td>{{ $line['barcode'] ?: '—' }}</td>
                    <td class="c">{{ $line['condition'] ?: '—' }}</td>
                    <td class="c">{{ $line['quantity'] }}</td>
                    <td class="c">pc</td>
                </tr>
            @empty
                <tr><td colspan="8" class="c muted">No items on this transfer.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" class="r">TOTAL QUANTITY</td>
                <td class="c">{{ $totalQty }}</td>
                <td class="c">pc{{ $totalQty === 1 ? '' : 's' }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="remarks">
        <div class="label">Remarks</div>
        <div>{{ $transfer->memo_remarks ?: '—' }}</div>
    </div>

    <div class="note">Received the above items in good order and condition, unless otherwise noted above.</div>

    <table class="signs">
        {{-- Names and captions sit in separate rows so the signature lines stay level. --}}
        <tr>
            <td><div class="sign-name">{{ $transfer->creator?->name }}</div></td>
            <td><div class="sign-name">{{ $transfer->posted_by }}</div></td>
            <td><div class="sign-name"></div></td>
            <td><div class="sign-name"></div></td>
        </tr>
        <tr>
            <td style="vertical-align: top;"><div class="sign-cap">Prepared By</div></td>
            <td style="vertical-align: top;"><div class="sign-cap">Released / Approved By</div></td>
            <td style="vertical-align: top;"><div class="sign-cap">Delivered By</div></td>
            <td style="vertical-align: top;"><div class="sign-cap">Received By<br>(Signature over Printed Name)</div></td>
        </tr>
        <tr>
            <td><div class="sign-date">Date: ____________</div></td>
            <td><div class="sign-date">Date: ____________</div></td>
            <td><div class="sign-date">Date: ____________</div></td>
            <td><div class="sign-date">Date: ____________</div></td>
        </tr>
    </table>
</body>
</html>
