@php
    $link = rtrim(config('inventory.app_url'), '/').'/app/inventory/warehouses';
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $headline }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="620" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                <tr>
                    <td style="background:linear-gradient(135deg,#f59e0b,#fbbf24); padding:26px 32px;">
                        <p style="margin:0; color:#ffffff; font-size:19px; font-weight:bold;">{{ $headline }}</p>
                        <p style="margin:6px 0 0; color:rgba(255,255,255,0.85); font-size:13px;">Daily warehouse housekeeping</p>
                    </td>
                </tr>

                @foreach ($sites as $site)
                    <tr>
                        <td style="padding:22px 32px 0;">
                            <p style="margin:0 0 8px; font-size:14px; font-weight:bold; color:#0f172a;">
                                {{ $site['warehouse']['name'] }}
                                @if (! empty($site['warehouse']['code']))
                                    <span style="color:#94a3b8; font-size:12px; font-family:monospace;">{{ $site['warehouse']['code'] }}</span>
                                @endif
                            </p>

                            @if ($site['unlocated'] > 0)
                                {{-- Listed first, and framed as the bigger problem: an
                                     over-full bin is a tidy-up, but stock nobody can
                                     locate gets re-ordered because it cannot be found. --}}
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                    style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; margin-bottom:10px;">
                                    <tr>
                                        <td style="padding:12px 16px;">
                                            <p style="margin:0; font-size:13px; color:#7f1d1d;">
                                                <strong>{{ $fmt($site['unlocated']) }}</strong> unit(s) are here with no bin recorded —
                                                the site says it has them and nobody can say where.
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @if (! empty($site['over']))
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px;">
                                    <thead>
                                        <tr>
                                            <th align="left"  style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Bin</th>
                                            <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Holding</th>
                                            <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Limit</th>
                                            <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Full</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach (array_slice($site['over'], 0, 12) as $b)
                                            <tr>
                                                <td style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a; font-family:monospace;">{{ $b['code'] }}</td>
                                                <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a; font-weight:bold;">{{ $fmt($b['used']) }}</td>
                                                <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#64748b;">{{ $fmt($b['capacity']) }}</td>
                                                <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#dc2626; font-weight:bold;">{{ $b['utilisation'] }}%</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                @if (count($site['over']) > 12)
                                    <p style="margin:8px 0 0; font-size:12px; color:#94a3b8;">…and {{ count($site['over']) - 12 }} more in the app.</p>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach

                <tr>
                    <td style="padding:22px 32px 28px;">
                        <a href="{{ $link }}" style="display:inline-block; background:#f59e0b; color:#ffffff; text-decoration:none; font-size:13px; font-weight:bold; padding:11px 22px; border-radius:10px;">
                            Open the layout
                        </a>
                    </td>
                </tr>

                <tr>
                    <td style="background:#f8fafc; padding:16px 32px; border-top:1px solid #e2e8f0;">
                        <p style="margin:0; font-size:11px; color:#94a3b8;">
                            Automatic notice from the Inventory module. Turn these off in Inventory &rsaquo; Settings &rsaquo; Notifications.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
