@php
    /**
     * One template for the relieving, experience and salary revision letters.
     *
     * They share a masthead, a facts table and a signature block, and differ only
     * in title and wording — three near-identical blades would have meant the
     * company letterhead drifting apart between them, which is exactly the kind
     * of difference nobody notices until a customer points at two letters.
     *
     * Every value is resolved from records by LetterService. Nothing is
     * hardcoded here, including the company name and brand colour.
     *
     * @var array $letter    ['title', 'ref', 'rows', 'body']
     * @var \App\Models\Hr\HrEmployee $employee
     */
    $company = $tenant->name ?? config('app.name');
    $brand   = $tenant->branding_color ?? '#7C3AED';
    $today   = \Illuminate\Support\Carbon::now()->format('d F Y');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $letter['ref'] }} — {{ $letter['title'] }}</title>
    <style>
        @page { margin: 34px 40px 56px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11.5px; color: #1f2937; line-height: 1.6; }
        .head { border-bottom: 3px solid {{ $brand }}; padding-bottom: 12px; margin-bottom: 22px; }
        .head td { vertical-align: middle; }
        .company { font-size: 19px; font-weight: bold; color: {{ $brand }}; }
        .meta { text-align: right; font-size: 10.5px; color: #6b7280; }
        h1 { font-size: 15px; margin: 18px 0 14px; color: {{ $brand }};
             text-transform: uppercase; letter-spacing: .5px; }
        table.kv { width: 100%; border-collapse: collapse; margin: 10px 0 18px; }
        table.kv td { padding: 7px 9px; border: 1px solid #e5e7eb; }
        table.kv td.k { background: #f9fafb; font-weight: bold; width: 34%; color: #374151; }
        p.body { margin: 0 0 11px; text-align: justify; }
        .sign { width: 100%; margin-top: 40px; }
        .sign td { width: 50%; vertical-align: top; }
        .sig-line { border-top: 1px solid #9ca3af; margin-top: 48px; padding-top: 5px; font-size: 10.5px; }
        .footer { position: fixed; bottom: -34px; left: 0; right: 0; font-size: 9px; color: #9ca3af;
                  border-top: 1px solid #e5e7eb; padding-top: 6px; text-align: center; }
    </style>
</head>
<body>

<table class="head" width="100%">
    <tr>
        <td><span class="company">{{ $company }}</span></td>
        <td class="meta">
            {{ $letter['ref'] }}<br>
            {{ $today }}
        </td>
    </tr>
</table>

<h1>{{ $letter['title'] }}</h1>

@if (!empty($letter['rows']))
    <table class="kv">
        @foreach ($letter['rows'] as $k => $v)
            <tr><td class="k">{{ $k }}</td><td>{{ $v }}</td></tr>
        @endforeach
    </table>
@endif

@foreach ($letter['body'] as $paragraph)
    <p class="body">{{ $paragraph }}</p>
@endforeach

<table class="sign">
    <tr>
        <td>
            <div class="sig-line">For {{ $company }}</div>
        </td>
        <td></td>
    </tr>
</table>

<div class="footer">
    {{ $company }} · {{ $letter['title'] }} · {{ $letter['ref'] }}
</div>

</body>
</html>
