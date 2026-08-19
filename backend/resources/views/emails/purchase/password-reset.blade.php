{{-- Purchase Vendor password-reset e-mail. Table-based + inline CSS so it renders in
     Outlook/Gmail, fluid widths so it reads on a phone. Matches
     emails/purchase/activation.blade.php. Never renders a raw token as text --
     it only ever appears inside the action link. --}}
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">

    <tr><td style="background:linear-gradient(135deg,#7C3AED,#a78bfa);padding:28px 32px;">
      @if($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ $companyName }}" height="36" style="height:36px;display:block;border:0;">
      @else
        <div style="color:#ffffff;font-size:20px;font-weight:800;letter-spacing:-.02em;">{{ $companyName }}</div>
      @endif
      <div style="color:rgba(255,255,255,.9);font-size:13px;margin-top:6px;">Purchase &amp; Procurement Portal</div>
    </td></tr>

    <tr><td style="padding:32px;">
      <div style="font-size:22px;font-weight:800;color:#111827;margin:0 0 4px;">Reset your password</div>
      <p style="font-size:15px;color:#374151;line-height:1.6;margin:0 0 22px;">
        Hello <strong>{{ $vendor->company_name }}</strong>, we received a request to reset the password for your portal account.
      </p>

      <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">
        <tr><td style="border-radius:9px;background:#7C3AED;">
          <a href="{{ $actionUrl }}" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Choose a new password</a>
        </td></tr>
      </table>

      <p style="font-size:12.5px;color:#6b7280;line-height:1.6;margin:0 0 6px;">
        If the button does not work, copy this link into your browser:
      </p>
      <p style="font-size:12px;color:#7C3AED;word-break:break-all;margin:0 0 22px;">{{ $actionUrl }}</p>

      <p style="font-size:12.5px;color:#6b7280;line-height:1.6;margin:0;">This link expires @if($expiresAt) at {{ $expiresAt }} @else in one hour @endif. If you did not request a reset, you can ignore this email &mdash; your password stays unchanged.</p>
    </td></tr>

    <tr><td style="padding:18px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;">
      <div style="font-size:12px;color:#6b7280;">
        Need help? Contact us at <a href="mailto:{{ $supportEmail }}" style="color:#7C3AED;">{{ $supportEmail }}</a>.
      </div>
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
