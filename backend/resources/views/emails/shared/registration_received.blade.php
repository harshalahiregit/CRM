{{-- Sent to a vendor the moment they finish self-registering.

     Both self-registration paths — TPV and Purchase — created the records,
     logged a line and told nobody. The vendor saw "Awaiting admin approval" on
     a page they then closed, and from their side the next thing that happened
     was nothing, for as long as approval took. This is the mail that says the
     form arrived, what the reference is, and what happens next. --}}
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">

    <tr><td style="background:linear-gradient(135deg,#6d28d9,#7c3aed);padding:28px 32px;">
      <div style="color:#ffffff;font-size:20px;font-weight:800;letter-spacing:-.02em;">{{ $companyName }}</div>
      <div style="color:rgba(255,255,255,.9);font-size:13px;margin-top:6px;">{{ $portalName }}</div>
    </td></tr>

    <tr><td style="padding:32px;">
      <div style="font-size:22px;font-weight:800;color:#111827;margin:0 0 4px;">We have your registration</div>
      <p style="font-size:15px;color:#374151;line-height:1.6;margin:0 0 18px;">
        Hello <strong>{{ $vendorName }}</strong>, thank you for registering with {{ $companyName }}.
        Your details have been received and are now with our team for review.
      </p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin:0 0 22px;">
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;width:44%;">Registered email</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $email }}</td>
        </tr>
        @if($reference)
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;">Your reference</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $reference }}</td>
        </tr>
        @endif
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;">Received</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $receivedAt }}</td>
        </tr>
      </table>

      <div style="font-size:14px;font-weight:800;color:#111827;margin:0 0 10px;">What happens next</div>
      <ol style="font-size:14px;color:#374151;line-height:1.7;margin:0 0 22px;padding-left:20px;">
        <li>Our team reviews your registration.</li>
        <li>Once it is approved, you will receive a second email with your sign-in details.</li>
        <li>You then sign in and complete your onboarding documents.</li>
      </ol>

      <p style="font-size:13px;color:#6b7280;line-height:1.6;margin:0 0 4px;">
        <strong>You do not need to register again.</strong> Registering a second time with the same
        email address will be refused, because that address is already on file with us.
      </p>

      <p style="font-size:13px;color:#6b7280;line-height:1.6;margin:18px 0 0;border-top:1px solid #e5e7eb;padding-top:18px;">
        Questions in the meantime? Write to <a href="mailto:{{ $supportEmail }}" style="color:#7c3aed;">{{ $supportEmail }}</a>.
      </p>
    </td></tr>

    <tr><td style="background:#f9fafb;padding:18px 32px;border-top:1px solid #e5e7eb;">
      <div style="font-size:12px;color:#6b7280;line-height:1.6;">
        Regards,<br><strong style="color:#374151;">{{ $companyName }}</strong>
      </div>
      <div style="font-size:11px;color:#9ca3af;margin-top:8px;">
        This message confirms a registration made with your email address. If that was not you, please ignore it —
        no account can be used until it is approved.
      </div>
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
