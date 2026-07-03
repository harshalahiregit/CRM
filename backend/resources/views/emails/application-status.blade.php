@extends('emails.layout')

@section('content')
    <h2>Application Status Update</h2>
    
    <p>Dear {{ $candidate->name }},</p>
    
    <p>We would like to update you on the status of your application for the position of <strong>{{ optional($candidate->jobPosting)->title ?? 'a position' }}</strong>.</p>
    
    <div class="info-box">
        <p><strong>New Status:</strong> {{ $newStage }}</p>
        @if(!empty($message))
            <p><strong>Message:</strong> {{ $message }}</p>
        @endif
    </div>
    
    @if($newStage === 'Screening')
        <p>Your application has moved to the screening stage. Our team is currently reviewing your profile in detail.</p>
    @elseif($newStage === 'Assessment')
        <p>Congratulations! You have been shortlisted for the assessment round. You will receive further instructions shortly.</p>
    @elseif($newStage === 'Interview')
        <p>Great news! You have been selected for an interview. You will receive the interview schedule separately.</p>
    @elseif($newStage === 'Offer')
        <p>Congratulations! We are pleased to extend an offer to you. You will receive the offer letter separately.</p>
    @elseif($newStage === 'Hired')
        <p>Welcome aboard! We are excited to have you join our team. Our onboarding team will contact you shortly.</p>
    @elseif($newStage === 'Rejected')
        <p>Thank you for your interest in {{ config('app.name') }}. Unfortunately, we have decided to move forward with other candidates at this time.</p>
        <p>We encourage you to apply for future openings that match your skills and experience.</p>
    @endif
    
    <p>Thank you for your patience throughout this process.</p>
    
    <p>Best regards,<br>{{ config('app.name') }} HR Team</p>
@endsection
