<!doctype html>
<html lang="id">
<body style="margin:0;padding:16px;background:#ffffff;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;color:#111827;">
@php
    $m = fn (int $v) => 'Rp'.number_format($v, 0, ',', '.');
    $change = $compare['change_percent'];
@endphp
<p style="margin:0 0 14px;">Halo,</p>
<p style="margin:0 0 14px;">Berikut ringkasan penjualan <strong>{{ $outlet }}</strong> untuk {{ $dateLabel }}.</p>
<ul style="margin:0 0 14px;padding-left:20px;">
    <li>Penjualan bersih: <strong>{{ $m($s->net) }}</strong>@if ($change !== null) ({{ $change >= 0 ? 'naik' : 'turun' }} {{ abs($change) }}% dari hari yang sama minggu lalu)@endif</li>
    <li>Jumlah order: {{ $s->orders }} (rata-rata {{ $m($average) }} per order)</li>
    @if ($s->byMethod !== [])
        <li>Metode bayar: @foreach ($s->byMethod as $name => $total){{ $name }} {{ $m($total) }}{{ $loop->last ? '' : ', ' }}@endforeach</li>
    @endif
    @if ($s->discounts > 0)<li>Diskon: {{ $m($s->discounts) }}</li>@endif
    @if ($s->refunds > 0)<li>Refund: {{ $m($s->refunds) }}</li>@endif
    @if ($s->voidItems > 0)<li>Pembatalan: {{ $s->voidItems }} item ({{ $m($s->voidValue) }})</li>@endif
    @if (count($expenses['items']) > 0)<li>Pengeluaran kas: {{ $m($expenses['total']) }} ({{ count($expenses['items']) }} catatan)</li>@endif
    <li>Selisih kas: {{ $s->cashDifference === 0 ? 'cocok' : $m($s->cashDifference) }}</li>
</ul>
<p style="margin:0 0 14px;">Rincian lengkap (grafik per jam, menu terlaris, staf, dan pengeluaran) ada di PDF terlampir.</p>
<p style="margin:0;">Salam,<br>{{ $outlet }}</p>
</body>
</html>
