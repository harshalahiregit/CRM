@php
    /**
     * Offer Letter PDF. Every value is resolved from existing records — nothing is
     * hardcoded. Rendered by OfferService when an offer is created or regenerated.
     *
     * @var \App\Models\Hr\HrOffer $offer
     */
    $candidate  = $offer->candidate;
    $tenant     = $tenant     ?? null;
    $onboarding = $onboarding ?? null;

    $company  = $tenant->name ?? config('app.name');
    $brand    = $tenant->branding_color ?? '#7C3AED';
    $logo     = $tenant->logo_url ?? null;
    $offerNo  = 'OFR-' . str_pad((string) $offer->id, 4, '0', STR_PAD_LEFT);
    $manager  = $onboarding->reporting_manager_name ?? null;
    $location = $offer->location ?? ($onboarding->department ?? null);
    $money    = fn ($v) => $v === null ? '—' : '₹' . number_format((float) $v, 2);
    $date     = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d F Y') : '—';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $offerNo }} — Offer Letter</title>
    <style>
        @page { margin: 34px 40px 56px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11.5px; color: #1f2937; line-height: 1.55; }
        .head { border-bottom: 3px solid {{ $brand }}; padding-bottom: 12px; margin-bottom: 20px; }
        .head td { vertical-align: middle; }
        .company { font-size: 19px; font-weight: bold; color: {{ $brand }}; }
        .muted { color: #6b7280; }
        .meta { text-align: right; font-size: 10.5px; }
        h1 { font-size: 15px; margin: 20px 0 12px; color: {{ $brand }}; text-transform: uppercase; letter-spacing: .5px; }
        table.kv { width: 100%; border-collapse: collapse; margin: 10px 0 16px; }
        table.kv td { padding: 7px 9px; border: 1px solid #e5e7eb; }
        table.kv td.k { background: #f9fafb; font-weight: bold; width: 32%; color: #374151; }
        ol { padding-left: 16px; margin: 6px 0; }
        ol li { margin-bottom: 5px; }
        .sign { width: 100%; margin-top: 34px; }
        .sign td { width: 50%; vertical-align: top; padding-top: 6px; }
        .sig-line { border-top: 1px solid #9ca3af; margin-top: 46px; padding-top: 5px; font-size: 10.5px; }
        .footer { position: fixed; bottom: -34px; left: 0; right: 0; font-size: 9px; color: #9ca3af;
                  border-top: 1px solid #e5e7eb; padding-top: 6px; text-align: center; }
        .accepted { margin-top: 14px; padding: 9px 11px; border: 1px solid #a7f3d0; background: #ecfdf5; color: #065f46; font-size: 10.5px; }
    </style>
</head>
<body>

<table class="head" width="100%">
    <tr>
        <td>
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $company }}" style="max-height:44px;">
            @endif
            <div class="company">{{ $company }}</div>
        </td>
        <td class="meta">
            <div><strong>Offer No.</strong> {{ $offerNo }}</div>
            <div class="muted">Date: {{ $date($offer->generated_at ?? $offer->created_at) }}</div>
            @if ($offer->validity_date)
                <div class="muted">Valid till: {{ $date($offer->validity_date) }}</div>
            @endif
        </td>
    </tr>
</table>

<p>Dear <strong>{{ $candidate->name ?? $offer->candidate_name }}</strong>,</p>

<p>
    We are pleased to offer you the position of <strong>{{ $offer->position }}</strong> at
    <strong>{{ $company }}</strong>. This letter confirms the terms of your appointment as set out below.
</p>

<h1>Terms of Appointment</h1>
<table class="kv">
    <tr><td class="k">Candidate Name</td><td>{{ $candidate->name ?? '—' }}</td></tr>
    <tr><td class="k">Position</td><td>{{ $offer->position ?? '—' }}</td></tr>
    <tr><td class="k">Department</td><td>{{ $offer->department ?? '—' }}</td></tr>
    <tr><td class="k">Annual CTC</td><td>{{ $money($offer->offered_ctc) }}</td></tr>
    <tr><td class="k">Date of Joining</td><td>{{ $date($offer->joining_date) }}</td></tr>
    @if ($manager)
        <tr><td class="k">Reporting Manager</td><td>{{ $manager }}</td></tr>
    @endif
    @if ($location)
        <tr><td class="k">Location</td><td>{{ $location }}</td></tr>
    @endif
    @if ($offer->probation_period)
        <tr><td class="k">Probation Period</td><td>{{ $offer->probation_period }}</td></tr>
    @endif
    @if ($offer->notice_period)
        <tr><td class="k">Notice Period</td><td>{{ $offer->notice_period }}</td></tr>
    @endif
</table>

<h1>Terms &amp; Conditions</h1>
<ol>
    <li>Your appointment is subject to verification of the documents and information submitted by you.</li>
    <li>You are required to join on or before <strong>{{ $date($offer->joining_date) }}</strong>. Failure to do so may render this offer void.</li>
    @if ($offer->probation_period)
        <li>You will be on probation for <strong>{{ $offer->probation_period }}</strong> from your date of joining.</li>
    @endif
    @if ($offer->notice_period)
        <li>Either party may terminate this employment by serving a notice period of <strong>{{ $offer->notice_period }}</strong>.</li>
    @endif
    <li>You shall maintain strict confidentiality of all proprietary and business information of the Company.</li>
    <li>You shall be governed by the Company's policies, as amended from time to time.</li>
    @if ($offer->validity_date)
        <li>This offer remains valid until <strong>{{ $date($offer->validity_date) }}</strong>.</li>
    @endif
</ol>

<p>
    We look forward to welcoming you to {{ $company }} and are confident that you will find your
    association with us both rewarding and fulfilling.
</p>

<table class="sign">
    <tr>
        <td>
            <div class="sig-line">
                Authorised Signatory<br>
                <span class="muted">For {{ $company }}</span>
            </div>
        </td>
        <td>
            @if ($offer->accepted_at)
                <div class="sig-line">
                    @if ($offer->accepted_signature)
                        <img src="{{ $offer->accepted_signature }}" alt="Signature" style="max-height:40px;"><br>
                    @endif
                    {{ $offer->accepted_name ?? ($candidate->name ?? '') }}<br>
                    <span class="muted">Accepted on {{ $date($offer->accepted_at) }}</span>
                </div>
            @else
                <div class="sig-line">
                    Candidate Signature<br>
                    <span class="muted">{{ $candidate->name ?? '' }}</span>
                </div>
            @endif
        </td>
    </tr>
</table>

@if ($offer->accepted_at)
    <div class="accepted">
        <strong>Offer accepted</strong> on {{ $date($offer->accepted_at) }}
        @if ($offer->accepted_ip) &middot; IP {{ $offer->accepted_ip }} @endif
        @if ($offer->accepted_device) &middot; {{ $offer->accepted_device }} @endif
    </div>
@endif

<div class="footer">
    {{ $company }} &middot; {{ $offerNo }} &middot; This is a system-generated offer letter.
</div>

</body>
</html>
