<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    .sub { color: #555; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
    th { background: #f0f0f0; }
    td.num { text-align: right; }
</style>
</head>
<body>
<h1>{{ $title }}</h1>
<div class="sub">{{ $subtitle }}</div>
<table>
    <thead><tr>@foreach ($headings as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach ($rows as $row)
        <tr>@foreach ($row as $cell)<td class="{{ is_int($cell) ? 'num' : '' }}">{{ is_int($cell) ? number_format($cell, 0, ',', '.') : $cell }}</td>@endforeach</tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
