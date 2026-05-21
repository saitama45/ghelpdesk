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
        .labels-container {
            overflow: hidden;
        }

        /* 3 per row: (190mm usable - 2 gaps × 2.5mm) / 3 ≈ 61mm each */
        .label {
            float: left;
            width: 61mm;
            height: 35mm;
            margin: 0 2mm 3mm 0;
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

    <div class="labels-container">
        @foreach($items as $label)
            @php($item = $label['item'])
            <div class="label">
                <div class="item-code">
                    {{ $item->asset?->item_code ?: 'No Item Code' }}
                </div>

                <div class="barcode-wrap">
                    @if($label['image'])
                        <img class="barcode" src="data:image/png;base64,{{ $label['image'] }}" alt="{{ $item->barcode }}">
                    @else
                        <div class="empty-image">Barcode image unavailable</div>
                    @endif
                </div>

                <div class="code">{{ $item->barcode }}</div>
                <div class="details">
                    Serial: {{ $item->serial_no ?: '-' }} |
                    Asset: {{ $item->asset?->description ?: $item->asset?->model ?: '-' }}
                </div>
                <div class="details">
                    Date: {{ $item->receive_date?->format('M d, Y') ?: '-' }} |
                    Dest: {{ $item->destination_location ?: '-' }}
                </div>
            </div>
        @endforeach
    </div>
</body>
</html>
