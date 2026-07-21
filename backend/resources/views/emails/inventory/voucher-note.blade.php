<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $voucher->code }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="620" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                <tr>
                    <td style="background:linear-gradient(135deg,#059669,#10B981); padding:26px 32px;">
                        <p style="margin:0; color:#ffffff; font-size:19px; font-weight:bold;">{{ $voucher->type_label }}</p>
                        <p style="margin:6px 0 0; color:rgba(255,255,255,0.85); font-size:13px;">
                            {{ $voucher->code }}
                            @if ($voucher->date_add) &middot; {{ $voucher->date_add->format('d M Y') }} @endif
                        </p>
                    </td>
                </tr>

                @if (trim($body) !== '')
                    <tr>
                        <td style="padding:26px 32px 0;">
                            <p style="margin:0; font-size:14px; color:#334155; line-height:1.65; white-space:pre-line;">{{ $body }}</p>
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:22px 32px 8px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr>
                                    <th align="left"  style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Item</th>
                                    <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Qty</th>
                                    <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Price</th>
                                    <th align="right" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-size:11px; text-transform:uppercase;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($lines as $l)
                                    <tr>
                                        <td style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a;">
                                            <strong>{{ $l->product->name ?? 'Item' }}</strong><br>
                                            <span style="color:#94a3b8; font-size:11px;">{{ $l->product->sku ?? '' }}</span>
                                        </td>
                                        <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#334155;">{{ rtrim(rtrim(number_format((float) $l->quantity, 3, '.', ''), '0'), '.') }}</td>
                                        <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#334155;">{{ number_format((float) $l->unit_price, 2) }}</td>
                                        <td align="right" style="padding:9px 6px; border-bottom:1px solid #f1f5f9; color:#0f172a; font-weight:bold;">{{ number_format((float) $l->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:6px 32px 26px;">
                        <table role="presentation" align="right" cellpadding="0" cellspacing="0" style="font-size:13px;">
                            <tr><td style="padding:3px 12px 3px 0; color:#64748b;">Total goods</td><td align="right" style="color:#0f172a;">{{ number_format((float) $voucher->total_goods, 2) }}</td></tr>
                            @if ((float) $voucher->total_discount > 0)
                                <tr><td style="padding:3px 12px 3px 0; color:#64748b;">Discount</td><td align="right" style="color:#0f172a;">− {{ number_format((float) $voucher->total_discount, 2) }}</td></tr>
                            @endif
                            <tr><td style="padding:3px 12px 3px 0; color:#64748b;">Tax</td><td align="right" style="color:#0f172a;">{{ number_format((float) $voucher->total_tax, 2) }}</td></tr>
                            <tr>
                                <td style="padding:8px 12px 3px 0; color:#0f172a; font-weight:bold; border-top:2px solid #e2e8f0;">Total payment</td>
                                <td align="right" style="padding:8px 0 3px; color:#059669; font-weight:bold; border-top:2px solid #e2e8f0;">{{ number_format((float) $voucher->total_amount, 2) }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="background:#f8fafc; padding:16px 32px; border-top:1px solid #e2e8f0;">
                        <p style="margin:0; font-size:11px; color:#94a3b8;">Sent from the Inventory module. Please reply to this email if anything looks wrong.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
