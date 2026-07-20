<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 26px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; }
        .head { display: table; width: 100%; margin-bottom: 18px; }
        .head .co { display: table-cell; width: 55%; vertical-align: top; }
        .head .doc { display: table-cell; width: 45%; text-align: right; vertical-align: top; }
        .co .name { font-size: 17px; font-weight: bold; color: #4c1d95; }
        .co .muted, .doc .muted { color: #64748b; font-size: 10.5px; line-height: 1.5; }
        .doc .title { font-size: 20px; font-weight: bold; letter-spacing: 1px; color: #1e293b; }
        .doc .num { font-size: 12px; font-weight: bold; color: #7c3aed; margin-top: 2px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9.5px; font-weight: bold; background: #ede9fe; color: #5b21b6; }
        .cols { display: table; width: 100%; margin: 4px 0 14px; }
        .cols .c { display: table-cell; width: 50%; vertical-align: top; padding-right: 12px; }
        .box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; margin-bottom: 6px; }
        .box .lbl { font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #94a3b8; margin-bottom: 3px; }
        .box .val { font-size: 12px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th { background: #4c1d95; color: #fff; text-align: left; padding: 7px 8px; font-size: 9.5px; text-transform: uppercase; }
        table.items th.r, table.items td.r { text-align: right; }
        table.items td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; font-size: 11px; vertical-align: top; }
        table.items tr:nth-child(even) td { background: #faf9ff; }
        .sku { color: #7c3aed; font-size: 9px; font-weight: bold; }
        .totals { width: 250px; margin-left: auto; margin-top: 12px; }
        .totals div { display: table; width: 100%; padding: 3px 0; }
        .totals span:first-child { display: table-cell; color: #64748b; }
        .totals span:last-child { display: table-cell; text-align: right; font-weight: bold; }
        .totals .grand { font-size: 14px; border-top: 2px solid #4c1d95; margin-top: 4px; padding-top: 6px; color: #4c1d95; }
        .section-h { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #7c3aed; font-weight: bold; margin: 16px 0 4px; border-bottom: 1px solid #ede9fe; padding-bottom: 3px; }
        .terms { font-size: 10.5px; color: #475569; line-height: 1.6; white-space: pre-line; }
        .sign { display: table; width: 100%; margin-top: 40px; }
        .sign .s { display: table-cell; width: 33%; text-align: center; vertical-align: bottom; font-size: 10px; color: #64748b; }
        .sign .line { border-top: 1px solid #94a3b8; margin: 0 12px 4px; padding-top: 4px; }
        .footer { position: fixed; bottom: -8px; left: 0; right: 0; text-align: center; font-size: 9px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="co">
            <div class="name">{{ $company['name'] }}</div>
            @if($company['address'])<div class="muted">{{ $company['address'] }}</div>@endif
            @if($company['gst'])<div class="muted">GSTIN: {{ $company['gst'] }}</div>@endif
            @if($company['email'])<div class="muted">{{ $company['email'] }}</div>@endif
        </div>
        <div class="doc">
            <div class="title">@yield('doc_title')</div>
            <div class="num">@yield('doc_number')</div>
            <div class="muted" style="margin-top:6px;">@yield('doc_meta')</div>
        </div>
    </div>

    @yield('content')

    <div class="footer">{{ $company['name'] }} · @yield('doc_title') @yield('doc_number') · Generated {{ now()->format('d M Y, H:i') }}</div>
</body>
</html>
