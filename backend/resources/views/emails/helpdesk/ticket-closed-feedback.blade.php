<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>How did we do?</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                    {{-- Header --}}
                    <tr>
                        <td style="background:linear-gradient(135deg,#06b6d4,#0e7490); padding:28px 32px;">
                            <p style="margin:0; color:#ffffff; font-size:20px; font-weight:bold;">🎧 Helpdesk &amp; Support</p>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 12px; font-size:16px; color:#0f172a;">Hi {{ $customerName }},</p>
                            <p style="margin:0 0 8px; font-size:14px; color:#334155; line-height:1.6;">
                                Your ticket <strong>#{{ $ticket->id }} — {{ $ticket->subject }}</strong> has been marked
                                <strong style="color:#059669;">resolved</strong>.
                            </p>
                            <p style="margin:0 0 24px; font-size:14px; color:#334155; line-height:1.6;">
                                How would you rate the support you received? Just tap a star — it takes one click.
                            </p>

                            {{-- One-click star rating (each star is a signed link) --}}
                            <table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin:0 auto 24px;">
                                <tr>
                                    @foreach ($stars as $star)
                                        <td style="padding:0 4px;">
                                            <a href="{{ $star['url'] }}"
                                               style="text-decoration:none; font-size:40px; line-height:1; color:#f59e0b; display:inline-block;"
                                               title="{{ $star['value'] }} star{{ $star['value'] > 1 ? 's' : '' }}">&#9733;</a>
                                        </td>
                                    @endforeach
                                </tr>
                                <tr>
                                    <td colspan="5" align="center" style="padding-top:6px; font-size:11px; color:#94a3b8;">
                                        1 = Poor &nbsp;·&nbsp; 5 = Excellent
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0; font-size:12px; color:#94a3b8; line-height:1.6;">
                                If your issue isn’t fully resolved, simply reply to this ticket and it will reopen automatically.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
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
