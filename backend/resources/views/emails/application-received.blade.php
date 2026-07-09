@extends('emails.layout')

@section('content')
    <h2>Application Received</h2>
    
    <p>Dear {{ $candidate->name }},</p>
    
    <p>Thank you for applying for the position of <strong>{{ $candidate->jobPosting->title ?? 'a position' }}</strong> at {{ config('app.name') }}.</p>
    
    <p>We have successfully received your application and our recruitment team is currently reviewing it.</p>
    
    <div class="info-box">
        <p><strong>Application Summary:</strong></p>
        <p><strong>Position:</strong> {{ $candidate->jobPosting->title ?? 'N/A' }}</p>
        <p><strong>Application Date:</strong> {{ \Carbon\Carbon::parse($candidate->created_at)->format('F j, Y') }}</p>
        <p><strong>Current Stage:</strong> {{ $candidate->stage }}</p>
        @if($candidate->ai_score)
            <p><strong>Profile Match Score:</strong> {{ $candidate->ai_score }}%</p>
        @endif
    </div>
    
    <p><strong>What Happens Next?</strong></p>
    <ul>
        <li>Our team will review your application within 3-5 business days</li>
        <li>If shortlisted, we'll contact you for the next round of interviews</li>
        <li>You'll receive email updates about your application status</li>
    </ul>
    
    <p>We appreciate your interest in joining our team. Should you have any questions, feel free to reach out to us.</p>
    
    <p>Best regards,<br>{{ config('app.name') }} HR Team</p>
@endsection
