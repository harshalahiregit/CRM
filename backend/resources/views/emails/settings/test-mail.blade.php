<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test email</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:520px; margin:40px auto; background:#ffffff; border-radius:12px; padding:32px; border:1px solid #e2e8f0;">
        <h2 style="margin:0 0 12px; color:#1a1535;">✅ Email settings are working</h2>
        <p style="margin:0 0 8px; color:#475569;">
            This is a test message from <strong>{{ config('app.name', 'Sangoe CRM') }}</strong>.
            If you're reading it, this workspace's outgoing-mail configuration delivers successfully.
        </p>
        <p style="margin:16px 0 0; color:#94a3b8; font-size:12px;">
            Sent {{ now()->format('d M Y, H:i') }} · You can safely delete this email.
        </p>
    </div>
</body>
</html>
