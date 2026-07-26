@extends('emails.layout')

@section('content')
    <h2>Congratulations! Job Offer Letter</h2>
    
    <p>Dear {{ $candidate->name }},</p>
    
    <p>We are delighted to extend an offer of employment to you for the position of <strong>{{ $offer->position }}</strong> at {{ config('app.name') }}.</p>
    
    <div class="info-box">
        <p><strong>Offer Details:</strong></p>
        <p><strong>Position:</strong> {{ $offer->position }}</p>
        <p><strong>Department:</strong> {{ $offer->department }}</p>
        <p><strong>Annual CTC:</strong> ₹{{ number_format($offer->offered_ctc) }}</p>
        <p><strong>Joining Date:</strong> {{ \Carbon\Carbon::parse($offer->joining_date)->format('F j, Y') }}</p>
        @if($offer->probation_period)
            <p><strong>Probation Period:</strong> {{ $offer->probation_period }}</p>
        @endif
        @if($offer->notice_period)
            <p><strong>Notice Period:</strong> {{ $offer->notice_period }}</p>
        @endif
        @if($offer->validity_date)
            <p><strong>Offer Valid Until:</strong> {{ \Carbon\Carbon::parse($offer->validity_date)->format('F j, Y') }}</p>
        @endif
    </div>
    
    <p>We believe you will be a valuable addition to our team and look forward to having you on board.</p>

    @if(!empty($portalLink))
        <p>Please review your offer letter and respond (accept / decline / request clarification) securely here:</p>
        <p style="text-align:center;margin:24px 0;">
            <a href="{{ $portalLink }}" class="button" style="background:#7C3AED;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;">View &amp; Respond to Offer</a>
        </p>
        <p style="font-size:13px;color:#64748b;">Or copy this link: {{ $portalLink }}</p>
    @else
        <p>Please review the offer details and confirm your acceptance at your earliest convenience.</p>
    @endif
    
    <p>Should you have any questions, please don't hesitate to contact us.</p>
    
    <p>Welcome to the team!</p>
    
    <p>Best regards,<br>{{ config('app.name') }} HR Team</p>
@endsection
