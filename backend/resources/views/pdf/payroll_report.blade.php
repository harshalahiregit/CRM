@php
    /**
     * Generic payroll report PDF (Payroll Phase 6). Renders a titled table from
     * pre-shaped {title, headers, rows} — no calculation happens in the view.
     *
     * @var array $data  ['title' => string, 'headers' => string[], 'rows' => array[]]
     */
    $brand = '#7C3AED';
    $numeric = fn ($v) => is_numeric($v);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $data['title'] }}</title>
    <style>
        @page { margin: 28px 30px 44px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        .head { border-bottom: 3px solid {{ $brand }}; padding-bottom: 8px; margin-bottom: 12px; }
        .title { font-size: 15px; font-weight: bold; color: {{ $brand }}; }
        .muted { color: #6b7280; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: {{ $brand }}; color: #fff; text-align: left; padding: 6px 8px; font-size: 9.5px; }
        td { padding: 5px 8px; border-bottom: 1px solid #eef2f7; font-size: 9.5px; }
        td.num { text-align: right; }
        tr:nth-child(even) td { background: #f9fafb; }
        .footer { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 8px; color: #9ca3af;
                  border-top: 1px solid #e5e7eb; padding-top: 5px; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        <div class="title">{{ $data['title'] }}</div>
        <div class="muted">Generated {{ now()->format('d M Y, H:i') }} &middot; {{ count($data['rows']) }} row(s)</div>
    </div>

    <table>
        <thead>
            <tr>@foreach ($data['headers'] as $h)<th>{{ $h }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @forelse ($data['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td class="{{ $numeric($cell) ? 'num' : '' }}">{{ $numeric($cell) ? number_format((float) $cell, 2) : $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($data['headers']) }}" class="muted">No data for the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">System-generated payroll report &middot; {{ $data['title'] }}</div>
</body>
</html>
