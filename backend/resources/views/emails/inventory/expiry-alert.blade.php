@php
    $link = rtrim(config('inventory.app_url'), '/').'/app/inventory/traceability';
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
                    <td style="background:linear-gradient(135deg,#b45309,#f59e0b); padding:26px 32px;">
                        <p style="margin:0; color:#ffffff; font-size:19px; font-weight:bold;">Batches nearing expiry</p>
                        <p style="margin:6px 0 0; color:rgba(255,255,255,0.88); font-size:13px;">{{ $headline }}</p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px 32px 4px;">
                        <p style="margin:0; font-size:14px; color:#334155; line-height:1.65;">
                            These batches expire within {{ $days }} days, or already have. Issue them first (the picker already
                            works oldest-expiry-first) or write them off before they become a loss.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 32px 8px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr>
                                    <th align="left"  style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Item / batch</th>
                                    <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Remaining</th>
                                    <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Expires</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($batches as $b)
                                    @php $gone = $b->expiry_date && $b->expiry_date->isPast(); @endphp
                                    <tr>
                                        <td style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a;">
                                            <strong>{{ $b->product->name ?? 'Item' }}</strong><br>
                                            <span style="color:#94a3b8; font-size:11px;">Batch {{ $b->batch_no }}</span>
                                        </td>
                                        <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#334155;">{{ $fmt($b->remaining_qty) }}</td>
                                        <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; font-weight:bold; color:{{ $gone ? '#dc2626' : '#b45309' }};">
                                            {{ $b->expiry_date ? $b->expiry_date->format('d M Y') : '—' }}
                                            @if ($gone)<br><span style="font-size:10px; font-weight:normal;">expired</span>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 32px 28px;">
                        <a href="{{ $link }}" style="display:inline-block; background:#b45309; color:#ffffff; text-decoration:none; font-size:13px; font-weight:bold; padding:11px 22px; border-radius:10px;">
                            Open batch tracking
                        </a>
                    </td>
                </tr>

                <tr>
                    <td style="background:#f8fafc; padding:16px 32px; border-top:1px solid #e2e8f0;">
                        <p style="margin:0; font-size:11px; color:#94a3b8;">
                            Daily digest from the Inventory module. Turn these off in Inventory &rsaquo; Settings &rsaquo; Notifications.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
