@php
    /**
     * TPV Onboarding — Kickoff Document (Step 1). Content is resolved from the
     * vendor + tenant records; nothing hardcoded. Rendered by KickoffPdfService.
     *
     * @var \App\Models\Tpv\TpvOnboarding $onboarding
     */
    $company = $tenant->name ?? config('app.name');
    $brand   = $tenant->branding_color ?? '#7C3AED';
    $logo    = $tenant->logo_url ?? null;
    $vname   = $vendor->company_name ?? 'Vendor';
    $vcode   = $vendor->vendor_code ?? '—';
    $ref     = 'KO-' . str_pad((string) $onboarding->id, 5, '0', STR_PAD_LEFT);
    $date    = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $ref }} — Kickoff Document</title>
    <style>
        @page { margin: 34px 40px 52px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11.5px; color: #1f2937; line-height: 1.55; }
        .head { border-bottom: 3px solid {{ $brand }}; padding-bottom: 12px; margin-bottom: 18px; }
        .head td { vertical-align: middle; }
        .company { font-size: 19px; font-weight: bold; color: {{ $brand }}; }
        .meta { text-align: right; font-size: 10.5px; }
        .muted { color: #6b7280; }
        .doctitle { font-size: 16px; font-weight: bold; color: {{ $brand }}; text-transform: uppercase; letter-spacing: .5px; margin: 2px 0 4px; }
        h2 { font-size: 12.5px; margin: 16px 0 6px; color: {{ $brand }}; text-transform: uppercase; letter-spacing: .4px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        table.kv { width: 100%; border-collapse: collapse; margin: 4px 0 8px; }
        table.kv td { padding: 6px 9px; border: 1px solid #e5e7eb; font-size: 11px; }
        table.kv td.k { background: #f9fafb; font-weight: bold; width: 26%; color: #374151; }
        ol { padding-left: 16px; margin: 6px 0; }
        ol li { margin-bottom: 5px; }
        .note { margin-top: 12px; padding: 10px 12px; border: 1px solid #ddd6fe; background: #f5f3ff; color: #5b21b6; font-size: 10.5px; border-radius: 6px; }
        .footer { position: fixed; bottom: -32px; left: 0; right: 0; font-size: 9px; color: #9ca3af;
                  border-top: 1px solid #e5e7eb; padding-top: 6px; text-align: center; }
    </style>
</head>
<body>

<table class="head" width="100%">
    <tr>
        <td>
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $company }}" style="max-height:42px;">
            @endif
            <div class="company">{{ $company }}</div>
        </td>
        <td class="meta">
            <div class="doctitle">Kickoff Document</div>
            <div><strong>Reference:</strong> {{ $ref }}</div>
            <div class="muted">Issued: {{ $date($onboarding->created_at) }}</div>
        </td>
    </tr>
</table>

<p>Dear <strong>{{ $vname }}</strong>,</p>
<p>Welcome to the {{ $company }} Third Party Vendor onboarding programme. This Kickoff Document sets out
what the onboarding involves and what is expected of you before your organisation can be activated for work.</p>

<h2>Vendor</h2>
<table class="kv">
    <tr><td class="k">Company</td><td>{{ $vname }}</td><td class="k">Vendor Code</td><td>{{ $vcode }}</td></tr>
    <tr><td class="k">Reference</td><td>{{ $ref }}</td><td class="k">Date</td><td>{{ $date($onboarding->created_at) }}</td></tr>
</table>

<h2>Onboarding Steps</h2>
<ol>
    <li><strong>Kickoff</strong> — read and acknowledge this document.</li>
    <li><strong>Company Profile</strong> — provide your company, contact, bank, GST and PAN details.</li>
    <li><strong>Legal Documents</strong> — upload the required statutory documents.</li>
    <li><strong>Under Review</strong> — our team verifies each document.</li>
    <li><strong>Final Confirmation</strong> — review the summary and submit your declaration.</li>
    <li><strong>Final Approval</strong> — on approval you receive your registration number and portal access.</li>
</ol>

<h2>Your Responsibilities</h2>
<ol>
    <li>Ensure every document is current, legible and matches the company details provided.</li>
    <li>Respond promptly to any document rejection and re-upload the corrected file.</li>
    <li>Comply with all site health, safety, security and environment (HSSE) requirements.</li>
    <li>Keep your contact and authorised-person details up to date throughout the engagement.</li>
</ol>

<div class="note">
    By ticking the acknowledgement checkbox in the portal you confirm that you have read and understood this
    Kickoff Document. Your acknowledgement is recorded with the date, time and originating device for audit.
</div>

<div class="footer">{{ $company }} · {{ $ref }} · Third Party Vendor Onboarding</div>

</body>
</html>
