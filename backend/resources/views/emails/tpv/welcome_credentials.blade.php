{{-- TPV welcome + credentials, sent when the vendor is added (before activation)
     so they can log in and complete onboarding. Always carries a plaintext the
     system holds only in memory (admin-typed or freshly generated). --}}
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">

    <tr><td style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);padding:28px 32px;">
      @if($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ $companyName }}" height="36" style="height:36px;display:block;border:0;">
      @else
        <div style="color:#ffffff;font-size:20px;font-weight:800;letter-spacing:-.02em;">{{ $companyName }}</div>
      @endif
      <div style="color:rgba(255,255,255,.9);font-size:13px;margin-top:6px;">Third Party Vendor Portal</div>
    </td></tr>

    <tr><td style="padding:32px;">
      <div style="font-size:22px;font-weight:800;color:#111827;margin:0 0 4px;">👋 Welcome</div>
      <p style="font-size:15px;color:#374151;line-height:1.6;margin:0 0 18px;">
        Hello <strong>{{ $vendor->company_name }}</strong>, your vendor account has been created.
        Sign in with the details below to <strong>complete your onboarding</strong>.
      </p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:4px 0;margin:0 0 18px;">
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;width:44%;">Login ID (email)</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $vendor->email }}</td>
        </tr>
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;">TPV Code</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $vendor->vendor_code }}</td>
        </tr>
      </table>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;margin:0 0 22px;">
        <tr><td style="padding:16px;">
          <div style="font-size:13px;font-weight:800;color:#92400e;margin-bottom:8px;">Your password</div>
          <div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:17px;font-weight:700;color:#111827;background:#ffffff;border:1px dashed #fbbf24;border-radius:8px;padding:10px 14px;display:inline-block;">{{ $tempPassword }}</div>
          <div style="font-size:12.5px;color:#92400e;line-height:1.6;margin-top:12px;">
            <strong>Please change your password</strong> after your first login.
          </div>
        </td></tr>
      </table>

      <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 26px;">
        <tr><td style="background:#1d4ed8;border-radius:9px;">
          <a href="{{ $portalUrl }}" style="display:inline-block;padding:13px 30px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;">Login &amp; complete onboarding</a>
        </td></tr>
      </table>
      <p style="font-size:12px;color:#6b7280;margin:0 0 4px;">Or paste this link into your browser:</p>
      <p style="font-size:12px;color:#1d4ed8;word-break:break-all;margin:0 0 26px;">{{ $portalUrl }}</p>

      <p style="font-size:13px;color:#6b7280;line-height:1.6;margin:0;border-top:1px solid #e5e7eb;padding-top:18px;">
        Need help? Contact us at <a href="mailto:{{ $supportEmail }}" style="color:#1d4ed8;">{{ $supportEmail }}</a>.
      </p>
    </td></tr>

    <tr><td style="background:#f9fafb;padding:18px 32px;border-top:1px solid #e5e7eb;">
      <div style="font-size:12px;color:#6b7280;line-height:1.6;">
        Regards,<br><strong style="color:#374151;">{{ $companyName }}</strong>
      </div>
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
