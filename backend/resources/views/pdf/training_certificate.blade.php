@php
    /**
     * Training certificate PDF (L&D Phase 6). Rendered only when no file was
     * uploaded. Read-only view of an issued certificate.
     * @var \App\Models\Hr\HrTrainingCertificate $c
     */
    $brand = '#7C3AED';
    $emp = $c->assignment?->employee;
    $prog = $c->assignment?->program;
    $sess = $c->assignment?->session;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $c->certificate_number }}</title>
    <style>
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; margin: 0; }
        .frame { margin: 26px; border: 3px solid {{ $brand }}; padding: 40px 50px; text-align: center; height: 470px; position: relative; }
        .inner { border: 1px solid #e5e7eb; padding: 30px 40px; height: 405px; }
        .eyebrow { letter-spacing: 6px; font-size: 11px; color: #6b7280; text-transform: uppercase; }
        .title { font-size: 34px; font-weight: bold; color: {{ $brand }}; margin: 6px 0 2px; }
        .sub { font-size: 12px; color: #6b7280; margin-bottom: 26px; }
        .name { font-size: 26px; font-weight: bold; margin: 8px 0; }
        .line { width: 340px; border-bottom: 1px solid #d1d5db; margin: 4px auto 16px; }
        .body { font-size: 13px; color: #374151; }
        .prog { font-size: 17px; font-weight: bold; color: {{ $brand }}; margin: 8px 0; }
        .meta { position: absolute; bottom: 24px; left: 50px; right: 50px; font-size: 10px; color: #6b7280;
                display: flex; justify-content: space-between; }
        .num { font-family: DejaVu Sans Mono, monospace; }
    </style>
</head>
<body>
    <div class="frame">
        <div class="inner">
            <div class="eyebrow">Certificate of Completion</div>
            <div class="title">Learning &amp; Development</div>
            <div class="sub">This is proudly presented to</div>
            <div class="name">{{ $emp?->name ?? 'Employee' }}</div>
            <div class="line"></div>
            <div class="body">for successfully completing the training program</div>
            <div class="prog">{{ $prog?->program_name ?? '—' }}</div>
            <div class="body">
                @if ($sess?->trainer_name) Trainer: {{ $sess->trainer_name }} &middot; @endif
                Issued {{ optional($c->issue_date)->format('d M Y') }}
                @if ($c->expiry_date) &middot; Valid until {{ $c->expiry_date->format('d M Y') }} @endif
            </div>
        </div>
        <div class="meta">
            <span class="num">{{ $c->certificate_number }}</span>
            <span>{{ $prog?->program_code }}</span>
        </div>
    </div>
</body>
</html>
