<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 24px; }
    body { font-family: DejaVu Sans, sans-serif; margin: 0; }
    table.sheet { width: 100%; border-collapse: separate; border-spacing: 10px; table-layout: fixed; }
    table.sheet td { width: 50%; height: 245px; border: 1px dashed #999; text-align: center; vertical-align: middle; padding: 8px 0; }
    .page { page-break-after: always; }
    .page.last { page-break-after: auto; }
    img { width: 170px; height: 170px; }
    .name { font-size: 26px; font-weight: bold; margin-top: 6px; }
    .hint { font-size: 11px; color: #555; margin-top: 3px; }
</style>
</head>
<body>
@foreach ($cards->chunk(6) as $page)
    <div class="page {{ $loop->last ? 'last' : '' }}">
        <table class="sheet">
            @foreach ($page->chunk(2) as $row)
                <tr>
                    @foreach ($row as $card)
                        <td>
                            <div class="hint">{{ $outlet }}</div>
                            <img src="{{ $card['svg'] }}" alt="QR {{ $card['name'] }}">
                            <div class="name">{{ $card['name'] }}</div>
                            <div class="hint">Scan untuk memesan · Pembayaran di kasir</div>
                        </td>
                    @endforeach
                    @if ($row->count() < 2)
                        <td style="border: none"></td>
                    @endif
                </tr>
            @endforeach
        </table>
    </div>
@endforeach
</body>
</html>
