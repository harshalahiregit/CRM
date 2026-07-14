<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We've received your request</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#06b6d4,#0e7490); padding:28px 32px;">
                            <p style="margin:0; color:#ffffff; font-size:20px; font-weight:bold;">🎧 Helpdesk &amp; Support</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 12px; font-size:16px; color:#0f172a;">Hi {{ $recipientName }},</p>
                            <p style="margin:0 0 8px; font-size:14px; color:#334155; line-height:1.6;">
                                Thanks for reaching out — we've received your request and created a support ticket for it.
                                Our team is on it.
                            </p>

                            {{-- Ticket reference card --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0; background:#ecfeff; border:1px solid #a5f3fc; border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <p style="margin:0 0 4px; font-size:12px; color:#0e7490; text-transform:uppercase; letter-spacing:.05em;">Your ticket number</p>
                                        <p style="margin:0 0 10px; font-size:22px; font-weight:bold; color:#0f172a;">#{{ $ticket->id }}</p>
                                        <p style="margin:0; font-size:14px; color:#334155;"><strong>Subject:</strong> {{ $ticket->subject }}</p>
                                        <p style="margin:6px 0 0; font-size:13px; color:#64748b; text-transform:capitalize;"><strong>Priority:</strong> {{ $ticket->priority }}</p>
                                    </td>
                                </tr>
                            </table>

                            @if ($ticket->description)
                                <p style="margin:0 0 6px; font-size:12px; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em;">What you told us</p>
                                <p style="margin:0 0 20px; font-size:14px; color:#334155; line-height:1.6; padding:12px 16px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0;">{{ $ticket->description }}</p>
                            @endif

                            <p style="margin:0; font-size:13px; color:#64748b; line-height:1.6;">
                                You can simply <strong>reply to this email</strong> to add more details — your message will be
                                added straight to ticket #{{ $ticket->id }}.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f8fafc; padding:16px 32px; border-top:1px solid #e2e8f0;">
                            <p style="margin:0; font-size:11px; color:#94a3b8;">This is an automated confirmation from the Helpdesk module.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
