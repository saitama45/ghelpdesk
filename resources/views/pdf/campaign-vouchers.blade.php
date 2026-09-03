<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $batch->title }}</title>
    <style>
        @page { margin: 10mm 19mm; }
        body { margin: 0; font-family: DejaVu Sans, Arial, sans-serif; color: #111827; }
        table.sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td.cell { width: 50%; height: 50mm; padding: 0; vertical-align: top; border: .2mm dashed #9ca3af; }
        .voucher { height: 50mm; padding: 2mm 4mm; box-sizing: border-box; overflow: hidden; text-align: center; }
        .logos { height: 8mm; margin-bottom: 1mm; }
        .logos img { max-height: 8mm; max-width: 28mm; margin: 0 2mm; vertical-align: middle; }
        .partner { font-size: 6.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; color: #4b5563; }
        .title { font-size: 10pt; font-weight: bold; margin-top: .5mm; white-space: nowrap; overflow: hidden; }
        .value { font-size: 16pt; line-height: 1; font-weight: bold; color: #7c2d12; margin: 1mm 0; }
        .claim { font-size: 6pt; color: #374151; height: 4mm; overflow: hidden; }
        .code { font-family: DejaVu Sans Mono, monospace; font-size: 7pt; font-weight: bold; letter-spacing: .3px; margin: 1mm 0 .5mm; }
        .barcode { display: block; width: 72mm; max-width: 100%; height: 10mm; margin: 0 auto 1mm; }
        .terms { font-size: 5pt; line-height: 1.15; color: #4b5563; height: 6mm; overflow: hidden; }
    </style>
</head>
<body>
<table class="sheet">
    @foreach($vouchers->chunk(2) as $row)
        <tr>
            @foreach($row as $item)
                @php($voucher = $item['voucher'])
                <td class="cell"><div class="voucher">
                    <div class="logos">
                        @if($batch->company?->logo && file_exists(storage_path('app/public/'.$batch->company->logo)))
                            <img src="{{ storage_path('app/public/'.$batch->company->logo) }}" alt="Company logo">
                        @endif
                        @if($batch->partner_logo_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($batch->partner_logo_path))
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('local')->path($batch->partner_logo_path) }}" alt="Partner logo">
                        @endif
                    </div>
                    <div class="partner">{{ $batch->company?->name }} × {{ $batch->partner_name }}</div>
                    <div class="title">{{ $batch->title }}</div>
                    <div class="value">₱{{ number_format((float) $batch->face_value, 2) }}</div>
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
            @for($i = $row->count(); $i < 2; $i++)<td class="cell"></td>@endfor
        </tr>
        @if($loop->iteration % 5 === 0 && ! $loop->last)
            </table><div style="page-break-after: always"></div><table class="sheet">
        @endif
    @endforeach
</table>
</body>
</html>
