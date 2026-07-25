@php
    $link = rtrim(config('inventory.app_url'), '/').'/app/inventory/transfers';
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0';
    $money = fn ($n) => ($n < 0 ? '-' : '').number_format(abs((float) $n), 2);
    // Red once something is missing; blue while the lorry is simply on its way.
    $bad     = $summary && ($summary['short_qty'] ?? 0) > 0;
    $accent  = $bad ? '#dc2626' : '#0ea5e9';
    $accent2 = $bad ? '#f87171' : '#38bdf8';
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
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                <tr>
                    <td style="background:linear-gradient(135deg,{{ $accent }},{{ $accent2 }}); padding:26px 32px;">
                        <p style="margin:0; color:#ffffff; font-size:19px; font-weight:bold;">{{ $headline }}</p>
                        <p style="margin:6px 0 0; color:rgba(255,255,255,0.85); font-size:13px;">
                            {{ $transfer->code }} &middot;
                            {{ $transfer->fromWarehouse->name ?? 'source' }} &rarr; {{ $transfer->toWarehouse->name ?? 'destination' }}
                            @if ($transfer->expected_at) &middot; expected {{ $transfer->expected_at->format('d M Y') }} @endif
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px 32px 0;">
                        <p style="margin:0; font-size:14px; color:#334155; line-height:1.65;">{{ $body }}</p>
                    </td>
                </tr>

                @if ($transfer->vehicle_no || $transfer->driver_name || $transfer->tracking_number)
                    <tr>
                        <td style="padding:18px 32px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
                                <tr>
                                    <td style="padding:14px 18px;">
                                        <p style="margin:0; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">On the road</p>
                                        <p style="margin:4px 0 0; font-size:13px; color:#0f172a;">
                                            @if ($transfer->vehicle_no)<strong style="font-family:monospace;">{{ $transfer->vehicle_no }}</strong>@endif
                                            @if ($transfer->driver_name) &middot; {{ $transfer->driver_name }} @endif
                                            @if ($transfer->driver_phone) &middot; {{ $transfer->driver_phone }} @endif
                                        </p>
                                        @if ($transfer->tracking_number)
                                            <p style="margin:2px 0 0; font-size:12px; color:#64748b;">
                                                {{ $transfer->carrier }} <span style="font-family:monospace;">{{ $transfer->tracking_number }}</span>
                                            </p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif

                @if ($bad && ! empty($summary['problems']))
                    <tr>
                        <td style="padding:20px 32px 8px;">
                            <p style="margin:0 0 8px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">
                                What did not arrive
                            </p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px;">
                                <thead>
                                    <tr>
                                        <th align="left"  style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Item</th>
                                        <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Sent</th>
                                        <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Arrived</th>
                                        <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Missing</th>
                                        <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($summary['problems'] as $p)
                                        <tr>
                                            <td style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a;"><strong>{{ $p['product'] }}</strong></td>
                                            <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#64748b;">{{ $fmt($p['dispatched']) }}</td>
                                            <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a;">{{ $fmt($p['received']) }}</td>
                                            <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#dc2626; font-weight:bold;">{{ $fmt($p['missing']) }}</td>
                                            <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#334155;">{{ $money($p['value']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>
                @elseif (count($lines))
                    {{-- The manifest: what the receiving site should expect to see. --}}
                    <tr>
                        <td style="padding:20px 32px 8px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px;">
                                <thead>
                                    <tr>
                                        <th align="left"  style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Item</th>
                                        <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Sent</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $l)
                                        <tr>
                                            <td style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a;">
                                                <strong>{{ $l->product->name ?? 'Item' }}</strong><br>
                                                <span style="color:#94a3b8; font-size:11px;">{{ $l->product->sku ?? '' }}</span>
                                            </td>
                                            <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a; font-weight:bold;">{{ $fmt($l->dispatched_qty) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:20px 32px 28px;">
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
