{{-- TPV activation e-mail. TPV-owned; Purchase has its own template.
     Table-based + inline CSS so it renders in Outlook/Gmail, and fluid widths so
     it reads on a phone. Never renders a password hash — $tempPassword is only
     ever a plaintext one the system just generated (Scenario B). --}}
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">

    {{-- Header / logo --}}
    <tr><td style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);padding:28px 32px;">
      @if($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ $companyName }}" height="36" style="height:36px;display:block;border:0;">
      @else
        <div style="color:#ffffff;font-size:20px;font-weight:800;letter-spacing:-.02em;">{{ $companyName }}</div>
      @endif
      <div style="color:rgba(255,255,255,.9);font-size:13px;margin-top:6px;">Third Party Vendor Portal</div>
    </td></tr>

    {{-- Body --}}
    <tr><td style="padding:32px;">
      <div style="font-size:22px;font-weight:800;color:#111827;margin:0 0 4px;">🎉 Your account is active</div>
      <p style="font-size:15px;color:#374151;line-height:1.6;margin:0 0 18px;">
        Hello <strong>{{ $vendor->company_name }}</strong>, congratulations — your account has been approved and activated.
        You can now sign in and access the vendor portal.
      </p>

      {{-- Details --}}
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:4px 0;margin:0 0 22px;">
        @foreach ([
          'Registered Email'  => $vendor->email,
          'TPV Code'          => $vendor->vendor_code,
          'Registration Type' => $registrationType,
          'Activation Date'   => $activationDate,
        ] as $label => $value)
        <tr>
          <td style="padding:9px 16px;font-size:12px;color:#6b7280;width:44%;">{{ $label }}</td>
          <td style="padding:9px 16px;font-size:13px;color:#111827;font-weight:600;">{{ $value }}</td>
        </tr>
        @endforeach
      </table>

      @if($tempPassword)
        {{-- Scenario B: admin-created account with a system-generated password --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;margin:0 0 22px;">
          <tr><td style="padding:16px;">
            <div style="font-size:13px;font-weight:800;color:#92400e;margin-bottom:8px;">Your temporary password</div>
            <div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:17px;font-weight:700;color:#111827;background:#ffffff;border:1px dashed #fbbf24;border-radius:8px;padding:10px 14px;display:inline-block;">{{ $tempPassword }}</div>
            <div style="font-size:12.5px;color:#92400e;line-height:1.6;margin-top:12px;">
              <strong>First login:</strong> sign in with the email and temporary password above.<br>
              <strong>Please change your password immediately</strong> after your first login — this temporary one is single-use by convention and should not be reused or shared.
            </div>
          </td></tr>
        </table>
      @endif

      {{-- CTA --}}
      <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 26px;">
        <tr><td style="background:#1d4ed8;border-radius:9px;">
          <a href="{{ $portalUrl }}" style="display:inline-block;padding:13px 30px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;">Login to the Portal</a>
        </td></tr>
      </table>
      <p style="font-size:12px;color:#6b7280;margin:0 0 4px;">Or paste this link into your browser:</p>
      <p style="font-size:12px;color:#1d4ed8;word-break:break-all;margin:0 0 26px;">{{ $portalUrl }}</p>

      <p style="font-size:13px;color:#6b7280;line-height:1.6;margin:0;border-top:1px solid #e5e7eb;padding-top:18px;">
        Need help? Contact us at <a href="mailto:{{ $supportEmail }}" style="color:#1d4ed8;">{{ $supportEmail }}</a>.
      </p>
    </td></tr>

    {{-- Footer --}}
    <tr><td style="background:#f9fafb;padding:18px 32px;border-top:1px solid #e5e7eb;">
      <div style="font-size:12px;color:#6b7280;line-height:1.6;">
        Regards,<br><strong style="color:#374151;">{{ $companyName }}</strong>
      </div>
      <div style="font-size:11px;color:#9ca3af;margin-top:8px;">
        This is an automated message about your vendor account. If you did not expect it, please ignore this email.
      </div>
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
