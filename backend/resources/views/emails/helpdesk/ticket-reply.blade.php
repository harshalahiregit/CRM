<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #{{ $ticket->id }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#06b6d4,#0e7490); padding:28px 32px;">
                            <p style="margin:0; color:#ffffff; font-size:20px; font-weight:bold;">🎧 Helpdesk &amp; Support</p>
                            <p style="margin:6px 0 0; color:#cffafe; font-size:13px;">Ticket #{{ $ticket->id }} · {{ $ticket->subject }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px; font-size:16px; color:#0f172a;">Hi {{ $recipientName }},</p>

                            {{-- The agent's message --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0 0 10px; font-size:12px; color:#0e7490; font-weight:bold;">{{ $agentName }} replied:</p>
                                        {{-- The reply body is rich-text HTML, sanitized on store by
                                             App\Support\HtmlSanitizer before it is persisted, so it is safe
                                             to render here as HTML rather than escaped text. --}}
                                        <div style="margin:0; font-size:14px; color:#334155; line-height:1.7;">{!! $reply->message !!}</div>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0; font-size:13px; color:#64748b; line-height:1.6;">
                                Need to respond? Just <strong>reply to this email</strong> — your message goes straight back to
                                our team on ticket #{{ $ticket->id }}.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f8fafc; padding:16px 32px; border-top:1px solid #e2e8f0;">
                            <p style="margin:0; font-size:11px; color:#94a3b8;">This is an automated message from the Helpdesk module. Please keep the subject line unchanged so we can track your ticket.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
