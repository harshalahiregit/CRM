<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; margin: 32px; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #1a1535; }
        .muted { color: #64748b; font-size: 11px; }
        .box { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-top: 16px; }
        table.meta { width: 100%; margin-top: 16px; border-collapse: collapse; }
        table.meta td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; }
        table.meta td:first-child { color: #64748b; width: 160px; }
        .page { margin-top: 20px; page-break-inside: avoid; }
        .page h2 { font-size: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
        .sig { margin-top: 32px; border-top: 1px solid #e2e8f0; padding-top: 16px; }
        .sig img { max-height: 80px; }
        .stamp { margin-top: 12px; display: inline-block; border: 2px solid #7C3AED; color: #7C3AED; border-radius: 8px; padding: 8px 16px; font-weight: bold; transform: rotate(-4deg); }
        .audit { font-size: 10px; color: #94a3b8; margin-top: 6px; }
        .portal { margin-top: 24px; font-size: 10px; color: #64748b; word-break: break-all; }
    </style>
</head>
<body>
    <h1>{{ $contract->subject }}</h1>
    <p class="muted">{{ $contract->reference_no }} @if($contract->version > 1) · Version {{ $contract->version }} @endif</p>

    <table class="meta">
        <tr><td>Client</td><td>{{ $contract->client?->company ?? '—' }}</td></tr>
        <tr><td>Contract Type</td><td>{{ $contract->type?->name ?? '—' }}</td></tr>
        <tr><td>Value</td><td>{{ $contract->currency ?? 'INR' }} {{ number_format($contract->value ?? 0, 2) }}</td></tr>
        <tr><td>Period</td><td>{{ optional($contract->start_date)->format('d M Y') ?? '—' }} — {{ optional($contract->end_date)->format('d M Y') ?? 'open-ended' }}</td></tr>
        <tr><td>Status</td><td>{{ ucfirst($contract->status) }}</td></tr>
    </table>

    @if($contract->description)
        <div class="box">{{ $contract->description }}</div>
    @endif

    @if($contract->relationLoaded('pages'))
        @foreach($contract->pages as $pg)
            <div class="page">
                <h2>{{ $pg->title }}</h2>
                {!! $pg->content !!}
            </div>
        @endforeach
    @endif

    <div class="sig">
        @php $sig = $contract->signature_data ? json_decode($contract->signature_data, true) : null; @endphp
        @if($sig)
            <strong>Signed by {{ $sig['name'] ?? $contract->signed_by_name }}</strong><br>
            @if(!empty($sig['image']))<img src="{{ $sig['image'] }}" alt="signature">@endif
            <p class="audit">
                Signed {{ optional($contract->signed_at)->format('d M Y H:i') }}
                @if(!empty($sig['ip'])) · IP {{ $sig['ip'] }} @endif
                @if(!empty($sig['method'])) · method: {{ $sig['method'] }} @endif
            </p>
        @else
            <strong>Signature</strong>
            <p class="muted">This contract has not been signed yet.</p>
        @endif
        <div class="stamp">{{ config('app.name', 'Sangoe CRM') }}</div>
    </div>

    @if($contract->public_token)
        <p class="portal">Review &amp; verify online: {{ rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/') }}/portal/contracts/{{ $contract->public_token }}</p>
    @endif
</body>
</html>
