@extends('emails.layout')

@section('content')
    <h2>Interview Scheduled</h2>
    
    <p>Dear {{ $candidate->name }},</p>
    
    <p>We are pleased to inform you that your interview has been scheduled for the <strong>{{ $interview->round_name }}</strong> position.</p>
    
    <div class="info-box">
        <p><strong>Interview Details:</strong></p>
        <p><strong>Round:</strong> {{ $interview->round_name }}</p>
        <p><strong>Date & Time:</strong> {{ \Carbon\Carbon::parse($interview->scheduled_at)->format('l, F j, Y \a\t g:i A') }}</p>
        @if($interview->interviewer_name)
            <p><strong>Interviewer:</strong> {{ $interview->interviewer_name }}</p>
        @endif
        @if($interview->meet_link)
            <p><strong>Meeting Link:</strong> <a href="{{ $interview->meet_link }}" style="color: #7C3AED;">{{ $interview->meet_link }}</a></p>
        @endif
    </div>
    
    <p>Please ensure you join the meeting 5 minutes before the scheduled time.</p>
    
    @if($interview->meet_link)
        <a href="{{ $interview->meet_link }}" class="button">Join Interview</a>
    @endif
    
    <p>We look forward to speaking with you!</p>
    
    <p>Best regards,<br>{{ config('app.name') }} HR Team</p>
@endsection
