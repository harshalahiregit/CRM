@php
    $accent  = $critical ? '#dc2626' : '#f59e0b';
    $accent2 = $critical ? '#ef4444' : '#fbbf24';
    $link    = rtrim(config('inventory.app_url'), '/').'/app/inventory/products?alert='.($critical ? 'out' : 'low');
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
                    <td style="background:linear-gradient(135deg,{{ $accent }},{{ $accent2 }}); padding:26px 32px;">
                        <p style="margin:0; color:#ffffff; font-size:19px; font-weight:bold;">
                            {{ $critical ? 'Out of stock' : 'Stock running low' }}
                        </p>
                        <p style="margin:6px 0 0; color:rgba(255,255,255,0.88); font-size:13px;">
                            {{ $headline }}@if ($warehouse) &middot; {{ $warehouse }} @endif
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px 32px 4px;">
                        <p style="margin:0; font-size:14px; color:#334155; line-height:1.65;">
                            {{ $critical
                                ? 'The items below have no stock left. Anything promised against them cannot be fulfilled until they are replenished.'
                                : 'The items below have just fallen to or below their reorder point. Raise a receiving voucher before they run out.' }}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 32px 8px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr>
                                    <th align="left"  style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Item</th>
                                    <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">On hand</th>
                                    <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Reorder at</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($items as $row)
                                    <tr>
                                        <td style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a;">
                                            <strong>{{ $row['product']->name }}</strong><br>
                                            <span style="color:#94a3b8; font-size:11px;">{{ $row['product']->sku }}</span>
                                        </td>
                                        <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; font-weight:bold; color:{{ $row['on_hand'] <= 0 ? '#dc2626' : '#b45309' }};">
                                            {{ $fmt($row['on_hand']) }}
                                        </td>
                                        <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#334155;">{{ $fmt($row['threshold']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 32px 28px;">
                        <a href="{{ $link }}" style="display:inline-block; background:{{ $accent }}; color:#ffffff; text-decoration:none; font-size:13px; font-weight:bold; padding:11px 22px; border-radius:10px;">
                            Open the reorder list
                        </a>
                    </td>
                </tr>

                <tr>
                    <td style="background:#f8fafc; padding:16px 32px; border-top:1px solid #e2e8f0;">
                        <p style="margin:0; font-size:11px; color:#94a3b8;">
                            Automatic alert from the Inventory module. Turn these off in Inventory &rsaquo; Settings &rsaquo; Notifications.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
