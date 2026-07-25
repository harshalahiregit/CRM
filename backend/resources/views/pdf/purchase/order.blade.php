@extends('pdf.purchase._layout')
@php
    $cur = $po->currency ?: 'INR';
    $sym = ['INR' => 'Rs.', 'USD' => '$', 'EUR' => 'EUR ', 'GBP' => 'GBP '][$cur] ?? ($cur . ' ');
    $money = fn ($v) => $sym . number_format((float) $v, 2);
    $v = $po->vendor;
@endphp

@section('doc_title', 'PURCHASE ORDER')
@section('doc_number', $po->po_number)
@section('doc_meta')
    <span class="badge">{{ $po->status }}</span><br>
    Order Date: {{ $po->order_date ? \Illuminate\Support\Carbon::parse($po->order_date)->format('d M Y') : optional($po->created_at)->format('d M Y') }}
    @if($po->expected_delivery_date)<br>Expected: {{ \Illuminate\Support\Carbon::parse($po->expected_delivery_date)->format('d M Y') }}@endif
@endsection

@section('content')
    <div class="cols">
        <div class="c">
            <div class="box">
                <div class="lbl">Vendor</div>
                <div class="val"><strong>{{ $v->company_name ?? '—' }}</strong>
                    @if($v && $v->address)<br>{{ $v->address }}@endif
                    @if($v && ($v->city || $v->state))<br>{{ trim(($v->city ?? '') . ' ' . ($v->state ?? '')) }}@endif
                    @if($v && $v->gst_number)<br>GSTIN: {{ $v->gst_number }}@endif
                    @if($v && $v->email)<br>{{ $v->email }}@endif
                    @if($v && $v->phone)<br>{{ $v->phone }}@endif
                </div>
            </div>
        </div>
        <div class="c">
            <div class="box">
                <div class="lbl">Deliver To</div>
                <div class="val"><strong>{{ $company['name'] }}</strong>
                    @if($company['address'])<br>{{ $company['address'] }}@endif
                    @if($po->department)<br>Dept: {{ $po->department }}@endif
                    @if($company['gst'])<br>GSTIN: {{ $company['gst'] }}@endif
                </div>
            </div>
        </div>
    </div>

    @if($po->title)<h2 style="font-size:14px;margin:2px 0 8px;">{{ $po->title }}</h2>@endif
    @if($po->contract)
        <div class="box"><div class="lbl">Against Contract</div><div class="val">{{ $po->contract->contract_number }} — {{ $po->contract->title }}</div></div>
    @endif

    <div class="section-h">Order Lines</div>
    <table class="items">
        <thead><tr>
            <th style="width:6%">#</th>
            <th style="width:40%">Description</th>
            <th class="r">Qty</th><th>Unit</th>
            <th class="r">Rate</th><th class="r">Tax %</th><th class="r">Amount</th>
        </tr></thead>
        <tbody>
        @forelse($po->items as $i => $it)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $it->description }}@if($it->contract_rate_applied)<br><span class="sku">CONTRACT RATE</span>@endif</td>
                <td class="r">{{ rtrim(rtrim(number_format((float)$it->qty, 2), '0'), '.') }}</td>
                <td>{{ $it->unit ?: '—' }}</td>
                <td class="r">{{ $money($it->rate) }}</td>
                <td class="r">{{ (float) $it->tax }}%</td>
                <td class="r">{{ $money($it->amount) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center;color:#94a3b8;">No line items.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span>{{ $money($po->subtotal) }}</span></div>
        <div><span>Tax</span><span>{{ $money($po->tax_total) }}</span></div>
        <div class="grand"><span>Grand Total</span><span>{{ $money($po->total) }}</span></div>
    </div>

    @if($po->terms)
        <div class="section-h">Terms &amp; Conditions</div>
        <div class="terms">{{ $po->terms }}</div>
    @endif
    @if($po->notes)
        <div class="section-h">Notes</div>
        <div class="terms">{{ $po->notes }}</div>
    @endif

    <div class="sign">
        <div class="s"><div class="line">Prepared By</div></div>
        <div class="s"><div class="line">Authorised Signatory</div>{{ $company['name'] }}</div>
        <div class="s"><div class="line">Vendor Acknowledgement</div></div>
    </div>
@endsection
