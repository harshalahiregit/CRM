<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $appointed ? 'Ticket manager appointment' : 'Ticket manager removal' }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg,{{ $appointed ? '#7c3aed,#4f46e5' : '#64748b,#475569' }}); padding:28px 32px;">
                            <p style="margin:0; color:#ffffff; font-size:20px; font-weight:bold;">
                                {{ $appointed ? '🎧 You’re now a ticket manager' : 'Ticket manager access removed' }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 12px; font-size:16px; color:#0f172a;">Hi {{ $recipientName }},</p>
                            @if ($appointed)
                                <p style="margin:0 0 8px; font-size:14px; color:#334155; line-height:1.6;">
                                    An admin has made you a <strong>ticket manager</strong> for <strong>{{ $scopeLabel }}</strong>.
                                    You’ll now see and be notified about tickets raised there, and you can assign and manage them.
                                </p>
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0 4px;">
                                    <tr>
                                        <td style="border-radius:10px; background:linear-gradient(135deg,#7c3aed,#4f46e5);">
                                            <a href="{{ $helpdeskUrl }}" style="display:inline-block; padding:12px 24px; font-size:14px; font-weight:bold; color:#ffffff; text-decoration:none;">Open the helpdesk &rarr;</a>
                                        </td>
                                    </tr>
                                </table>
                            @else
                                <p style="margin:0 0 8px; font-size:14px; color:#334155; line-height:1.6;">
                                    An admin has removed your <strong>ticket manager</strong> access for <strong>{{ $scopeLabel }}</strong>.
                                    You’ll no longer be notified about or able to manage tickets there.
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f8fafc; padding:16px 32px; border-top:1px solid #e2e8f0;">
                            <p style="margin:0; font-size:11px; color:#94a3b8;">This is an automated message from the Helpdesk module.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
