<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; }
        .header { display: table; width: 100%; margin-bottom: 24px; }
        .header .logo { display: table-cell; width: 50%; vertical-align: top; }
        .header .logo img { max-height: 60px; }
        .header .meta { display: table-cell; width: 50%; text-align: right; vertical-align: top; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #64748b; }
        .box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 16px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.items th { background: #f1f5f9; text-align: left; padding: 8px; font-size: 10px; text-transform: uppercase; }
        table.items td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
        .totals { width: 260px; margin-left: auto; margin-top: 16px; }
        .totals div { display: table; width: 100%; padding: 4px 0; }
        .totals span:first-child { display: table-cell; }
        .totals span:last-child { display: table-cell; text-align: right; font-weight: bold; }
        .grand { font-size: 14px; border-top: 1px solid #1e293b; padding-top: 8px; }
        .footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 10px; color: #64748b; }
        .stamp { max-height: 80px; margin-top: 16px; }
        .cover { text-align: center; page-break-after: always; padding-top: 40px; }
        .cover img { max-width: 100%; max-height: 360px; border-radius: 8px; margin-bottom: 28px; }
        .cover .ctitle { text-transform: uppercase; letter-spacing: 3px; color: #7C3AED; font-size: 12px; font-weight: bold; margin-bottom: 10px; }
        .cover .cheading { font-size: 30px; font-weight: 800; color: #1a1535; line-height: 1.2; margin: 0; }
        .cover .cmeta { color: #64748b; font-size: 13px; margin-top: 18px; }
    </style>
</head>
<body>
    @if(!empty($proposal->cover['enabled']))
        @php($cv = $proposal->cover)
        <div class="cover">
            @if(!empty($cv['image']))<img src="{{ $cv['image'] }}">@endif
            @if(!empty($cv['title']))<div class="ctitle">{{ $cv['title'] }}</div>@endif
            <h1 class="cheading">{{ $cv['heading'] ?: $proposal->subject }}</h1>
            @if($proposal->proposal_to)<p class="cmeta">Prepared for <strong>{{ $proposal->proposal_to }}</strong></p>@endif
            <p class="cmeta">{{ optional($proposal->date)->format('d M Y') }}</p>
        </div>
    @endif

    <div class="header">
        <div class="logo">
            @if($proposal->company_logo_url)
                <img src="{{ $proposal->company_logo_url }}">
            @else
                <h1>PROPOSAL</h1>
            @endif
        </div>
        <div class="meta">
            <p class="muted">{{ $proposal->pdf_header ?? '' }}</p>
        </div>
    </div>

    <h1>{{ $proposal->subject }}</h1>
    <p class="muted">Reference: PROP-{{ str_pad($proposal->id, 3, '0', STR_PAD_LEFT) }} &middot; Date: {{ optional($proposal->date)->format('d M Y') }}</p>

    <div class="box">
        <strong>Prepared For</strong><br>
        {{ $proposal->proposal_to ?? '—' }}<br>
        @if($proposal->address){{ $proposal->address }}<br>@endif
        @if($proposal->email){{ $proposal->email }}<br>@endif
        @if($proposal->phone){{ $proposal->phone }}@endif
    </div>

    @if($proposal->relationLoaded('pages') && $proposal->pages->isNotEmpty())
        @foreach($proposal->pages as $pg)
            <div class="box" style="margin-top: 16px; page-break-inside: avoid;">
                <strong>{{ $pg->title }}</strong><br>
                {!! $pg->content !!}
            </div>
        @endforeach
    @endif

    <table class="items">
        <thead>
            <tr><th>Item</th><th>HSN/SAC</th><th>Qty</th><th>Rate</th><th>Tax</th><th>Total</th></tr>
        </thead>
        <tbody>
            @foreach($proposal->lineItems as $item)
                <tr>
                    <td>{{ $item->item_name }}</td>
                    <td>{{ $item->hsn_sac_code ?: '—' }}</td>
                    <td>{{ $item->qty }}</td>
                    <td>{{ number_format($item->rate, 2) }}</td>
                    <td>{{ $item->tax }}%</td>
                    <td>{{ number_format($item->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span>{{ number_format($proposal->subtotal, 2) }}</span></div>
        @if($proposal->discount_amount > 0)
            <div><span>Discount</span><span>-{{ number_format($proposal->discount_amount, 2) }}</span></div>
        @endif
@php($taxRows = $proposal->taxBreakdown())
        @if(count($taxRows))
            @foreach($taxRows as $t)
                <div><span>{{ $t['name'] }} ({{ rtrim(rtrim(number_format($t['rate'], 2, '.', ''), '0'), '.') }}%)</span><span>{{ number_format($t['amount'], 2) }}</span></div>
            @endforeach
        @elseif($proposal->supply_type === 'intra')
            <div><span>CGST</span><span>{{ number_format($proposal->tax_total / 2, 2) }}</span></div>
            <div><span>SGST</span><span>{{ number_format($proposal->tax_total / 2, 2) }}</span></div>
        @elseif($proposal->supply_type === 'inter')
            <div><span>IGST</span><span>{{ number_format($proposal->tax_total, 2) }}</span></div>
        @else
            <div><span>Tax</span><span>{{ number_format($proposal->tax_total, 2) }}</span></div>
        @endif
        <div class="grand"><span>Total</span><span>{{ $proposal->currency }} {{ number_format($proposal->total, 2) }}</span></div>
    </div>

    @if($proposal->notes)
        <div class="box" style="margin-top: 24px;">
            <strong>Notes</strong><br>{{ $proposal->notes }}
        </div>
    @endif

    @if($proposal->terms)
        {{-- Terms is sanitized rich text (HtmlSanitizer) — safe to render as HTML. --}}
        <div class="box" style="margin-top: 16px;">
            <strong>Terms &amp; Conditions</strong>
            <div>{!! $proposal->terms !!}</div>
        </div>
    @endif

    @if($proposal->company_stamp_url)
        <img src="{{ $proposal->company_stamp_url }}" class="stamp">
    @endif

    @if($proposal->pdf_footer)
        <div class="footer">{{ $proposal->pdf_footer }}</div>
    @endif
</body>
</html>
