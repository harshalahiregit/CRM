@extends('pdf.purchase._layout')
@php
    $cur = $pr->currency ?: 'INR';
    $sym = ['INR' => 'Rs.', 'USD' => '$', 'EUR' => 'EUR ', 'GBP' => 'GBP '][$cur] ?? ($cur . ' ');
    $money = fn ($v) => $sym . number_format((float) $v, 2);
@endphp

@section('doc_title', 'PURCHASE REQUISITION')
@section('doc_number', $pr->pr_number)
@section('doc_meta')
    <span class="badge">{{ $pr->status }}</span><br>
    Raised: {{ optional($pr->created_at)->format('d M Y') }}
@endsection

@section('content')
    <h2 style="font-size:15px;margin:0 0 10px;">{{ $pr->title }}</h2>

    <div class="cols">
        <div class="c">
            <div class="box"><div class="lbl">Requested By</div><div class="val">{{ $requester->name ?? '—' }}</div></div>
            <div class="box"><div class="lbl">Department / Cost Centre</div><div class="val">{{ $pr->department ?: '—' }}</div></div>
        </div>
        <div class="c">
            <div class="box"><div class="lbl">Priority</div><div class="val">{{ $pr->priority ?: 'Normal' }}</div></div>
            <div class="box"><div class="lbl">Required By</div><div class="val">{{ $pr->required_by ? \Illuminate\Support\Carbon::parse($pr->required_by)->format('d M Y') : '—' }}</div></div>
        </div>
    </div>

    @if($pr->vendor)
        <div class="box"><div class="lbl">Suggested Vendor</div><div class="val">{{ $pr->vendor->company_name }}</div></div>
    @endif

    <div class="section-h">Requested Items</div>
    <table class="items">
        <thead><tr>
            <th style="width:44%">Description</th>
            <th class="r">Qty</th><th>Unit</th>
            <th class="r">Est. Rate</th><th class="r">Tax %</th><th class="r">Amount</th>
        </tr></thead>
        <tbody>
        @forelse($pr->items as $it)
            <tr>
                <td>{{ $it->description }}@if($it->contract_rate_applied)<br><span class="sku">CONTRACT RATE</span>@endif</td>
                <td class="r">{{ rtrim(rtrim(number_format((float)$it->qty, 2), '0'), '.') }}</td>
                <td>{{ $it->unit ?: '—' }}</td>
                <td class="r">{{ $money($it->rate) }}</td>
                <td class="r">{{ (float) $it->tax }}%</td>
                <td class="r">{{ $money($it->amount) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center;color:#94a3b8;">No line items.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span>{{ $money($pr->subtotal) }}</span></div>
        <div><span>Tax</span><span>{{ $money($pr->tax_total) }}</span></div>
        <div class="grand"><span>Total</span><span>{{ $money($pr->total) }}</span></div>
    </div>

    @if($pr->justification)
        <div class="section-h">Justification</div>
        <div class="terms">{{ $pr->justification }}</div>
    @endif

    <div class="sign">
        <div class="s"><div class="line">Requested By</div>{{ $requester->name ?? '' }}</div>
        <div class="s"><div class="line">Reviewed By</div></div>
        <div class="s"><div class="line">Approved By</div></div>
    </div>
@endsection
