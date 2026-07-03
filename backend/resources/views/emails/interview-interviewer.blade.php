@extends('emails.layout')

@section('content')
    <h2>New Interview Scheduled</h2>
    
    <p>Hello {{ $interview->interviewer_name }},</p>
    
    <p>You have been assigned to conduct an interview for <strong>{{ $candidate->name }}</strong>.</p>
    
    <div class="info-box">
        <p><strong>Interview Details:</strong></p>
        <p><strong>Candidate:</strong> {{ $candidate->name }}</p>
        <p><strong>Position:</strong> {{ $candidate->jobPosting->title ?? 'N/A' }}</p>
        <p><strong>Round:</strong> {{ $interview->round_name }}</p>
        <p><strong>Date & Time:</strong> {{ \Carbon\Carbon::parse($interview->scheduled_at)->format('l, F j, Y \a\t g:i A') }}</p>
        @if($interview->meet_link)
            <p><strong>Meeting Link:</strong> <a href="{{ $interview->meet_link }}" style="color: #7C3AED;">{{ $interview->meet_link }}</a></p>
        @endif
    </div>
    
    <p><strong>Candidate Profile Summary:</strong></p>
    <ul>
        @if($candidate->current_company)
            <li>Current Company: {{ $candidate->current_company }}</li>
        @endif
        @if($candidate->experience_years)
            <li>Experience: {{ $candidate->experience_years }} years</li>
        @endif
        @if($candidate->ai_score)
            <li>AI Score: {{ $candidate->ai_score }}%</li>
        @endif
    </ul>
    
    @if($interview->meet_link)
        <a href="{{ $interview->meet_link }}" class="button">Join Interview</a>
    @endif
    
    <p>Please review the candidate's profile before the interview.</p>
    
    <p>Best regards,<br>{{ config('app.name') }} HR Team</p>
@endsection
