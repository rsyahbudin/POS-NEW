<!doctype html>
<html lang="id">
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;color:#111827;">
@php
    $m = fn (int $v) => 'Rp'.number_format($v, 0, ',', '.');
    $change = $compare['change_percent'];
    $short = function (int $v) {
        if ($v >= 1000000) { return rtrim(rtrim(number_format($v / 1000000, 1, ',', '.'), '0'), ',').' jt'; }
        if ($v >= 1000) { return round($v / 1000).' rb'; }
        return (string) $v;
    };
    $hourMax = max(1, collect($hours)->max('total') ?? 1);
    $weekMax = max(1, collect($week)->max('total') ?? 1);
    $topMax = max(1, collect($top)->max('total') ?? 1);
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
    @if ($hours !== [])
    <tr><td style="padding:0 24px 16px;">
        <div style="font-size:13px;font-weight:600;margin-bottom:8px;">Penjualan per jam</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;">
            <tr>
            @foreach ($hours as $h)
                @php $bar = $h['total'] > 0 ? max(3, (int) round($h['total'] / $hourMax * 90)) : 1; @endphp
                <td valign="bottom" align="center" style="padding:0 1px;height:112px;">
                    <div style="font-size:9px;color:#6b7280;line-height:12px;">{{ $h['total'] > 0 ? $short($h['total']) : '' }}</div>
                    <div style="height:{{ $bar }}px;background:{{ $h['total'] === $hourMax ? '#111827' : '#9ca3af' }};border-radius:3px 3px 0 0;font-size:0;line-height:0;">&nbsp;</div>
                </td>
            @endforeach
            </tr>
            <tr>
            @foreach ($hours as $h)
                <td align="center" style="font-size:10px;color:#6b7280;padding-top:3px;border-top:1px solid #e5e7eb;">{{ $h['hour'] }}</td>
            @endforeach
            </tr>
        </table>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;margin-top:10px;">
            <tr><td style="padding:4px 0;color:#6b7280;">Jam</td><td align="right" style="padding:4px 0;color:#6b7280;">Order</td><td align="right" style="padding:4px 0;color:#6b7280;">Penjualan</td></tr>
            @foreach ($hours as $h)
                @continue($h['orders'] === 0)
                <tr><td style="padding:4px 0;border-top:1px solid #f3f4f6;">{{ $h['hour'] }}:00–{{ $h['hour'] }}:59</td><td align="right" style="padding:4px 0;border-top:1px solid #f3f4f6;">{{ $h['orders'] }}</td><td align="right" style="padding:4px 0;border-top:1px solid #f3f4f6;">{{ $m($h['total']) }}</td></tr>
            @endforeach
        </table>
    </td></tr>
    @endif
    @if (count($week) > 1)
    <tr><td style="padding:0 24px 16px;">
        <div style="font-size:13px;font-weight:600;margin-bottom:8px;">7 hari terakhir</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
            @foreach ($week as $d)
                <tr>
                    <td width="96" style="padding:4px 0;color:{{ $d['today'] ? '#111827' : '#6b7280' }};{{ $d['today'] ? 'font-weight:600;' : '' }}">{{ $d['name'] }}</td>
                    <td style="padding:4px 8px;"><div style="height:10px;width:{{ max(1, (int) round($d['total'] / $weekMax * 100)) }}%;background:{{ $d['today'] ? '#111827' : '#d1d5db' }};border-radius:3px;font-size:0;line-height:0;">&nbsp;</div></td>
                    <td width="84" align="right" style="padding:4px 0;{{ $d['today'] ? 'font-weight:600;' : '' }}">{{ $m($d['total']) }}</td>
                </tr>
            @endforeach
        </table>
    </td></tr>
    @endif
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
        <div style="font-size:13px;font-weight:600;margin-bottom:8px;">Menu terlaris</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
            @foreach ($top as $p)
                <tr>
                    <td colspan="2" style="padding:5px 0 2px;border-top:1px solid #f3f4f6;">{{ $p['name'] }} <span style="color:#6b7280;">× {{ $p['qty'] }}</span></td>
                    <td align="right" style="padding:5px 0 2px;border-top:1px solid #f3f4f6;">{{ $m($p['total']) }}</td>
                </tr>
                <tr><td colspan="3" style="padding:0 0 4px;"><div style="height:6px;width:{{ max(1, (int) round($p['total'] / $topMax * 100)) }}%;background:#6b7280;border-radius:3px;font-size:0;line-height:0;">&nbsp;</div></td></tr>
            @endforeach
        </table>
    </td></tr>
    @endif
    @if (count($types) > 0 || count($cashiers) > 0)
    <tr><td style="padding:0 24px 16px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
        @foreach ([['Per tipe order', $types], ['Per kasir', $cashiers]] as [$title, $rows])
            @if (count($rows) > 0)
            <td valign="top" width="50%" style="padding-right:8px;">
                <div style="font-size:13px;font-weight:600;margin-bottom:6px;">{{ $title }}</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
                    @foreach ($rows as $r)
                        <tr><td style="padding:4px 0;border-top:1px solid #f3f4f6;">{{ $r['name'] }} <span style="color:#6b7280;">({{ $r['orders'] }})</span></td><td align="right" style="padding:4px 0;border-top:1px solid #f3f4f6;">{{ $m($r['total']) }}</td></tr>
                    @endforeach
                </table>
            </td>
            @endif
        @endforeach
        </tr></table>
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
