<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? 'Message' }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:560px; margin:40px auto; background:#ffffff; border-radius:12px; padding:32px; border:1px solid #e2e8f0;">
        {{-- Already sanitized by HtmlSanitizer before it was stored/sent. --}}
        <div style="color:#334155; font-size:14px; line-height:1.65;">{!! $bodyHtml !!}</div>
    </div>
</body>
</html>
