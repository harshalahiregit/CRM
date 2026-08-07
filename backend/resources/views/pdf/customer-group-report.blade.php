@php
    $inr = fn ($v) => '₹' . number_format((float) $v, 2);
    $t   = $report['totals'];
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px 22px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2937; }
        h1 { font-size: 16px; margin: 0 0 2px; color: #4c1d95; }
        h2 { font-size: 11px; margin: 14px 0 5px; color: #4c1d95; border-bottom: 1px solid #ddd6fe; padding-bottom: 3px; }
        .meta { color: #6b7280; font-size: 8px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e5e7eb; padding: 3px 5px; }
        th { background: #f5f3ff; text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: .03em; }
        td.n, th.n { text-align: right; }
        tr.total td { background: #f9fafb; font-weight: bold; }
        .tiles td { border: none; padding: 0 6px 0 0; width: 20%; }
        .tile { border: 1px solid #e5e7eb; border-radius: 4px; padding: 6px 8px; }
        .tile .k { color: #6b7280; font-size: 7px; text-transform: uppercase; }
        .tile .v { font-size: 12px; font-weight: bold; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>

<h1>{{ $report['group']['name'] }} — Group Report</h1>
<div class="meta">
    {{ $t['customer_count'] }} customer(s) ·
    @if($report['range']['from'] || $report['range']['to'])
        Invoices {{ $report['range']['from'] ?: 'start' }} to {{ $report['range']['to'] ?: 'today' }} ·
    @else
        All dates ·
    @endif
    Ageing as of {{ $report['as_of'] }}
</div>

{{-- Summary tiles --}}
<table class="tiles"><tr>
    <td><div class="tile"><div class="k">Billed</div><div class="v">{{ $inr($t['total_billed']) }}</div></div></td>
    <td><div class="tile"><div class="k">Paid</div><div class="v">{{ $inr($t['total_paid']) }}</div></div></td>
    <td><div class="tile"><div class="k">Outstanding</div><div class="v">{{ $inr($t['outstanding']) }}</div></div></td>
    <td><div class="tile"><div class="k">GST Unpaid</div><div class="v">{{ $inr($t['gst_unpaid']) }}</div></div></td>
    <td><div class="tile"><div class="k">Credit</div><div class="v">{{ $inr($t['available_credit']) }}</div></div></td>
</tr></table>

{{-- Section 1: financials --}}
<h2>Financials by customer</h2>
<table>
    <thead><tr>
        <th>Customer</th><th class="n">Inv</th><th class="n">Billed</th>
        <th class="n">Paid</th><th class="n">Outstanding</th><th class="n">Credit</th>
    </tr></thead>
    <tbody>
    @forelse($report['clients'] as $c)
        <tr>
            <td>{{ $c['company'] }}@if(! $c['active']) <span style="color:#9ca3af">(inactive)</span>@endif</td>
            <td class="n">{{ $c['invoice_count'] }}</td>
            <td class="n">{{ $inr($c['total_billed']) }}</td>
            <td class="n">{{ $inr($c['total_paid']) }}</td>
            <td class="n">{{ $inr($c['outstanding']) }}</td>
            <td class="n">{{ $inr($c['available_credit']) }}</td>
        </tr>
    @empty
        <tr><td colspan="6" style="color:#6b7280">No customers in this group.</td></tr>
    @endforelse
        <tr class="total">
            <td>TOTAL</td>
            <td class="n">{{ $t['invoice_count'] }}</td>
            <td class="n">{{ $inr($t['total_billed']) }}</td>
            <td class="n">{{ $inr($t['total_paid']) }}</td>
            <td class="n">{{ $inr($t['outstanding']) }}</td>
            <td class="n">{{ $inr($t['available_credit']) }}</td>
        </tr>
    </tbody>
</table>

{{-- Section 2: tax --}}
<h2>GST &amp; TDS</h2>
<table>
    <thead><tr>
        <th>Customer</th><th class="n">GST Total</th><th class="n">GST Paid</th>
        <th class="n">GST Unpaid</th><th class="n">TDS Deducted</th>
    </tr></thead>
    <tbody>
    @foreach($report['clients'] as $c)
        <tr>
            <td>{{ $c['company'] }}</td>
            <td class="n">{{ $inr($c['gst_total']) }}</td>
            <td class="n">{{ $inr($c['gst_paid']) }}</td>
            <td class="n">{{ $inr($c['gst_unpaid']) }}</td>
            <td class="n">{{ $inr($c['tds_deducted']) }}</td>
        </tr>
    @endforeach
        <tr class="total">
            <td>TOTAL</td>
            <td class="n">{{ $inr($t['gst_total']) }}</td>
            <td class="n">{{ $inr($t['gst_paid']) }}</td>
            <td class="n">{{ $inr($t['gst_unpaid']) }}</td>
            <td class="n">{{ $inr($t['tds_deducted']) }}</td>
        </tr>
    </tbody>
</table>

{{-- Section 3: ageing --}}
<div class="page-break"></div>
<h2>Outstanding ageing (as of {{ $report['as_of'] }})</h2>
<table>
    <thead><tr>
        <th>Customer</th><th class="n">Not due</th><th class="n">1–30d</th>
        <th class="n">31–60d</th><th class="n">61–90d</th><th class="n">90d+</th><th class="n">Total</th>
    </tr></thead>
    <tbody>
    @foreach($report['clients'] as $c)
        <tr>
            <td>{{ $c['company'] }}</td>
            <td class="n">{{ $inr($c['ageing']['current']) }}</td>
            <td class="n">{{ $inr($c['ageing']['d30']) }}</td>
            <td class="n">{{ $inr($c['ageing']['d60']) }}</td>
            <td class="n">{{ $inr($c['ageing']['d90']) }}</td>
            <td class="n">{{ $inr($c['ageing']['d90plus']) }}</td>
            <td class="n">{{ $inr($c['outstanding']) }}</td>
        </tr>
    @endforeach
        <tr class="total">
            <td>TOTAL</td>
            <td class="n">{{ $inr($t['ageing']['current']) }}</td>
            <td class="n">{{ $inr($t['ageing']['d30']) }}</td>
            <td class="n">{{ $inr($t['ageing']['d60']) }}</td>
            <td class="n">{{ $inr($t['ageing']['d90']) }}</td>
            <td class="n">{{ $inr($t['ageing']['d90plus']) }}</td>
            <td class="n">{{ $inr($t['outstanding']) }}</td>
        </tr>
    </tbody>
</table>

{{-- Section 4: activity --}}
<h2>Activity</h2>
<table>
    <thead><tr>
        <th>Customer</th><th class="n">Proposals</th><th class="n">Estimates</th>
        <th class="n">Invoices</th><th class="n">Contracts</th>
        <th class="n">Tickets</th><th class="n">Open</th><th class="n">Projects</th>
    </tr></thead>
    <tbody>
    @foreach($report['clients'] as $c)
        <tr>
            <td>{{ $c['company'] }}</td>
            @foreach(['proposals','estimates','invoices','contracts','tickets','open_tickets','projects'] as $k)
                <td class="n">{{ $c['activity'][$k] }}</td>
            @endforeach
        </tr>
    @endforeach
        <tr class="total">
            <td>TOTAL</td>
            @foreach(['proposals','estimates','invoices','contracts','tickets','open_tickets','projects'] as $k)
                <td class="n">{{ $t['activity'][$k] }}</td>
            @endforeach
        </tr>
    </tbody>
</table>

{{-- Section 5: all-groups comparison (only on the all-customers report) --}}
@if($comparison)
    <div class="page-break"></div>
    <h2>All groups compared</h2>
    <table>
        <thead><tr>
            <th>Group</th><th class="n">Customers</th><th class="n">Invoices</th>
            <th class="n">Billed</th><th class="n">Paid</th><th class="n">Outstanding</th><th class="n">GST Unpaid</th>
        </tr></thead>
        <tbody>
        @foreach(array_merge($comparison['groups']->all() ?? [], [$comparison['ungrouped']]) as $g)
            <tr>
                <td>{{ $g['name'] }}</td>
                <td class="n">{{ $g['customer_count'] }}</td>
                <td class="n">{{ $g['invoice_count'] }}</td>
                <td class="n">{{ $inr($g['total_billed']) }}</td>
                <td class="n">{{ $inr($g['total_paid']) }}</td>
                <td class="n">{{ $inr($g['outstanding']) }}</td>
                <td class="n">{{ $inr($g['gst_unpaid']) }}</td>
            </tr>
        @endforeach
            <tr class="total">
                <td>GRAND TOTAL</td>
                <td class="n">{{ $comparison['grand']['customer_count'] }}</td>
                <td class="n">{{ $comparison['grand']['invoice_count'] }}</td>
                <td class="n">{{ $inr($comparison['grand']['total_billed']) }}</td>
                <td class="n">{{ $inr($comparison['grand']['total_paid']) }}</td>
                <td class="n">{{ $inr($comparison['grand']['outstanding']) }}</td>
                <td class="n">{{ $inr($comparison['grand']['gst_unpaid']) }}</td>
            </tr>
        </tbody>
    </table>
@endif

</body>
</html>
