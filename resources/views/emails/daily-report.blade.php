<!doctype html>
<html lang="id">
<body style="margin:0;padding:0;background:{{ $pdf ? '#ffffff' : '#f1f5f9' }};font-family:{{ $pdf ? "'DejaVu Sans',sans-serif" : "-apple-system,'Segoe UI',Helvetica,Arial,sans-serif" }};color:#0f172a;">
@php
    $m = fn (int $v) => 'Rp'.number_format($v, 0, ',', '.');
    $change = $compare['change_percent'];
    $short = function (int $v) {
        if ($v >= 1000000) { return rtrim(rtrim(number_format($v / 1000000, 1, ',', '.'), '0'), ',').' jt'; }
        if ($v >= 1000) { return round($v / 1000).' rb'; }
        return (string) $v;
    };
    // the app accent colour, plus a fixed set of friendly colours for the rest
    $accents = ['blue' => ['#2563eb', '#dbeafe', '#1e3a8a'], 'green' => ['#059669', '#d1fae5', '#064e3b'], 'orange' => ['#ea580c', '#ffedd5', '#7c2d12'], 'violet' => ['#7c3aed', '#ede9fe', '#4c1d95']];
    [$ac, $tint, $ink] = $accents[\App\Support\Accent::key()];
    $palette = [$ac, '#14b8a6', '#f59e0b', '#ec4899', '#8b5cf6', '#06b6d4'];
    $hourMax = max(1, collect($hours)->max('total') ?? 1);
    $weekMax = max(1, collect($week)->max('total') ?? 1);
    $topMax = max(1, collect($top)->max('total') ?? 1);
    $methodMax = max(1, collect($s->byMethod)->max() ?? 1);
    $cards = [['Order', (string) $s->orders, '#eff6ff', '#2563eb'], ['Rata-rata/order', $m($average), '#ecfdf5', '#059669'], ['Diskon', $m($s->discounts), '#fffbeb', '#d97706'], ['Refund', $m($s->refunds), '#fff1f2', '#e11d48']];
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $pdf ? '#ffffff' : '#f1f5f9' }};padding:{{ $pdf ? '0' : '24px 12px' }};">
<tr><td align="center">
<table role="presentation" width="{{ $pdf ? '100%' : '560' }}" cellpadding="0" cellspacing="0" style="{{ $pdf ? 'width:100%;' : 'max-width:560px;width:100%;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;' }}background:#ffffff;">
    <tr><td style="background:{{ $ac }};padding:22px 24px;">
        <div style="font-size:13px;color:#ffffff;opacity:.85;">{{ $outlet }}</div>
        <div style="font-size:20px;font-weight:700;color:#ffffff;margin-top:2px;">Laporan harian · {{ $dateLabel }}</div>
    </td></tr>
    <tr><td style="background:{{ $tint }};padding:18px 24px;">
        <div style="font-size:13px;color:{{ $ink }};">Penjualan bersih</div>
        <div style="font-size:34px;font-weight:800;letter-spacing:-0.5px;color:{{ $ink }};">{{ $m($s->net) }}</div>
        <div style="margin-top:6px;">
        @if ($change === null)
            <span style="font-size:12px;color:#475569;">Belum ada pembanding minggu lalu</span>
        @else
            <span style="display:inline-block;font-size:12px;font-weight:600;padding:3px 9px;border-radius:999px;background:{{ $change >= 0 ? '#d1fae5' : '#ffe4e6' }};color:{{ $change >= 0 ? '#047857' : '#be123c' }};">{{ $change >= 0 ? '▲' : '▼' }} {{ abs($change) }}% dari minggu lalu</span>
            <span style="font-size:12px;color:#475569;margin-left:4px;">({{ $m($compare['last_week_revenue']) }})</span>
        @endif
        </div>
    </td></tr>
    <tr><td style="padding:16px 24px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            @foreach ($cards as [$k, $v, $bg, $fg])
                <td width="25%" style="padding:10px 8px;background:{{ $bg }};border-radius:8px;border-top:3px solid {{ $fg }};">
                    <div style="font-size:11px;color:#475569;">{{ $k }}</div>
                    <div style="font-size:15px;font-weight:700;margin-top:2px;color:{{ $fg }};">{{ $v }}</div>
                </td>
                @if (! $loop->last)<td width="8"></td>@endif
            @endforeach
        </tr></table>
    </td></tr>
    @if ($hours !== [])
    <tr><td style="padding:4px 24px 16px;page-break-inside:avoid;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="page-break-inside:avoid;"><tr><td>
        <div style="font-size:14px;font-weight:700;margin-bottom:2px;">Penjualan per jam</div>
        <div style="font-size:11px;color:#64748b;margin-bottom:8px;"><span style="color:#f59e0b;">■</span> jam teramai &nbsp; <span style="color:{{ $ac }};">■</span> jam lain &nbsp; <span style="color:{{ $ac }};font-weight:700;">3×</span> = jumlah order</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;">
            <tr>
            @foreach ($hours as $h)
                @php $bar = $h['total'] > 0 ? max(3, (int) round($h['total'] / $hourMax * 90)) : 1; $peak = $h['total'] === $hourMax && $h['total'] > 0; @endphp
                <td valign="bottom" align="center" style="padding:0 1px;height:112px;">
                    <div style="font-size:9px;color:#64748b;line-height:12px;">{{ $h['total'] > 0 ? $short($h['total']) : '' }}</div>
                    <div style="height:{{ $bar }}px;background:{{ $peak ? '#f59e0b' : $ac }};border-radius:3px 3px 0 0;font-size:0;line-height:0;">&nbsp;</div>
                </td>
            @endforeach
            </tr>
            <tr>
            @foreach ($hours as $h)
                <td align="center" style="font-size:10px;color:#64748b;padding-top:3px;border-top:1px solid #e2e8f0;">{{ $h['hour'] }}<div style="font-size:9px;color:{{ $ac }};font-weight:700;">{{ $h['orders'] > 0 ? $h['orders'].'×' : '' }}</div></td>
            @endforeach
            </tr>
        </table>
        <div style="font-size:11px;color:#64748b;margin-top:6px;">Order dihitung pada jam saat order selesai dibayar, bukan saat mulai dipesan.</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;margin-top:10px;">
            <tr><td style="padding:5px 8px;background:#f1f5f9;color:#475569;font-weight:600;border-radius:6px 0 0 6px;">Jam</td><td align="right" style="padding:5px 8px;background:#f1f5f9;color:#475569;font-weight:600;">Order</td><td align="right" style="padding:5px 8px;background:#f1f5f9;color:#475569;font-weight:600;border-radius:0 6px 6px 0;">Penjualan</td></tr>
            @foreach ($hours as $h)
                @continue($h['orders'] === 0)
                <tr><td style="padding:5px 8px;border-bottom:1px solid #f1f5f9;">{{ $h['hour'] }}:00–{{ $h['hour'] }}:59</td><td align="right" style="padding:5px 8px;border-bottom:1px solid #f1f5f9;">{{ $h['orders'] }}</td><td align="right" style="padding:5px 8px;border-bottom:1px solid #f1f5f9;font-weight:{{ $h['total'] === $hourMax ? '700' : '400' }};color:{{ $h['total'] === $hourMax ? '#b45309' : '#0f172a' }};">{{ $m($h['total']) }}</td></tr>
            @endforeach
        </table>
    </td></tr></table>
    </td></tr>
    @endif
    @if (count($week) > 1)
    <tr><td style="padding:4px 24px 16px;page-break-inside:avoid;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="page-break-inside:avoid;"><tr><td>
        <div style="font-size:14px;font-weight:700;margin-bottom:8px;">7 hari terakhir</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
            @foreach ($week as $d)
                <tr>
                    <td width="118" style="padding:4px 0;color:{{ $d['today'] ? $ac : '#475569' }};{{ $d['today'] ? 'font-weight:700;' : '' }}">{{ $d['name'] }}@if (! empty($d['tag'])) <span style="font-size:11px;font-weight:600;">· {{ $d['tag'] }}</span>@endif</td>
                    <td style="padding:4px 8px;"><div style="height:11px;width:{{ max(1, (int) round($d['total'] / $weekMax * 100)) }}%;background:{{ $d['today'] ? $ac : '#5eead4' }};border-radius:4px;font-size:0;line-height:0;">&nbsp;</div></td>
                    <td width="84" align="right" style="padding:4px 0;{{ $d['today'] ? 'font-weight:700;' : '' }}">{{ $m($d['total']) }}</td>
                </tr>
            @endforeach
        </table>
    </td></tr></table>
    </td></tr>
    @endif
    @if ($s->byMethod !== [])
    <tr><td style="padding:4px 24px 16px;page-break-inside:avoid;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="page-break-inside:avoid;"><tr><td>
        <div style="font-size:14px;font-weight:700;margin-bottom:8px;">Per metode bayar</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
            @foreach ($s->byMethod as $name => $total)
                @php $c = $palette[$loop->index % count($palette)]; @endphp
                <tr><td style="padding:5px 0 1px;"><span style="color:{{ $c }};">●</span> {{ $name }}</td><td align="right" style="padding:5px 0 1px;font-weight:600;">{{ $m($total) }}</td></tr>
                <tr><td colspan="2" style="padding:0 0 4px;"><div style="height:6px;width:{{ max(1, (int) round($total / $methodMax * 100)) }}%;background:{{ $c }};border-radius:3px;font-size:0;line-height:0;opacity:.85;">&nbsp;</div></td></tr>
            @endforeach
        </table>
    </td></tr></table>
    </td></tr>
    @endif
    @if ($top !== [])
    <tr><td style="padding:4px 24px 16px;page-break-inside:avoid;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="page-break-inside:avoid;"><tr><td>
        <div style="font-size:14px;font-weight:700;margin-bottom:8px;">Menu terlaris</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
            @foreach ($top as $p)
                @php $c = $palette[$loop->index % count($palette)]; @endphp
                <tr>
                    <td style="padding:5px 0 1px;"><span style="font-weight:700;color:{{ $c }};">{{ $loop->iteration }}.</span> {{ $p['name'] }} <span style="color:#64748b;">× {{ $p['qty'] }}</span></td>
                    <td align="right" style="padding:5px 0 1px;font-weight:600;">{{ $m($p['total']) }}</td>
                </tr>
                <tr><td colspan="2" style="padding:0 0 4px;"><div style="height:6px;width:{{ max(1, (int) round($p['total'] / $topMax * 100)) }}%;background:{{ $c }};border-radius:3px;font-size:0;line-height:0;opacity:.85;">&nbsp;</div></td></tr>
            @endforeach
        </table>
    </td></tr></table>
    </td></tr>
    @endif
    @php
        $one = count($recorders) === 1 && count($receivers) === 1 && $recorders[0]['name'] === $receivers[0]['name'];
        $groups = [['Per tipe order', $types, '#f59e0b', false], ['Order dicatat oleh', $recorders, '#ec4899', true], ['Pembayaran diterima oleh', $receivers, '#8b5cf6', true]];
        $groups = array_values(array_filter($groups, fn ($g) => count($g[1]) > 0 && ! ($one && $g[3])));
    @endphp
    @foreach ($groups as [$title, $rows, $color, $people])
    @php $max = max(1, collect($rows)->max('total') ?? 1); @endphp
    <tr><td style="padding:4px 24px 16px;page-break-inside:avoid;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="page-break-inside:avoid;"><tr><td>
        <div style="font-size:14px;font-weight:700;margin-bottom:8px;">{{ $title }}</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
            @foreach ($rows as $r)
                <tr>
                    <td width="34" valign="middle" style="padding:6px 0;">
                        @if ($people)
                            <table role="presentation" cellpadding="0" cellspacing="0" width="28" height="28" style="width:28px;height:28px;border-radius:14px;background:{{ $color }};"><tr><td align="center" valign="middle" style="color:#ffffff;font-size:12px;font-weight:700;line-height:14px;">{{ mb_strtoupper(mb_substr($r['name'], 0, 1)) }}</td></tr></table>
                        @else
                            <table role="presentation" cellpadding="0" cellspacing="0" width="28" height="28" style="width:28px;height:28px;border-radius:14px;background:{{ $color }};"><tr><td align="center" valign="middle" style="color:#ffffff;font-size:10px;font-weight:400;line-height:12px;">&nbsp;</td></tr></table>
                        @endif
                    </td>
                    <td valign="middle" style="padding:6px 8px 6px 0;">
                        <div style="font-weight:600;">{{ $r['name'] }}</div>
                        <div style="font-size:12px;color:#64748b;">{{ $r['orders'] }} order</div>
                    </td>
                    <td valign="middle" width="150" style="padding:6px 0;">
                        <div style="text-align:right;font-weight:700;">{{ $m($r['total']) }}</div>
                        <div style="height:5px;background:#f1f5f9;border-radius:3px;margin-top:4px;font-size:0;line-height:0;"><div style="height:5px;width:{{ max(2, (int) round($r['total'] / $max * 100)) }}%;background:{{ $color }};border-radius:3px;font-size:0;line-height:0;">&nbsp;</div></div>
                    </td>
                </tr>
                @if (! $loop->last)<tr><td colspan="3" style="border-bottom:1px solid #f1f5f9;font-size:0;line-height:0;height:1px;">&nbsp;</td></tr>@endif
            @endforeach
        </table>
    </td></tr></table>
    </td></tr>
    @endforeach
    @if ($one)
    <tr><td style="padding:4px 24px 16px;page-break-inside:avoid;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="page-break-inside:avoid;"><tr><td>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fdf2f8;border-radius:8px;">
            <tr>
                <td width="46" style="padding:10px 0 10px 12px;"><table role="presentation" cellpadding="0" cellspacing="0" width="30" height="30" style="width:30px;height:30px;border-radius:15px;background:#ec4899;"><tr><td align="center" valign="middle" style="color:#ffffff;font-size:13px;font-weight:700;line-height:15px;">{{ mb_strtoupper(mb_substr($recorders[0]['name'], 0, 1)) }}</td></tr></table></td>
                <td style="padding:10px 8px;font-size:13px;"><div style="font-size:12px;color:#9d174d;">Dilayani oleh</div><div style="font-weight:700;">{{ $recorders[0]['name'] }}</div></td>
                <td align="right" style="padding:10px 12px;font-size:13px;"><div style="font-weight:700;">{{ $m($recorders[0]['total']) }}</div><div style="font-size:12px;color:#64748b;">{{ $recorders[0]['orders'] }} order</div></td>
            </tr>
        </table>
    </td></tr></table>
    </td></tr>
    @endif
    <tr><td style="padding:4px 24px 16px;page-break-inside:avoid;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="page-break-inside:avoid;"><tr><td>
        <div style="font-size:14px;font-weight:700;margin-bottom:8px;">Pengeluaran kas</div>
        @if (count($expenses['items']) === 0)
            <div style="font-size:13px;padding:8px 10px;background:#f1f5f9;border-radius:6px;color:#475569;">Tidak ada pengeluaran kas hari ini.</div>
        @else
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
            @foreach ($expenses['items'] as $e)
                <tr>
                    <td style="padding:6px 0;border-bottom:1px solid #f1f5f9;">
                        <div style="font-weight:600;">{{ $e['label'] }}</div>
                        <div style="font-size:12px;color:#64748b;"><span style="display:inline-block;padding:1px 7px;border-radius:999px;background:{{ $e['source'] === 'Kas laci' ? '#ffedd5' : '#e0e7ff' }};color:{{ $e['source'] === 'Kas laci' ? '#9a3412' : '#3730a3' }};font-size:11px;font-weight:600;">{{ $e['source'] }}</span>@if ($e['by']) · {{ $e['by'] }}@endif</div>
                    </td>
                    <td align="right" valign="top" style="padding:6px 0;border-bottom:1px solid #f1f5f9;font-weight:700;color:#be123c;">{{ $m($e['amount']) }}</td>
                </tr>
            @endforeach
            <tr><td style="padding:8px 10px;background:#fff1f2;border-radius:6px 0 0 6px;font-weight:700;">Total pengeluaran</td><td align="right" style="padding:8px 10px;background:#fff1f2;border-radius:0 6px 6px 0;font-weight:800;color:#be123c;">{{ $m($expenses['total']) }}</td></tr>
        </table>
        @endif
    </td></tr></table>
    </td></tr>
    <tr><td style="padding:4px 24px 16px;page-break-inside:avoid;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="page-break-inside:avoid;"><tr><td>
        <div style="font-size:14px;font-weight:700;margin-bottom:8px;">Perlu dicek</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
            <tr><td style="padding:6px 10px;background:#fffbeb;border-radius:6px;">Pembatalan</td><td align="right" style="padding:6px 10px;background:#fffbeb;border-radius:6px;font-weight:600;color:#b45309;">{{ $s->voidItems }} item · {{ $m($s->voidValue) }}</td></tr>
            <tr><td height="6" colspan="2"></td></tr>
            <tr><td style="padding:6px 10px;background:#f5f3ff;border-radius:6px;">Komplimen / staff meal</td><td align="right" style="padding:6px 10px;background:#f5f3ff;border-radius:6px;font-weight:600;color:#6d28d9;">{{ $s->compItems }} item · {{ $m($s->compValue) }}</td></tr>
            <tr><td height="6" colspan="2"></td></tr>
            <tr><td style="padding:6px 10px;background:{{ $s->cashDifference === 0 ? '#ecfdf5' : '#fff1f2' }};border-radius:6px;">Selisih kas</td><td align="right" style="padding:6px 10px;background:{{ $s->cashDifference === 0 ? '#ecfdf5' : '#fff1f2' }};border-radius:6px;font-weight:700;color:{{ $s->cashDifference === 0 ? '#047857' : '#be123c' }};">{{ $s->cashDifference === 0 ? '✓ Cocok' : $m($s->cashDifference) }}</td></tr>
        </table>
    </td></tr></table>
    </td></tr>
@unless ($pdf)
    <tr><td style="padding:8px 24px 24px;">
        <a href="{{ $url }}" style="display:inline-block;background:{{ $ac }};color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 20px;border-radius:8px;">Buka laporan lengkap</a>
    </td></tr>
@endunless
</table>
@unless ($pdf)<div style="font-size:11px;color:#94a3b8;margin-top:12px;">Dikirim otomatis oleh POS. Atur penerima di Pengaturan › Notifikasi.</div>@endunless
</td></tr>
</table>
</body>
</html>
