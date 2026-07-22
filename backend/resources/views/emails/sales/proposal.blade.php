<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $proposal->subject }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:560px; margin:40px auto; background:#ffffff; border-radius:12px; padding:32px; border:1px solid #e2e8f0;">
        <div style="color:#334155; font-size:14px; line-height:1.65;">{!! $bodyHtml !!}</div>
        <p style="margin:24px 0; text-align:center;">
            <a href="{{ $portalUrl }}" style="display:inline-block; background:#7C3AED; color:#ffffff; text-decoration:none; font-weight:bold; padding:12px 28px; border-radius:10px;">View Proposal Online</a>
        </p>
        <p style="margin:0; color:#94a3b8; font-size:12px;">
            A PDF copy is attached. If the button doesn't work, open this link:<br>
            <a href="{{ $portalUrl }}" style="color:#7C3AED;">{{ $portalUrl }}</a>
        </p>
    </div>
    <img src="{{ $pixelUrl }}" width="1" height="1" alt="" style="display:block;">
</body>
</html>
