@php
    $link = rtrim(config('inventory.app_url'), '/').'/app/inventory/fulfilment';
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0';
    $shipped = in_array($list->status, ['shipped', 'delivered'], true);
    $accent  = $shipped ? '#0ea5e9' : '#059669';
    $accent2 = $shipped ? '#38bdf8' : '#10B981';
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
                        <p style="margin:0; color:#ffffff; font-size:19px; font-weight:bold;">{{ $headline }}</p>
                        <p style="margin:6px 0 0; color:rgba(255,255,255,0.85); font-size:13px;">
                            {{ $list->code }}
                            @if ($list->warehouse) &middot; {{ $list->warehouse->name }} @endif
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px 32px 0;">
                        <p style="margin:0; font-size:14px; color:#334155; line-height:1.65;">{{ $body }}</p>
                    </td>
                </tr>

                @if ($list->tracking_number)
                    <tr>
                        <td style="padding:18px 32px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
                                <tr>
                                    <td style="padding:14px 18px;">
                                        <p style="margin:0; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">Tracking</p>
                                        <p style="margin:4px 0 0; font-size:15px; font-weight:bold; color:#0f172a; font-family:monospace;">
                                            {{ $list->tracking_number }}
                                        </p>
                                        <p style="margin:2px 0 0; font-size:12px; color:#64748b;">{{ $list->carrier }}</p>
                                        @if ($list->tracking_url)
                                            <a href="{{ $list->tracking_url }}" style="font-size:12px; color:{{ $accent }};">Track this parcel</a>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:20px 32px 8px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr>
                                    <th align="left"  style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Item</th>
                                    <th align="left"  style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Where</th>
                                    <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($lines as $l)
                                    <tr>
                                        <td style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a;">
                                            <strong>{{ $l->product->name ?? 'Item' }}</strong><br>
                                            <span style="color:#94a3b8; font-size:11px;">{{ $l->product->sku ?? '' }}</span>
                                        </td>
                                        <td style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#334155; font-size:12px;">
                                            {{-- The picker's route: which bin, and which batch FEFO chose. --}}
                                            {{ $l->location->code ?? $l->location->name ?? '—' }}
                                            @if ($l->batch)
                                                <br><span style="color:#94a3b8; font-size:11px;">Batch {{ $l->batch->batch_no }}</span>
                                            @endif
                                        </td>
                                        <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a; font-weight:bold;">
                                            {{ $fmt($shipped ? $l->packed_qty : $l->required_qty) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 32px 28px;">
                        <a href="{{ $link }}" style="display:inline-block; background:{{ $accent }}; color:#ffffff; text-decoration:none; font-size:13px; font-weight:bold; padding:11px 22px; border-radius:10px;">
                            Open in the app
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
