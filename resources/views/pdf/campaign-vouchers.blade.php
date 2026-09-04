<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $batch->title }}</title>
    <style>
        @page { margin: 6mm; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #111827; }
        table.sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td.cell { width: 33.333%; height: 50mm; padding: 0; vertical-align: top; border: .2mm dashed #9ca3af; }
        .voucher { height: 50mm; padding: 2mm 2.5mm; box-sizing: border-box; overflow: hidden; text-align: center; }
        .logos { height: 6mm; margin-bottom: .5mm; }
        .logos img { max-height: 6mm; max-width: 20mm; margin: 0 1mm; vertical-align: middle; }
        .partner { font-size: 5.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: .3px; color: #4b5563; }
        .title { font-size: 8.5pt; font-weight: bold; margin-top: .4mm; white-space: nowrap; overflow: hidden; }
        .value { font-size: 13pt; line-height: 1; font-weight: bold; color: #7c2d12; margin: .7mm 0; }
        .claim { font-size: 5.2pt; color: #374151; height: 3.5mm; overflow: hidden; }
        .code { font-family: Courier, monospace; font-size: 6pt; font-weight: bold; letter-spacing: .2px; margin: .7mm 0 .4mm; }
        .barcode { display: block; width: 55mm; max-width: 100%; height: 9mm; margin: 0 auto .7mm; }
        .terms { font-size: 4.5pt; line-height: 1.1; color: #4b5563; height: 5mm; overflow: hidden; }
    </style>
</head>
<body>
<table class="sheet">
    @foreach($vouchers->chunk(3) as $row)
        <tr>
            @foreach($row as $item)
                @php($voucher = $item['voucher'])
                <td class="cell"><div class="voucher">
                    <div class="logos">
                        @if($companyLogo)
                            <img src="{{ $companyLogo }}" alt="Company logo">
                        @endif
                        @if($partnerLogo)
                            <img src="{{ $partnerLogo }}" alt="Partner logo">
                        @endif
                    </div>
                    <div class="partner">{{ $batch->company?->name }} × {{ $batch->partner_name }}</div>
                    <div class="title">{{ $batch->title }}</div>
                    <div class="value">PHP {{ number_format((float) $batch->face_value, 2) }}</div>
                    <div class="claim">
                        @if($batch->claim_starts_on && $batch->claim_ends_on)
                            Valid {{ $batch->claim_starts_on->format('M d, Y') }} – {{ $batch->claim_ends_on->format('M d, Y') }}
                        @else
                            Claiming period: To follow
                        @endif
                        @if($batch->claim_instructions) · {{ $batch->claim_instructions }} @endif
                    </div>
                    <div class="code">{{ $voucher->code }}</div>
                    <img class="barcode" src="{{ $item['barcode'] }}" alt="{{ $voucher->code }}">
                    <div class="terms">{{ $batch->short_terms ?: 'Single use only. No cash change. Present this voucher at the cashier.' }}</div>
                </div></td>
            @endforeach
            @for($i = $row->count(); $i < 3; $i++)<td class="cell"></td>@endfor
        </tr>
        @if($loop->iteration % 5 === 0 && ! $loop->last)
            </table><div style="height: 0; line-height: 0; page-break-after: always"></div><table class="sheet">
        @endif
    @endforeach
</table>
</body>
</html>
