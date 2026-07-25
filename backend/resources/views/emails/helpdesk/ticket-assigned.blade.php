<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket assigned to you</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#7c3aed,#4f46e5); padding:28px 32px;">
                            <p style="margin:0; color:#ffffff; font-size:20px; font-weight:bold;">🎧 New ticket assigned</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 12px; font-size:16px; color:#0f172a;">Hi {{ $agentName }},</p>
                            <p style="margin:0 0 8px; font-size:14px; color:#334155; line-height:1.6;">
                                A ticket has been assigned to you. Here are the details:
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0; background:#f5f3ff; border:1px solid #ddd6fe; border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <p style="margin:0 0 6px; font-size:18px; font-weight:bold; color:#0f172a;">#{{ $ticket->id }} — {{ $ticket->subject }}</p>
                                        <p style="margin:0; font-size:13px; color:#64748b; text-transform:capitalize;">Priority: {{ $ticket->priority }} · Status: {{ str_replace('-', ' ', $ticket->status) }}</p>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 4px;">
                                <tr>
                                    <td style="border-radius:10px; background:linear-gradient(135deg,#7c3aed,#4f46e5);">
                                        <a href="{{ $ticketUrl }}" style="display:inline-block; padding:12px 24px; font-size:14px; font-weight:bold; color:#ffffff; text-decoration:none;">Open ticket &rarr;</a>
                                    </td>
                                </tr>
                            </table>
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
