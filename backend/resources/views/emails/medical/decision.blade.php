@extends('emails.layout')

{{-- The quality team's verdict, sent to the vendor. A held certificate that
     nobody reads is a worker who never reaches site, so the reason and the next
     step are both in the body rather than behind a login. --}}
@section('content')
    <h2 style="margin-top:0;">{{ $title }}</h2>

    <p>
        The medical certificate for <strong>{{ $worker ?: 'a worker' }}</strong>
        (certificate <strong>{{ $medical->certificate_no }}</strong>) has been
        <strong>{{ strtolower($decision) }}</strong>.
    </p>

    @if($reason || $note)
        <table style="width:100%; border-collapse:collapse; margin:16px 0;">
            @if($reason)
                <tr>
                    <td style="padding:6px 10px; color:#6b7280; width:90px;">Reason</td>
                    <td style="padding:6px 10px;"><strong>{{ str_replace('_', ' ', $reason) }}</strong></td>
                </tr>
            @endif
            @if($note)
                <tr>
                    <td style="padding:6px 10px; color:#6b7280;">Note</td>
                    <td style="padding:6px 10px;">{{ $note }}</td>
                </tr>
            @endif
        </table>
    @endif

    @if($decision === 'Hold')
        <p>Please correct what is noted above and resubmit the certificate from your portal. You can reply on the certificate's timeline if anything is unclear.</p>
    @elseif($decision === 'Rejected')
        <p>This certificate cannot be accepted. A fresh medical examination is required before this worker can be cleared for site.</p>
    @else
        <p>No further action is needed — this worker's medical clearance is now complete.</p>
    @endif

    <p style="margin-top:24px;">
        <a href="{{ $portalUrl }}" style="background:#7C3AED; color:#fff; padding:10px 18px; border-radius:6px; text-decoration:none; display:inline-block;">
            Open the portal
        </a>
    </p>
@endsection
