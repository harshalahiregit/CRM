{{-- Kickoff MOM e-mail — sent when minutes are published for acknowledgement.

     Table-based + inline CSS, matching emails/tpv/activation.blade.php, so it
     renders in Outlook/Gmail and reads on a phone. No <style> block: Gmail strips
     it, and the acknowledgement CTA is the whole point of this message.

     The acknowledge button carries the 48-char bearer token. Before this template
     the mail only said "log into the Vendor Portal" and never delivered the token
     at all, so the public one-click ack flow was unreachable from e-mail. --}}
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">

    {{-- Header --}}
    <tr><td align="center" style="background:linear-gradient(135deg,#0d9488,#10b981);padding:30px 32px;">
      @if($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ $companyName }}" height="34" style="height:34px;display:block;border:0;margin:0 auto 10px;">
      @endif
      <div style="font-size:30px;line-height:1;margin-bottom:8px;">&#128203;</div>
      <div style="color:#ffffff;font-size:21px;font-weight:800;letter-spacing:-.02em;">Kickoff Meeting Minutes</div>
      <div style="color:rgba(255,255,255,.92);font-size:13px;margin-top:6px;">Minutes &amp; Acknowledgment Required</div>
    </td></tr>

    <tr><td style="padding:30px 32px;">

      <div style="font-size:18px;font-weight:800;color:#111827;margin:0 0 6px;">Dear {{ $recipientName }},</div>
      <p style="font-size:14px;color:#374151;line-height:1.6;margin:0 0 22px;">
        Please find below the minutes of the kickoff meeting{{ $meetingDate ? ' held on '.$meetingDate : '' }}.
      </p>

      {{-- Meeting details --}}
      <div style="font-size:14px;font-weight:800;color:#0f766e;margin:0 0 10px;">&#128197; Meeting Details</div>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin:0 0 24px;">
        @foreach($details as $label => $value)
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;width:38%;">{{ $label }}</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $value }}</td>
        </tr>
        @endforeach
      </table>

      {{-- Action items — the itemised minutes (kickoff_mom_items). Already
           captured on the meeting form; this is the first place they are shown. --}}
      @if($momItems->count())
        <div style="font-size:14px;font-weight:800;color:#0f766e;margin:0 0 10px;">&#9989; Action Items</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin:0 0 24px;border-collapse:separate;">
          <tr style="background:#0d9488;">
            <th align="left" style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;width:34px;">#</th>
            <th align="left" style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;">Description / Action Item</th>
            <th align="left" style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;width:96px;">Target Date</th>
            <th align="left" style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;width:110px;">Remarks</th>
          </tr>
          @foreach($momItems as $i => $item)
          <tr style="background:{{ $i % 2 ? '#f9fafb' : '#ffffff' }};">
            <td valign="top" style="padding:10px 14px;font-size:12.5px;color:#6b7280;">{{ $i + 1 }}</td>
            <td valign="top" style="padding:10px 14px;font-size:12.5px;color:#111827;line-height:1.5;">
              {{ $item->description ?: '—' }}
              @if($item->responsible_names)
                <div style="font-size:11px;color:#6b7280;margin-top:3px;">Responsible: {{ $item->responsible_names }}</div>
              @endif
            </td>
            <td valign="top" style="padding:10px 14px;font-size:12.5px;color:#374151;">{{ optional($item->target_date)->format('d M Y') ?: '—' }}</td>
            <td valign="top" style="padding:10px 14px;font-size:12.5px;color:#374151;">{{ $item->remark ?: '—' }}</td>
          </tr>
          @endforeach
        </table>
      @endif

      {{-- Participants. Mirrors the MOM PDF so the mail and the document agree. --}}
      @if($attendees->count())
        <div style="font-size:14px;font-weight:800;color:#0f766e;margin:0 0 10px;">
          &#128101; Participants &amp; Attendance ({{ $presentCount }}/{{ $attendees->count() }} present)
        </div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin:0 0 24px;border-collapse:separate;">
          <tr style="background:#0d9488;">
            <th align="left"  style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;width:34px;">#</th>
            <th align="left"  style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;">Name</th>
            <th align="left"  style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;">Role</th>
            <th align="left"  style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;">Organisation</th>
            <th align="right" style="padding:9px 14px;font-size:11.5px;color:#ffffff;font-weight:700;">Attendance</th>
          </tr>
          @foreach($attendees as $i => $a)
          <tr style="background:{{ $i % 2 ? '#f9fafb' : '#ffffff' }};">
            <td style="padding:9px 14px;font-size:12.5px;color:#6b7280;">{{ $i + 1 }}</td>
            <td style="padding:9px 14px;font-size:12.5px;color:#111827;font-weight:600;">{{ $a->name ?: '—' }}</td>
            <td style="padding:9px 14px;font-size:12.5px;color:#374151;">{{ $a->role ?: '—' }}</td>
            <td style="padding:9px 14px;font-size:12.5px;color:#374151;">{{ $a->organisation ?: '—' }}</td>
            <td align="right" style="padding:9px 14px;font-size:12px;font-weight:700;color:{{ $a->attended ? '#047857' : '#b91c1c' }};">
              {{ $a->attended ? 'Present' : 'Absent' }}
            </td>
          </tr>
          @endforeach
        </table>
      @endif

      {{-- Agenda + minutes. Free text in this system, so rendered as prose. --}}
      @if($meeting->agenda)
        <div style="font-size:14px;font-weight:800;color:#0f766e;margin:0 0 8px;">&#128221; Agenda</div>
        <p style="font-size:13.5px;color:#374151;line-height:1.65;margin:0 0 22px;white-space:pre-line;">{{ $meeting->agenda }}</p>
      @endif

      <div style="font-size:14px;font-weight:800;color:#0f766e;margin:0 0 8px;">&#128196; Minutes</div>
      <p style="font-size:13.5px;color:#374151;line-height:1.65;margin:0 0 24px;white-space:pre-line;">{{ $meeting->minutes ?: 'No minutes have been recorded.' }}</p>

      {{-- Read the minutes. Opens the one generated PDF straight from the link,
           no login: the vendor's signatory has no CRM account, and acknowledging
           without being able to read the document first is not a real choice. --}}
      @if($momUrl)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:10px;margin:0 0 22px;">
          <tr><td align="center" style="padding:20px;">
            <div style="font-size:13.5px;font-weight:800;color:#0f766e;margin-bottom:4px;">&#128196; Minutes of Meeting (PDF)</div>
            <p style="font-size:12.5px;color:#0f766e;line-height:1.6;margin:0 0 14px;">
              Open the signed minutes — no login required.
            </p>
            <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 12px;">
              <tr><td style="background:#0d9488;border-radius:9px;">
                <a href="{{ $momUrl }}" style="display:inline-block;padding:12px 30px;color:#ffffff;font-size:14.5px;font-weight:700;text-decoration:none;">View MOM PDF</a>
              </td></tr>
            </table>
            <div style="font-size:11.5px;color:#0f766e;margin:0 0 4px;">If the button doesn't work, copy this link:</div>
            <div style="font-size:11.5px;color:#047857;word-break:break-all;">{{ $momUrl }}</div>
          </td></tr>
        </table>
      @endif

      {{-- Acknowledgement CTA. Only when a live token exists — an already
           acknowledged or never-published meeting must not show a dead button. --}}
      @if($ackUrl)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;margin:0 0 22px;">
          <tr><td align="center" style="padding:22px 20px;">
            <div style="font-size:22px;line-height:1;margin-bottom:8px;">&#9888;&#65039;</div>
            <div style="font-size:15px;font-weight:800;color:#92400e;margin-bottom:6px;">Acknowledgment Required</div>
            <p style="font-size:13px;color:#92400e;line-height:1.6;margin:0 0 14px;">
              Please acknowledge receipt of these minutes by clicking the button below.
            </p>
            @if($deadline)
              <div style="display:inline-block;background:#ffffff;border:1px dashed #fbbf24;border-radius:8px;padding:7px 14px;font-size:12.5px;color:#92400e;font-weight:700;margin-bottom:16px;">
                &#128337; Deadline: {{ $deadline }} ({{ $windowHours }} hours from now)
              </div>
            @endif
            <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 14px;">
              <tr><td style="background:#059669;border-radius:9px;">
                <a href="{{ $ackUrl }}" style="display:inline-block;padding:13px 32px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;">&#10003; Acknowledge MOM</a>
              </td></tr>
            </table>
            <div style="font-size:11.5px;color:#92400e;margin:0 0 4px;">If the button doesn't work, copy this link:</div>
            <div style="font-size:11.5px;color:#047857;word-break:break-all;">{{ $ackUrl }}</div>
          </td></tr>
        </table>

      @endif

      {{-- Closes the message for BOTH audiences: the vendor who acknowledges and
           the one who only reads. Outside the @if, or a read-only recipient ends
           on a bare link. --}}
      <p style="font-size:12.5px;color:#6b7280;line-height:1.6;margin:0;border-top:1px solid #e5e7eb;padding-top:16px;">
        &#128204; Please review all points above and take the necessary actions.
      </p>

    </td></tr>

    {{-- Footer --}}
    <tr><td align="center" style="background:#111827;padding:18px 32px;">
      <div style="font-size:12px;color:#9ca3af;line-height:1.6;">
        This is an automated email from <strong style="color:#e5e7eb;">{{ $companyName }}</strong>
      </div>
      <div style="font-size:11px;color:#6b7280;margin-top:6px;">Please do not reply to this email.</div>
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
