@php
    /**
     * Vendor HSSE & Work Start Letter — the formal "approval to commence work"
     * issued on Purchase approval. Content is resolved from the vendor / tenant /
     * onboarding records; nothing hardcoded. Rendered by WorkStartLetterService.
     *
     * @var \App\Models\Tpv\TpvOnboarding $onboarding
     * @var \App\Models\Vendor\Vendor     $vendor
     */
    $company = $tenant->name ?? config('app.name', 'Our Company');
    $brand   = $tenant->branding_color ?? '#7C3AED';
    $vname   = $vendor->company_name ?? 'Vendor';
    $vcode   = $vendor->purchase_vendor_code ?? '—';
    $contact = ($vendor->legal_name ?? $vendor->company_name) ?? null;
    $addr    = collect([$vendor->address, $vendor->city, $vendor->state, $vendor->country, $vendor->pincode])
                 ->filter()->implode(', ');
    $date    = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';

    // The standard HSSE document checklist, with a tick for those actually
    // approved. Each row maps its display label to the document-type keys that
    // satisfy it, so duplicate labels (insurance, BOCW) collapse to one line.
    $checklist = [
        ['Vendor Registration Certificate',              ['registration', 'reg_certificate']],
        ['Workmen Compensation Policy & Insurance',      ['workmen_comp', 'insurance', 'wc_policy']],
        ['BOCW & Labour License (if applicable)',        ['bocw', 'labour_license']],
        ['Subcontractor & HSSE Declaration Letter',      ['declaration', 'hsse_declaration']],
        ['Organisation Chart',                           ['org_chart']],
        ['Risk Assessment',                              ['risk_assessment', 'risk']],
        ['Training & Certifications of personnel',       ['training']],
        ['Safety Data Sheets (SDS), if applicable',      ['sds']],
        ['HSSE Vendor Guidelines Acceptance',            ['hsse_guidelines']],
        ['Police Verification',                          ['police', 'police_verification']],
    ];
    $approvedSet = collect($approvedDocs ?? [])->map(fn ($t) => strtolower((string) $t))->all();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $ref }} — Work Start Letter</title>
    <style>
        @page { margin: 34px 42px 54px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11.5px; color: #1f2937; line-height: 1.6; }
        .head { border-bottom: 3px solid {{ $brand }}; padding-bottom: 12px; margin-bottom: 16px; }
        .head td { vertical-align: middle; }
        .company { font-size: 19px; font-weight: bold; color: {{ $brand }}; }
        .meta { text-align: right; font-size: 10.5px; color: #6b7280; }
        .doctitle { font-size: 15px; font-weight: bold; color: {{ $brand }}; text-transform: uppercase; letter-spacing: .5px; margin: 6px 0 10px; }
        .to { margin: 4px 0 12px; }
        .subject { font-weight: bold; margin: 10px 0; }
        h2 { font-size: 12px; margin: 16px 0 6px; color: {{ $brand }}; text-transform: uppercase; letter-spacing: .4px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        ol { padding-left: 16px; margin: 6px 0; }
        ol li { margin-bottom: 4px; }
        .tick { color: #059669; font-weight: bold; }
        .pending { color: #b45309; font-size: 9.5px; }
        .sign { margin-top: 26px; }
        .note { margin-top: 14px; padding: 10px 12px; border: 1px solid #ddd6fe; background: #f5f3ff; color: #5b21b6; font-size: 10.5px; border-radius: 6px; }
        .footer { margin-top: 30px; font-size: 9px; color: #9ca3af;
                  border-top: 1px solid #e5e7eb; padding-top: 6px; text-align: center; }
        .toolbar { text-align: right; margin: 0 0 10px; }
        .toolbar button { font: inherit; font-size: 11px; padding: 6px 14px; border: 1px solid {{ $brand }};
                  background: {{ $brand }}; color: #fff; border-radius: 6px; cursor: pointer; }
        @media print { .toolbar { display: none; } @page { margin: 14mm; } }
        body { max-width: 800px; margin: 0 auto; padding: 20px; }
    </style>
</head>
<body>
    <div class="toolbar"><button onclick="window.print()">Print / Save as PDF</button></div>
    <table class="head" width="100%">
        <tr>
            <td><div class="company">{{ $company }}</div><div class="meta" style="text-align:left">HSSE &amp; Compliance Department</div></td>
            <td class="meta">
                Ref. No.: {{ $ref }}<br>
                Date: {{ $date($issuedAt) }}
            </td>
        </tr>
    </table>

    <div class="doctitle">Vendor HSSE &amp; Work Start Letter</div>

    <div class="to">
        To,<br>
        <strong>{{ $vname }}</strong>{{ $contact ? ' (Attn: '.$contact.')' : '' }}<br>
        {{ $addr ?: 'Address on file' }}<br>
        Vendor Code: {{ $vcode }}
    </div>

    <div class="subject">
        Subject: Approval to Commence Work — Compliance with Health, Safety, Security and Environmental (HSSE) Requirements
    </div>

    <p>Dear {{ $vname }},</p>

    <p>
        We are pleased to inform you that, based on the submission and review of all mandatory documents
        required under the Health, Safety, Security and Environmental (HSSE) protocols, you have been granted
        approval to commence work on the project.
    </p>

    <h2>Documents Reviewed &amp; Approved</h2>
    <ol>
        @foreach ($checklist as [$label, $keys])
            @php $ok = (bool) array_intersect($keys, $approvedSet); @endphp
            <li>
                {{ $label }}
                @if ($ok) <span class="tick">&#10003; Approved</span>
                @else <span class="pending">— to be maintained current</span> @endif
            </li>
        @endforeach
    </ol>

    <h2>Conditions of Work</h2>
    <ol>
        <li><strong>Safety Induction:</strong> All your personnel must complete the mandatory Safety Induction Program before starting work.</li>
        <li><strong>Work Permit:</strong> Ensure the appropriate permits are in place before starting any work activities.</li>
        <li><strong>Compliance Monitoring:</strong> Your work will be subject to continuous monitoring by the site execution and safety teams. Any non-compliance with HSSE requirements may result in penalties.</li>
        <li><strong>Medical &amp; Badging:</strong> Each worker must hold a current medical fitness certificate and an active site entry badge; access is denied automatically when either lapses.</li>
    </ol>

    <p>
        We trust that your team will maintain the highest standards of safety, security and environmental
        protection throughout the course of the work. If you require any further clarification or assistance,
        please contact our Safety Department.
    </p>

    <p>We look forward to a successful and safe collaboration.</p>

    <div class="sign">
        Sincerely,<br>
        <strong>Compliance &amp; HSSE Department</strong><br>
        On behalf of {{ $company }}
    </div>

    <div class="note">
        This letter is system-generated on approval of your onboarding
        (Ref. {{ $ref }}, {{ $date($onboarding->approved_at ?? $issuedAt) }}) and is valid in conjunction with your
        active vendor status. It is superseded if your account is suspended, held, or rejected.
    </div>

    <div class="footer">{{ $company }} · Work Start Letter {{ $ref }} · Generated {{ $date($issuedAt) }}</div>
</body>
</html>
