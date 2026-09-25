{{-- The joining link, sent the moment the organiser pastes the real room.

     The invitation (emails/shared/meeting_invite.blade.php) deliberately carries
     NO link: it goes out days early, when the only link in existence is the
     platform's instant-start URL, which opens a different empty room for every
     person who clicks it. This mail is the other half — it exists only once
     there is one room everybody can walk into, so here the link is the point of
     the message and it is the biggest thing on the page.

     Same table-based, inline-CSS shape as the invitation so it survives Outlook
     and reads on a phone. The .ics rides along, this time with the room in its
     LOCATION, which is what makes the calendar's own Join button appear. --}}
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">

    <tr><td align="center" style="background:linear-gradient(135deg,#0f766e,#0d9488);padding:30px 32px;">
      @if($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ $companyName }}" height="34" style="height:34px;display:block;border:0;margin:0 auto 10px;">
      @endif
      <div style="font-size:30px;line-height:1;margin-bottom:8px;">&#127909;</div>
      <div style="color:#ffffff;font-size:21px;font-weight:800;letter-spacing:-.02em;">Joining Link</div>
      <div style="color:rgba(255,255,255,.92);font-size:13px;margin-top:6px;">{{ $platformLabel }}</div>
    </td></tr>

    <tr><td style="padding:30px 32px;">

      <div style="font-size:18px;font-weight:800;color:#111827;margin:0 0 6px;">Dear {{ $recipientName }},</div>
      <p style="font-size:14px;color:#374151;line-height:1.6;margin:0 0 22px;">
        The meeting room is now open. Use the link below to join.
      </p>

      <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 12px;">
        <tr><td align="center" style="border-radius:8px;background:#0d9488;">
          <a href="{{ $joinLink }}" style="display:inline-block;padding:14px 34px;font-size:15px;font-weight:800;color:#ffffff;text-decoration:none;">Join the meeting</a>
        </td></tr>
      </table>

      {{-- The address in full as well as behind the button: it has to be
           copyable, readable out loud on a phone call, and openable on a second
           device where this mail is not. --}}
      <div style="font-size:12.5px;color:#6b7280;margin:0 0 22px;word-break:break-all;">
        <a href="{{ $joinLink }}" style="color:#0f766e;">{{ $joinLink }}</a>
      </div>

      @if($meeting->meeting_passcode)
        <div style="font-size:13px;color:#111827;margin:0 0 22px;">
          Passcode: <strong>{{ $meeting->meeting_passcode }}</strong>
        </div>
      @endif

      <div style="font-size:14px;font-weight:800;color:#0f766e;margin:0 0 10px;">&#128197; Meeting Details</div>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin:0 0 24px;">
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;width:38%;">Meeting</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $meeting->title }}</td>
        </tr>
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;">Reference</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $meeting->meeting_no ?: '#'.$meeting->getKey() }}</td>
        </tr>
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;">When</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $whenLine }}</td>
        </tr>
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;">Mode</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">
            {{ $meeting->location ?: ($meeting->mode ? ucfirst($meeting->mode) : 'Online') }}
          </td>
        </tr>
        @if($meeting->chairperson)
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;">Chairperson</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $meeting->chairperson }}</td>
        </tr>
        @endif
      </table>

      <p style="font-size:12.5px;color:#6b7280;line-height:1.6;margin:0;">
        A calendar invite is attached. The agenda, the register and the minutes are here:
        <a href="{{ $url }}" style="color:#0f766e;">{{ $url }}</a>
      </p>

    </td></tr>

    <tr><td style="padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;">
      <p style="font-size:11.5px;color:#9ca3af;margin:0;">{{ $companyName }} · This is an automated message.</p>
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
