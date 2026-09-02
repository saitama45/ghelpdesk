<!DOCTYPE html>
<html>
<head>
    <title>Stock-In Barcodes</title>
    <style>
        @page {
            margin: 10mm;
        }

        body {
            font-family: Arial, sans-serif;
            color: #111827;
            font-size: 8pt;
            margin: 0;
        }

        .header {
            text-align: center;
            margin-bottom: 8mm;
        }

        .header h1 {
            margin: 0 0 2mm;
            font-size: 16pt;
            color: #1f2937;
        }

        .meta {
            color: #4b5563;
            font-size: 8pt;
        }

        /* Container clears floats */
        .labels-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed;
        }

        .label-cell {
            width: 33.333%;
            padding: 0 2mm 3mm 0;
            vertical-align: top;
            page-break-inside: avoid;
        }

        /* 3 per row: (190mm usable - 2 gaps × 2.5mm) / 3 ≈ 61mm each */
        .label {
            height: 35mm;
            padding: 3mm;
            border: 1px solid #d1d5db;
            box-sizing: border-box;
            page-break-inside: avoid;
        }

        .item-code {
            font-size: 8pt;
            font-weight: bold;
            color: #374151;
            margin-bottom: 1.5mm;
            text-transform: uppercase;
        }

        .barcode-wrap {
            text-align: center;
            height: 16mm;
            margin-bottom: 1.5mm;
        }

        .barcode {
            max-width: 53mm;
            max-height: 16mm;
        }

        .code {
            text-align: center;
            font-size: 8pt;
            font-weight: bold;
            letter-spacing: 1px;
            margin-bottom: 1mm;
            word-break: break-all;
        }

        .details {
            color: #4b5563;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
        }

        .empty-image {
            color: #b91c1c;
            font-size: 8pt;
            padding-top: 4mm;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>STOCK-IN BARCODES</h1>
        <div class="meta">
            {{ $items->count() }} label{{ $items->count() === 1 ? '' : 's' }} generated on {{ now()->format('F d, Y h:i A') }}
        </div>
    </div>

    <table class="labels-table">
        @foreach($items->chunk(3) as $row)
            <tr>
                @foreach($row as $label)
                    @php($item = $label['item'])
                    <td class="label-cell">
                        <div class="label">
                            <div class="item-code">{{ $item->asset?->item_code ?: 'No Item Code' }}</div>
                            <div class="barcode-wrap">
                                @if($label['image'])
                                    <img class="barcode" src="{{ $label['image'] }}" alt="{{ $item->barcode }}">
                                @else
                                    <div class="empty-image">Barcode image unavailable</div>
                                @endif
                            </div>
                            <div class="code">{{ $item->barcode }}</div>
                            <div class="details">Serial: {{ $item->serial_no ?: '-' }} | Asset: {{ $item->asset?->description ?: $item->asset?->model ?: '-' }}</div>
                            <div class="details">Date: {{ $item->receive_date?->format('M d, Y') ?: '-' }} | Dest: {{ $item->destination_location ?: '-' }}</div>
                        </div>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>
</body>
</html>
