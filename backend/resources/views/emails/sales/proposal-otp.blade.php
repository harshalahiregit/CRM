<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification code</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:520px; margin:40px auto; background:#ffffff; border-radius:12px; padding:32px; border:1px solid #e2e8f0;">
        <h2 style="margin:0 0 12px; color:#1a1535;">Proposal access code</h2>
        <p style="margin:0 0 16px; color:#475569;">
            Someone is trying to open the secure link for your proposal
            <strong>{{ $proposal->subject }}</strong>@if($proposal->reference_no) ({{ $proposal->reference_no }})@endif.
            Share this code with the intended recipient so they can view it:
        </p>
        <p style="margin:0 0 16px; text-align:center;">
            <span style="display:inline-block; font-size:32px; letter-spacing:8px; font-weight:bold; color:#7C3AED; background:#f5f3ff; border-radius:10px; padding:12px 24px;">{{ $code }}</span>
        </p>
        <p style="margin:0; color:#94a3b8; font-size:12px;">
            The code expires in 10 minutes. Only share it with the person you intend to give access to —
            it's the key that unlocks this proposal. If you weren't expecting this, you can ignore this email.
        </p>
    </div>
</body>
</html>
