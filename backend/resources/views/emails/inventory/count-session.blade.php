@php
    $link = rtrim(config('inventory.app_url'), '/').'/app/inventory/counts';
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0';
    $money = fn ($n) => ($n < 0 ? '-' : '').number_format(abs((float) $n), 2);
    // Red when the count found a discrepancy, green when the shelf agreed with
    // the app — the colour is the headline for anyone skimming on a phone.
    $bad     = $summary && ($summary['variances'] ?? 0) > 0;
    $accent  = $bad ? '#dc2626' : '#059669';
    $accent2 = $bad ? '#f87171' : '#10B981';
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
                            {{ $session->code }}
                            @if ($session->warehouse) &middot; {{ $session->warehouse->name }} @endif
                            @if ($session->name) &middot; {{ $session->name }} @endif
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px 32px 0;">
                        <p style="margin:0; font-size:14px; color:#334155; line-height:1.65;">{{ $body }}</p>
                    </td>
                </tr>

                @if ($summary)
                    {{-- The four numbers a supervisor decides on. --}}
                    <tr>
                        <td style="padding:18px 32px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
                                <tr>
                                    @foreach ([
                                        ['Lines counted', $summary['counted']],
                                        ['Matched', $summary['matched']],
                                        ['Variances', $summary['variances']],
                                        ['Accuracy', $summary['accuracy_pct'] === null ? '—' : $summary['accuracy_pct'].'%'],
                                    ] as [$label, $value])
                                        <td align="center" style="padding:14px 8px;">
                                            <p style="margin:0; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">{{ $label }}</p>
                                            <p style="margin:4px 0 0; font-size:18px; font-weight:bold; color:#0f172a;">{{ $value }}</p>
                                        </td>
                                    @endforeach
                                </tr>
                            </table>
                        </td>
                    </tr>

                    @if (! empty($summary['rows']))
                        <tr>
                            <td style="padding:20px 32px 8px;">
                                <p style="margin:0 0 8px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">
                                    What disagreed &mdash; biggest first
                                </p>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px;">
                                    <thead>
                                        <tr>
                                            <th align="left"  style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Item</th>
                                            <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">App said</th>
                                            <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Counted</th>
                                            <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Diff</th>
                                            <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach (array_slice($summary['rows'], 0, 15) as $r)
                                            <tr>
                                                <td style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a;">
                                                    <strong>{{ $r['product'] }}</strong>
                                                    @if ($r['recounted'])
                                                        <span style="color:#0ea5e9; font-size:11px;">&nbsp;recounted</span>
                                                    @endif
                                                    <br><span style="color:#94a3b8; font-size:11px;">{{ $r['sku'] }}</span>
                                                </td>
                                                <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#64748b;">{{ $fmt($r['system']) }}</td>
                                                <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a; font-weight:bold;">{{ $fmt($r['counted']) }}</td>
                                                <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:{{ $r['variance'] < 0 ? '#dc2626' : '#059669' }}; font-weight:bold;">
                                                    {{ $r['variance'] > 0 ? '+' : '' }}{{ $fmt($r['variance']) }}
                                                </td>
                                                <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#334155;">{{ $money($r['value']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                @if (count($summary['rows']) > 15)
                                    <p style="margin:10px 0 0; font-size:12px; color:#94a3b8;">
                                        &hellip;and {{ count($summary['rows']) - 15 }} more in the app.
                                    </p>
                                @endif
                            </td>
                        </tr>
                    @endif
                @elseif (count($lines))
                    {{-- An assigned sheet: where to go, and what to look at. The
                         expected quantity is deliberately absent — a blind count
                         is only worth doing if the counter has nothing to agree with. --}}
                    <tr>
                        <td style="padding:20px 32px 8px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px;">
                                <thead>
                                    <tr>
                                        <th align="left" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Item</th>
                                        <th align="left" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Where to look</th>
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
                                                {{ $l->location->code ?? $l->location->name ?? 'Anywhere in the warehouse' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>
                @endif

                @if ($session->rejection_reason && $session->status === 'counting')
                    <tr>
                        <td style="padding:16px 32px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2; border:1px solid #fecaca; border-radius:12px;">
                                <tr>
                                    <td style="padding:14px 18px;">
                                        <p style="margin:0; font-size:11px; color:#b91c1c; text-transform:uppercase; letter-spacing:0.05em;">Sent back</p>
                                        <p style="margin:4px 0 0; font-size:13px; color:#7f1d1d;">{{ $session->rejection_reason }}</p>
                                    </td>
                                </tr>
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
