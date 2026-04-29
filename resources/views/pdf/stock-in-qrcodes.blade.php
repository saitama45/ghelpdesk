<!DOCTYPE html>
<html>
<head>
    <title>Stock-In QR Codes</title>
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

        .label {
            display: inline-block;
            vertical-align: top;
            width: 59mm;
            height: 70mm;
            margin: 0 2mm 4mm 0;
            padding: 4mm;
            border: 1px solid #d1d5db;
            box-sizing: border-box;
            page-break-inside: avoid;
            text-align: center;
        }

        .item-code {
            font-size: 8pt;
            font-weight: bold;
            color: #374151;
            margin-bottom: 2mm;
            text-transform: uppercase;
        }

        .qr-wrap {
            height: 34mm;
            margin-bottom: 2mm;
        }

        .qr-code {
            width: 32mm;
            height: 32mm;
        }

        .code {
            font-size: 8pt;
            font-weight: bold;
            line-height: 1.25;
            margin-bottom: 1mm;
            word-break: break-word;
        }

        .details {
            color: #4b5563;
            line-height: 1.25;
        }

        .empty-image {
            color: #b91c1c;
            font-size: 8pt;
            padding-top: 12mm;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>STOCK-IN QR CODES</h1>
        <div class="meta">
            {{ $items->count() }} label{{ $items->count() === 1 ? '' : 's' }} generated on {{ now()->format('F d, Y h:i A') }}
        </div>
    </div>

    @foreach($items as $label)
        @php($item = $label['item'])
        <div class="label">
            <div class="item-code">
                {{ $item->asset?->item_code ?: 'No Item Code' }}
            </div>

            <div class="qr-wrap">
                @if($label['image'])
                    <img class="qr-code" src="data:image/png;base64,{{ $label['image'] }}" alt="QR Code">
                @else
                    <div class="empty-image">QR image unavailable</div>
                @endif
            </div>

            <div class="code">
                {{ $item->serial_no ?: $item->barcode ?: 'No serial/barcode' }}
            </div>
            <div class="details">
                {{ $item->asset?->description ?: $item->asset?->model ?: '-' }}
            </div>
            <div class="details">
                {{ $item->receive_date?->format('M d, Y') ?: '-' }}
            </div>
        </div>
    @endforeach
</body>
</html>
