<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1a1a2e;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e6e6ef;">
        <tr><td style="background:#b91c1c;color:#ffffff;padding:16px 24px;font-size:16px;font-weight:700;">
          ⚠ Product Recall
        </td></tr>
        <tr><td style="padding:24px;">
          <h1 style="margin:0 0 8px;font-size:18px;">{{ $headline }}</h1>
          <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#44445a;">{{ $body }}</p>

          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;border-collapse:collapse;">
            <tr><td style="padding:6px 0;color:#77778c;width:40%;">Item</td><td style="padding:6px 0;font-weight:600;">{{ $product }}</td></tr>
            <tr><td style="padding:6px 0;color:#77778c;">Batch / Lot</td><td style="padding:6px 0;font-weight:600;">{{ $batch->batch_no }}{{ $batch->lot_number ? ' / '.$batch->lot_number : '' }}</td></tr>
            @if($batch->warehouse)
            <tr><td style="padding:6px 0;color:#77778c;">Warehouse</td><td style="padding:6px 0;">{{ $batch->warehouse->name }}</td></tr>
            @endif
            <tr><td style="padding:6px 0;color:#77778c;">Quantity quarantined</td><td style="padding:6px 0;">{{ rtrim(rtrim(number_format((float) $batch->remaining_qty, 3, '.', ''), '0'), '.') }}</td></tr>
            @if($batch->expiry_date)
            <tr><td style="padding:6px 0;color:#77778c;">Expiry</td><td style="padding:6px 0;">{{ $batch->expiry_date->toDateString() }}</td></tr>
            @endif
            <tr><td style="padding:6px 0;color:#77778c;">Reason</td><td style="padding:6px 0;">{{ $batch->recall_reason }}</td></tr>
          </table>

          <p style="margin:20px 0 0;font-size:12px;color:#99999f;line-height:1.6;">
            This batch has been quarantined and removed from picking. Pull any affected stock and, if it has already shipped, contact the customers who received it.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
