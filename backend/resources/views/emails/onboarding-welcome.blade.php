@extends('emails.layout')

@section('content')
    <h2>🎉 Congratulations, {{ $onboarding->candidate_name }}!</h2>

    <p>We are delighted to inform you that you have been <strong>selected</strong> for the position of
        {{-- A ternary, not @if/@endif: Blade matches directives with `\B@`, so an
             `@endif` written immediately after a word character ("department@endif")
             is NOT recognised as a directive. The @if was left unclosed and every
             render of this template threw "unexpected end of file", which meant the
             onboarding welcome email never sent and POST /hr/onboarding returned
             500 for any candidate with an email address. --}}
        <strong>{{ $onboarding->position }}</strong>{{ $onboarding->department ? ' in the '.$onboarding->department.' department' : '' }} at {{ config('app.name') }}.</p>

    @if(!empty($portalLink))
        <p><strong>Next step — complete your onboarding.</strong> Please use your secure link below to submit your
            details and documents so our HR team can verify them:</p>
        <p style="text-align:center;margin:24px 0;">
            <a href="{{ $portalLink }}" class="button" style="background:#7C3AED;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;">Complete Onboarding</a>
        </p>
        <p style="font-size:13px;color:#64748b;">Or copy this link: {{ $portalLink }}</p>
    @endif

    <div class="info-box">
        <p><strong>Onboarding Information:</strong></p>
        <p><strong>Position:</strong> {{ $onboarding->position }}</p>
        @if($onboarding->department)
            <p><strong>Department:</strong> {{ $onboarding->department }}</p>
        @endif
        @if($onboarding->joining_date)
            <p><strong>Tentative Joining Date:</strong> {{ \Carbon\Carbon::parse($onboarding->joining_date)->format('F j, Y') }}</p>
        @endif
    </div>

    <p><strong>What you'll need to submit:</strong></p>
    
    <ul>
        <li>Document Verification - Upload required documents</li>
        <li>Confirm your joining date</li>
        <li>Complete employee information form</li>
        <li>Review company policies</li>
    </ul>
    
    <p>Our HR team will guide you through each step of the process. If you have any documents ready, please bring them on your first day:</p>
    
    <ul>
        <li>Signed Offer Letter</li>
        <li>ID Proof (Aadhaar/PAN)</li>
        <li>Educational Certificates</li>
        <li>Previous Employment Documents</li>
        <li>Bank Account Details</li>
        <li>Passport Size Photos</li>
    </ul>
    
    <p>We look forward to seeing you soon!</p>
    
    <p>Best regards,<br>{{ config('app.name') }} HR Team</p>
@endsection
