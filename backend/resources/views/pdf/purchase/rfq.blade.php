@extends('pdf.purchase._layout')

@section('doc_title', 'REQUEST FOR QUOTATION')
@section('doc_number', $rfq->rfq_number)
@section('doc_meta')
    Issued: {{ optional($rfq->created_at)->format('d M Y') }}<br>
    @if($rfq->closes_at)<strong>Responses due: {{ \Illuminate\Support\Carbon::parse($rfq->closes_at)->format('d M Y') }}</strong>@endif
@endsection

@section('content')
    <h2 style="font-size:15px;margin:0 0 10px;">{{ $rfq->title }}</h2>

    <div class="box">
        <div class="lbl">Invitation to Quote</div>
        <div class="val">You are invited to submit your best pricing for the items listed below. Please return your quotation{{ $rfq->closes_at ? ' by ' . \Illuminate\Support\Carbon::parse($rfq->closes_at)->format('d M Y') : '' }}, referencing RFQ <strong>{{ $rfq->rfq_number }}</strong>.</div>
    </div>

    @if($rfq->rfqVendors && $rfq->rfqVendors->count())
        <div class="cols">
            @foreach($rfq->rfqVendors->take(2) as $rv)
                <div class="c"><div class="box"><div class="lbl">Addressed To</div><div class="val">{{ optional($rv->vendor)->company_name ?? '—' }}</div></div></div>
            @endforeach
        </div>
    @endif

    <div class="section-h">Items for Quotation</div>
    <table class="items">
        <thead><tr>
            <th style="width:6%">#</th>
            <th style="width:50%">Description</th>
            <th class="r">Qty</th><th>Unit</th>
            <th class="r" style="width:22%">Your Unit Rate</th>
        </tr></thead>
        <tbody>
        @forelse($rfq->items as $i => $it)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $it->description }}</td>
                <td class="r">{{ rtrim(rtrim(number_format((float)$it->qty, 2), '0'), '.') }}</td>
                <td>{{ $it->unit ?: '—' }}</td>
                <td class="r" style="color:#cbd5e1;">__________</td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center;color:#94a3b8;">No line items.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="section-h">How to Respond</div>
    <div class="terms">• Quote in {{ $rfq->currency ?: 'INR' }}, exclusive of taxes (state tax % separately).
• Mention delivery lead time and validity of your quote.
• Include applicable HSN/SAC codes and payment terms.@if($rfq->notes)

{{ $rfq->notes }}@endif</div>

    <div class="sign">
        <div class="s"><div class="line">Vendor Signature &amp; Seal</div></div>
        <div class="s"><div class="line">Quote Valid Until</div></div>
        <div class="s"><div class="line">Date</div></div>
    </div>
@endsection
