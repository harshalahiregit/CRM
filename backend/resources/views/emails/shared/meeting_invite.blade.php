{{-- Meeting invitation e-mail (Meeting.docx §1 — "Schedule / Send Invitation").

     Same table-based, inline-CSS shape as emails/shared/kickoff_mom.blade.php so
     it renders in Outlook/Gmail and reads on a phone. The .ics calendar invite is
     attached by MeetingInviteService, so the recipient does not have to retype
     the time into their own diary. --}}
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">

    <tr><td align="center" style="background:linear-gradient(135deg,#6d28d9,#7c3aed);padding:30px 32px;">
      @if($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ $companyName }}" height="34" style="height:34px;display:block;border:0;margin:0 auto 10px;">
      @endif
      <div style="font-size:30px;line-height:1;margin-bottom:8px;">&#128197;</div>
      <div style="color:#ffffff;font-size:21px;font-weight:800;letter-spacing:-.02em;">Meeting Invitation</div>
      <div style="color:rgba(255,255,255,.92);font-size:13px;margin-top:6px;">{{ $meeting->meeting_type_label }}</div>
    </td></tr>

    <tr><td style="padding:30px 32px;">

      <div style="font-size:18px;font-weight:800;color:#111827;margin:0 0 6px;">Dear {{ $recipientName }},</div>
      <p style="font-size:14px;color:#374151;line-height:1.6;margin:0 0 22px;">
        You are invited to the following meeting. A calendar invite is attached to this e-mail.
      </p>

      <div style="font-size:14px;font-weight:800;color:#5b21b6;margin:0 0 10px;">&#128197; Meeting Details</div>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin:0 0 24px;">
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;width:38%;">Meeting</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $meeting->title }}</td>
        </tr>
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;">Reference</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $meeting->meeting_no ?: '#'.$meeting->id }}</td>
        </tr>
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;">When</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $whenLine }}</td>
        </tr>
        @if($meeting->chairperson)
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;">Chairperson</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $meeting->chairperson }}</td>
        </tr>
        @endif
        @if($subjectName)
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;">Third Party Vendor</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $subjectName }}</td>
        </tr>
        @endif
      </table>

      @if($meeting->meeting_link)
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
          <tr><td align="center" style="border-radius:8px;background:#7c3aed;">
            <a href="{{ $meeting->meeting_link }}" style="display:inline-block;padding:12px 26px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;">Join the online meeting</a>
          </td></tr>
        </table>
      @endif

      @if($agendaItems && $agendaItems->count())
        <div style="font-size:14px;font-weight:800;color:#5b21b6;margin:0 0 10px;">&#128203; Agenda</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin:0 0 24px;border-collapse:separate;">
          <tr style="background:#6d28d9;">
            <th align="left" style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;width:34px;">#</th>
            <th align="left" style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;">Item</th>
            <th align="left" style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;width:120px;">Owner</th>
            <th align="left" style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;width:70px;">Time</th>
          </tr>
          @foreach($agendaItems as $i => $item)
          <tr style="background:{{ $i % 2 ? '#f9fafb' : '#ffffff' }};">
            <td valign="top" style="padding:10px 14px;font-size:12.5px;color:#6b7280;">{{ $i + 1 }}</td>
            <td valign="top" style="padding:10px 14px;font-size:12.5px;color:#111827;line-height:1.5;">{{ $item->item }}</td>
            <td valign="top" style="padding:10px 14px;font-size:12.5px;color:#374151;">{{ $item->owner_names ?: '—' }}</td>
            <td valign="top" style="padding:10px 14px;font-size:12.5px;color:#374151;">{{ $item->duration_minutes ? $item->duration_minutes.' min' : '—' }}</td>
          </tr>
          @endforeach
        </table>
      @elseif($meeting->agenda)
        <div style="font-size:14px;font-weight:800;color:#5b21b6;margin:0 0 10px;">&#128203; Agenda</div>
        <p style="font-size:13px;color:#374151;line-height:1.6;margin:0 0 24px;white-space:pre-wrap;">{{ $meeting->agenda }}</p>
      @endif

      <p style="font-size:12.5px;color:#6b7280;line-height:1.6;margin:0;">
        Sangoe users can open the meeting here:
        <a href="{{ $url }}" style="color:#7c3aed;">{{ $url }}</a>
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
