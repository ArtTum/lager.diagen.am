<!doctype html>
<html lang="hy">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #18243b; font-family: dejavusans, sans-serif; font-size: 8pt; }
        h1 { margin: 0 0 5px; font-size: 17pt; }
        .meta { margin: 0 0 13px; color: #68758d; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { background: #eef1f7; color: #34415a; font-weight: bold; }
        th, td { border: 1px solid #dce2ec; padding: 5px 6px; vertical-align: top; }
        tr { page-break-inside: avoid; }
        .empty { color: #68758d; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    @if ($metadata)
        <p class="meta">@foreach ($metadata as $label => $value){{ $label }}՝ {{ $value }}@if (! $loop->last) &nbsp; | &nbsp; @endif @endforeach</p>
    @endif
    <table>
        <thead><tr>@foreach ($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
        <tbody>
            @php($hasRows = false)
            @foreach ($rows as $row)
                @php($hasRows = true)
                <tr>@foreach ($row as $cell)<td>{{ $formatCell($cell) }}</td>@endforeach</tr>
            @endforeach
            @unless ($hasRows)<tr><td class="empty" colspan="{{ max(count($headers), 1) }}">Տվյալներ չկան։</td></tr>@endunless
        </tbody>
    </table>
</body>
</html>
