<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $breached ? 'SLA breached' : 'SLA at risk' }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                    <tr>
                        <td style="background:{{ $breached ? 'linear-gradient(135deg,#dc2626,#b91c1c)' : 'linear-gradient(135deg,#f59e0b,#d97706)' }}; padding:28px 32px;">
                            <p style="margin:0; color:#ffffff; font-size:20px; font-weight:bold;">
                                {{ $breached ? '🚨 SLA breached' : '⚠️ SLA at risk' }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 12px; font-size:16px; color:#0f172a;">Hi {{ $recipientName }},</p>
                            <p style="margin:0 0 8px; font-size:14px; color:#334155; line-height:1.6;">
                                @if ($breached)
                                    This ticket has missed its SLA target and still needs work.
                                @else
                                    This ticket is close to missing its SLA target.
                                @endif
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0; background:{{ $breached ? '#fef2f2' : '#fffbeb' }}; border:1px solid {{ $breached ? '#fecaca' : '#fde68a' }}; border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <p style="margin:0 0 6px; font-size:18px; font-weight:bold; color:#0f172a;">#{{ $ticket->id }} — {{ $ticket->subject }}</p>
                                        <p style="margin:0; font-size:13px; color:#64748b; text-transform:capitalize;">Priority: {{ $ticket->priority }} · Status: {{ str_replace('-', ' ', $ticket->status) }}</p>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px; border-collapse:collapse;">
                                <tr>
                                    <td style="padding:8px 0; font-size:11px; font-weight:bold; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #e2e8f0;">Clock</td>
                                    <td style="padding:8px 0; font-size:11px; font-weight:bold; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #e2e8f0;">Target</td>
                                    <td style="padding:8px 0; font-size:11px; font-weight:bold; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #e2e8f0;">State</td>
                                </tr>
                                @foreach ($breaches as $b)
                                    <tr>
                                        <td style="padding:10px 0; font-size:14px; color:#0f172a; border-bottom:1px solid #f1f5f9;">
                                            {{ $b['clock'] === 'response' ? 'First response' : 'Resolution' }}
                                        </td>
                                        <td style="padding:10px 0; font-size:14px; color:#334155; border-bottom:1px solid #f1f5f9;">
                                            {{ \Illuminate\Support\Carbon::parse($b['due'])->format('D j M, H:i') }}
                                        </td>
                                        <td style="padding:10px 0; font-size:13px; font-weight:bold; color:{{ $b['state'] === 'breached' ? '#dc2626' : '#d97706' }}; border-bottom:1px solid #f1f5f9;">
                                            {{ $b['state'] === 'breached' ? 'Breached' : 'At risk' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </table>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 4px;">
                                <tr>
                                    <td style="border-radius:10px; background:{{ $breached ? 'linear-gradient(135deg,#dc2626,#b91c1c)' : 'linear-gradient(135deg,#f59e0b,#d97706)' }};">
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
