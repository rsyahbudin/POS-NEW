<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 30px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
    .head { border-bottom: 2px solid #1f2937; padding-bottom: 6px; margin-bottom: 10px; }
    .head h1 { font-size: 16px; margin: 0; }
    .head .meta { color: #6b7280; margin-top: 2px; }
    h2 { font-size: 12px; margin: 14px 0 4px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th { background: #1f2937; color: #fff; text-align: left; padding: 5px 6px; font-weight: bold; }
    td { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; }
    tr:nth-child(even) td { background: #f3f4f6; }
    .num { text-align: right; white-space: nowrap; }
    tr.total td { background: #e5e7eb; font-weight: bold; border-top: 1px solid #9ca3af; }
    .foot { margin-top: 12px; color: #9ca3af; font-size: 9px; }
</style>
</head>
<body>
<div class="head">
    <h1>{{ $title }}</h1>
    <div class="meta">{{ $outlet }} · dicetak {{ $printedAt }}</div>
</div>
@foreach ($sections as $i => $section)
    @if ($i > 0 || count($sections) > 1)<h2>{{ $section['title'] }}</h2>@endif
    @php
        $numeric = [];
        foreach ($section['headings'] as $c => $_) {
            $vals = array_filter(array_column($section['rows'], $c), fn ($v) => $v !== null && $v !== '');
            $numeric[$c] = $vals !== [] && count(array_filter($vals, 'is_int')) === count($vals);
        }
    @endphp
    <table>
        <thead><tr>@foreach ($section['headings'] as $c => $h)<th class="{{ $numeric[$c] ? 'num' : '' }}">{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($section['rows'] as $row)
            <tr>@foreach ($row as $c => $cell)<td class="{{ ($numeric[$c] ?? false) ? 'num' : '' }}">{{ is_int($cell) ? number_format($cell, 0, ',', '.') : $cell }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($section['headings']) }}">Tidak ada data.</td></tr>
        @endforelse
        @if ($section['totals'])
            <tr class="total">@foreach ($section['totals'] as $c => $cell)<td class="{{ ($numeric[$c] ?? false) ? 'num' : '' }}">{{ is_int($cell) ? number_format($cell, 0, ',', '.') : $cell }}</td>@endforeach</tr>
        @endif
        </tbody>
    </table>
@endforeach
<div class="foot">Dibuat otomatis oleh POS Resto.</div>
</body>
</html>
