<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 22px 20px; }
    body { font-family: DejaVu Sans Mono, monospace; font-size: 7px; line-height: 1.25; color: #111; }
    .cover { margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1.5px solid #111; font-family: DejaVu Sans, sans-serif; }
    .cover h1 { font-size: 13px; margin: 0 0 2px; }
    .cover .meta { font-size: 8px; color: #555; }
    table.grid { width: 100%; border-collapse: separate; border-spacing: 5px; table-layout: fixed; }
    table.grid td { vertical-align: top; width: 33.33%; padding: 0; }
    .r { border: 1px solid #999; padding: 5px 6px; page-break-inside: avoid; }
    .c { text-align: center; }
    .b { font-weight: bold; }
    .dash { border-top: 1px dashed #333; margin: 3px 0; height: 0; }
    .l { width: 100%; border-collapse: collapse; }
    .l td { padding: 0; vertical-align: top; }
    .l td.v { text-align: right; white-space: nowrap; padding-left: 4px; }
    .ind { padding-left: 8px; }
    .tot td { font-size: 8.5px; font-weight: bold; }
</style>
</head>
<body>
@if ($cover ?? true)
<div class="cover">
    <h1>{{ $title }}</h1>
    <div class="meta">{{ $outlet }} · {{ $count }} struk · total penjualan Rp {{ number_format($total, 0, ',', '.') }} · dicetak {{ $printedAt }}@if ($part) · bagian {{ $part }}@endif</div>
</div>
@endif
@foreach (array_chunk($receipts, 3) as $row)
    <table class="grid"><tr>
    @foreach ($row as $r)
        <td>
        <div class="r">
            <div class="c">
                <div class="b" style="font-size:8.5px">{{ $r['outlet']['name'] }}</div>
                @if ($r['outlet']['address'])<div>{{ $r['outlet']['address'] }}</div>@endif
                @if ($r['outlet']['npwp'])<div>NPWP {{ $r['outlet']['npwp'] }}</div>@endif
                @if ($r['header'])<div>{{ $r['header'] }}</div>@endif
            </div>
            <div class="dash"></div>
            @include('pdf.partials.receipt-line', ['l' => 'No', 'v' => $r['order_number']])
            @include('pdf.partials.receipt-line', ['l' => 'Tanggal', 'v' => $r['date']])
            @if ($r['cashier'])@include('pdf.partials.receipt-line', ['l' => 'Kasir', 'v' => $r['cashier']])@endif
            @if ($r['table'])@include('pdf.partials.receipt-line', ['l' => 'Meja', 'v' => $r['table']])@endif
            @if ($r['call_number'])@include('pdf.partials.receipt-line', ['l' => 'Panggil', 'v' => $r['call_number']])@endif
            @if ($r['label'])@include('pdf.partials.receipt-line', ['l' => 'Tagihan', 'v' => $r['label']])@endif
            <div class="dash"></div>
            @foreach ($r['items'] as $i)
                <div>{{ $i['name'] }}</div>
                @foreach ($i['modifiers'] as $m)<div class="ind">+ {{ $m }}</div>@endforeach
                @include('pdf.partials.receipt-line', ['l' => '  '.$i['qty'].' x '.number_format($i['unit_price'], 0, ',', '.'), 'v' => number_format($i['line_total'], 0, ',', '.')])
            @endforeach
            <div class="dash"></div>
            @include('pdf.partials.receipt-line', ['l' => 'Subtotal', 'v' => number_format($r['subtotal'], 0, ',', '.')])
            @if ($r['discount_total'] > 0)@include('pdf.partials.receipt-line', ['l' => 'Diskon', 'v' => '-'.number_format($r['discount_total'], 0, ',', '.')])@endif
            @foreach ($r['charges'] as $c)@include('pdf.partials.receipt-line', ['l' => $c['name'].($c['is_inclusive'] ? ' (incl.)' : ''), 'v' => number_format($c['amount'], 0, ',', '.')])@endforeach
            @if ($r['rounding'] != 0)@include('pdf.partials.receipt-line', ['l' => 'Pembulatan', 'v' => number_format($r['rounding'], 0, ',', '.')])@endif
            <table class="l tot"><tr><td>TOTAL</td><td class="v">{{ number_format($r['grand_total'], 0, ',', '.') }}</td></tr></table>
            @foreach ($r['payments'] as $p)@include('pdf.partials.receipt-line', ['l' => $p['method'], 'v' => number_format($p['tendered'], 0, ',', '.')])@endforeach
            @if ($r['change'] > 0)@include('pdf.partials.receipt-line', ['l' => 'Kembali', 'v' => number_format($r['change'], 0, ',', '.')])@endif
            @if ($r['footer'])<div class="c" style="margin-top:3px">{{ $r['footer'] }}</div>@endif
        </div>
        </td>
    @endforeach
    @for ($k = count($row); $k < 3; $k++)<td></td>@endfor
    </tr></table>
@endforeach
</body>
</html>
