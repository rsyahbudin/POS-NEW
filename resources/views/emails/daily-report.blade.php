<!doctype html>
<html lang="id">
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;color:#111827;">
@php
    $m = fn (int $v) => 'Rp'.number_format($v, 0, ',', '.');
    $change = $compare['change_percent'];
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;">
    <tr><td style="padding:24px 24px 8px;">
        <div style="font-size:13px;color:#6b7280;">{{ $outlet }}</div>
        <div style="font-size:20px;font-weight:700;margin-top:2px;">Laporan harian · {{ $dateLabel }}</div>
    </td></tr>
    <tr><td style="padding:8px 24px 16px;">
        <div style="font-size:13px;color:#6b7280;">Penjualan bersih</div>
        <div style="font-size:32px;font-weight:700;letter-spacing:-0.5px;">{{ $m($s->net) }}</div>
        <div style="font-size:13px;margin-top:2px;color:{{ $change === null ? '#6b7280' : ($change >= 0 ? '#047857' : '#be123c') }};">
            @if ($change === null)
                Belum ada pembanding minggu lalu
            @else
                {{ $change >= 0 ? '▲' : '▼' }} {{ abs($change) }}% dibanding hari yang sama minggu lalu ({{ $m($compare['last_week_revenue']) }})
            @endif
        </div>
    </td></tr>
    <tr><td style="padding:0 24px 16px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            @foreach ([['Order', (string) $s->orders], ['Rata-rata/order', $m($average)], ['Diskon', $m($s->discounts)], ['Refund', $m($s->refunds)]] as [$k, $v])
                <td width="25%" style="padding:10px 8px;background:#f9fafb;border-radius:8px;">
                    <div style="font-size:11px;color:#6b7280;">{{ $k }}</div>
                    <div style="font-size:15px;font-weight:600;margin-top:2px;">{{ $v }}</div>
                </td>
                @if (! $loop->last)<td width="8"></td>@endif
            @endforeach
        </tr></table>
    </td></tr>
    @if ($s->byMethod !== [])
    <tr><td style="padding:0 24px 16px;">
        <div style="font-size:13px;font-weight:600;margin-bottom:6px;">Per metode bayar</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
            @foreach ($s->byMethod as $name => $total)
                <tr><td style="padding:5px 0;border-top:1px solid #f3f4f6;">{{ $name }}</td><td align="right" style="padding:5px 0;border-top:1px solid #f3f4f6;">{{ $m($total) }}</td></tr>
            @endforeach
        </table>
    </td></tr>
    @endif
    @if ($top !== [])
    <tr><td style="padding:0 24px 16px;">
        <div style="font-size:13px;font-weight:600;margin-bottom:6px;">Menu terlaris</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
            @foreach ($top as $p)
                <tr><td style="padding:5px 0;border-top:1px solid #f3f4f6;">{{ $p['name'] }} <span style="color:#6b7280;">× {{ $p['qty'] }}</span></td><td align="right" style="padding:5px 0;border-top:1px solid #f3f4f6;">{{ $m($p['total']) }}</td></tr>
            @endforeach
        </table>
    </td></tr>
    @endif
    <tr><td style="padding:0 24px 16px;">
        <div style="font-size:13px;font-weight:600;margin-bottom:6px;">Perlu dicek</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
            <tr><td style="padding:5px 0;border-top:1px solid #f3f4f6;">Void</td><td align="right" style="padding:5px 0;border-top:1px solid #f3f4f6;">{{ $s->voidItems }} item · {{ $m($s->voidValue) }}</td></tr>
            <tr><td style="padding:5px 0;border-top:1px solid #f3f4f6;">Komplimen / staff meal</td><td align="right" style="padding:5px 0;border-top:1px solid #f3f4f6;">{{ $s->compItems }} item · {{ $m($s->compValue) }}</td></tr>
            <tr><td style="padding:5px 0;border-top:1px solid #f3f4f6;">Selisih kas</td><td align="right" style="padding:5px 0;border-top:1px solid #f3f4f6;font-weight:600;color:{{ $s->cashDifference === 0 ? '#047857' : '#be123c' }};">{{ $s->cashDifference === 0 ? 'Cocok' : $m($s->cashDifference) }}</td></tr>
        </table>
    </td></tr>
    <tr><td style="padding:8px 24px 24px;">
        <a href="{{ $url }}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:11px 18px;border-radius:8px;">Buka laporan lengkap</a>
    </td></tr>
</table>
<div style="font-size:11px;color:#9ca3af;margin-top:12px;">Dikirim otomatis oleh POS. Atur penerima di Pengaturan › Notifikasi.</div>
</td></tr>
</table>
</body>
</html>
