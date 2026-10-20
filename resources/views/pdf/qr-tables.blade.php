<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 24px; }
    body { font-family: DejaVu Sans, sans-serif; }
    .card { width: 46%; display: inline-block; margin: 1.5%; padding: 16px 0; border: 1px dashed #999; text-align: center; page-break-inside: avoid; }
    .card img { width: 240px; height: 240px; }
    .name { font-size: 28px; font-weight: bold; margin-top: 8px; }
    .hint { font-size: 12px; color: #555; margin-top: 4px; }
</style>
</head>
<body>
@foreach ($cards as $card)
    <div class="card">
        <div class="hint">{{ $outlet }}</div>
        <img src="{{ $card['svg'] }}" alt="QR {{ $card['name'] }}">
        <div class="name">{{ $card['name'] }}</div>
        <div class="hint">Scan untuk memesan · Pembayaran di kasir</div>
    </div>
@endforeach
</body>
</html>
