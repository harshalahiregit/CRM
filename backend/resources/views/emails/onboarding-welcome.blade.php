@extends('emails.layout')

@section('content')
    <h2>Welcome to {{ config('app.name') }}!</h2>
    
    <p>Dear {{ $onboarding->candidate_name }},</p>
    
    <p>Congratulations and welcome to {{ config('app.name') }}! We are excited to have you join our team.</p>
    
    <div class="info-box">
        <p><strong>Onboarding Information:</strong></p>
        <p><strong>Position:</strong> {{ $onboarding->position }}</p>
        @if($onboarding->department)
            <p><strong>Department:</strong> {{ $onboarding->department }}</p>
        @endif
        <p><strong>Joining Date:</strong> {{ \Carbon\Carbon::parse($onboarding->joining_date)->format('F j, Y') }}</p>
    </div>
    
    <p><strong>What's Next?</strong></p>
    <p>To ensure a smooth onboarding process, please complete the following steps:</p>
    
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
