<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket reminder due</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#0ea5e9,#4f46e5); padding:28px 32px;">
                            <p style="margin:0; color:#ffffff; font-size:20px; font-weight:bold;">⏰ Reminder due</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 12px; font-size:16px; color:#0f172a;">Hi {{ $agentName }},</p>
                            <p style="margin:0 0 8px; font-size:14px; color:#334155; line-height:1.6;">
                                You asked to be reminded about this ticket
                                @if ($reminder->remind_at)
                                    at {{ \Illuminate\Support\Carbon::parse($reminder->remind_at)->format('D j M Y, H:i') }}
                                @endif.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0; background:#f0f9ff; border:1px solid #bae6fd; border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <p style="margin:0 0 6px; font-size:18px; font-weight:bold; color:#0f172a;">#{{ $ticket->id }} — {{ $ticket->subject }}</p>
                                        <p style="margin:0; font-size:13px; color:#64748b; text-transform:capitalize;">Priority: {{ $ticket->priority }} · Status: {{ str_replace('-', ' ', $ticket->status) }}</p>
                                    </td>
                                </tr>
                            </table>

                            @if ($reminder->note)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px; background:#f8fafc; border-left:3px solid #4f46e5; border-radius:6px;">
                                    <tr>
                                        <td style="padding:14px 18px;">
                                            <p style="margin:0 0 4px; font-size:11px; font-weight:bold; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px;">Your note</p>
                                            <p style="margin:0; font-size:14px; color:#334155; line-height:1.6;">{{ $reminder->note }}</p>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 4px;">
                                <tr>
                                    <td style="border-radius:10px; background:linear-gradient(135deg,#0ea5e9,#4f46e5);">
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
