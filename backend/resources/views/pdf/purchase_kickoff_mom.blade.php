@php
    /**
     * Purchase Kickoff Minutes-of-Meeting PDF. Every value is resolved from
     * existing meeting data. Rendered by PurchaseKickoffService::generateMom().
     *
     * @var \App\Models\Purchase\PurchaseKickoffMeeting $meeting
     */
    $company  = $tenant->name ?? config('app.name');
    $brand    = $tenant->branding_color ?? '#7C3AED';
    $logo     = $tenant->logo_url ?? null;
    $meetingNo = 'PKO-' . str_pad((string) $meeting->id, 4, '0', STR_PAD_LEFT);

    $fmt   = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y, g:i A') : '—';
    $day   = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
    $mode  = ['online' => 'Online', 'onsite' => 'On site'][$meeting->mode] ?? ($meeting->mode ?: '—');

    $attendees = $meeting->participants ?? collect();
    $present   = $attendees->where('attended', true)->count();
    $ack       = $meeting->acknowledged_at
        ? 'Acknowledged by ' . $meeting->acknowledged_by_name . ' on ' . $day($meeting->acknowledged_at)
        : 'Pending acknowledgement';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $meetingNo }} — Minutes of Meeting</title>
    <style>
        @page { margin: 34px 40px 56px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11.5px; color: #1f2937; line-height: 1.55; }
        .head { border-bottom: 3px solid {{ $brand }}; padding-bottom: 12px; margin-bottom: 18px; }
        .head td { vertical-align: middle; }
        .company { font-size: 19px; font-weight: bold; color: {{ $brand }}; }
        .muted { color: #6b7280; }
        .meta { text-align: right; font-size: 10.5px; }
        .doctitle { font-size: 15px; font-weight: bold; color: {{ $brand }}; text-transform: uppercase; letter-spacing: .5px; margin: 4px 0 2px; }
        h2 { font-size: 12.5px; margin: 18px 0 7px; color: {{ $brand }}; text-transform: uppercase; letter-spacing: .4px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        table.kv { width: 100%; border-collapse: collapse; margin: 4px 0 6px; }
        table.kv td { padding: 6px 9px; border: 1px solid #e5e7eb; font-size: 11px; }
        table.kv td.k { background: #f9fafb; font-weight: bold; width: 24%; color: #374151; }
        table.att { width: 100%; border-collapse: collapse; margin: 4px 0 6px; }
        table.att th { background: {{ $brand }}; color: #fff; font-size: 10px; text-align: left; padding: 6px 9px; text-transform: uppercase; letter-spacing: .3px; }
        table.att td { padding: 6px 9px; border-bottom: 1px solid #eef0f2; font-size: 10.5px; }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 9.5px; font-weight: bold; }
        .present { background: #ecfdf5; color: #065f46; }
        .absent  { background: #fef2f2; color: #991b1b; }
        .prose { font-size: 11px; white-space: pre-wrap; margin: 2px 0 0; }
        .footer { position: fixed; bottom: -34px; left: 0; right: 0; font-size: 9px; color: #9ca3af;
                  border-top: 1px solid #e5e7eb; padding-top: 6px; }
        .footer td { font-size: 9px; color: #9ca3af; }
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
            <div class="doctitle">Minutes of Meeting</div>
            <div><strong>Meeting ID:</strong> {{ $meetingNo }}</div>
            <div class="muted">{{ $mode }}</div>
        </td>
    </tr>
</table>

<div style="font-size:16px; font-weight:bold; color:#111827;">{{ $meeting->title }}</div>
@if ($vendorName)
    <div class="muted" style="margin-top:2px;">Vendor: <strong style="color:#374151;">{{ $vendorName }}</strong></div>
@endif

<h2>Meeting Details</h2>
<table class="kv">
    <tr><td class="k">Meeting ID</td><td>{{ $meetingNo }}</td><td class="k">Status</td><td>{{ $meeting->status }}</td></tr>
    <tr><td class="k">Meeting Date</td><td>{{ $fmt($meeting->scheduled_at) }}</td><td class="k">Meeting Mode</td><td>{{ $mode }}</td></tr>
    <tr><td class="k">Vendor</td><td>{{ $vendorName ?: '—' }}</td><td class="k">{{ $meeting->mode === 'online' ? 'Meeting Link' : 'Location' }}</td><td>{{ $meeting->location ?: '—' }}</td></tr>
</table>

<h2>Participants &amp; Attendance ({{ $present }}/{{ $attendees->count() }} present)</h2>
@if ($attendees->count())
    <table class="att">
        <thead>
            <tr><th style="width:34%">Name</th><th style="width:24%">Role</th><th style="width:24%">Organisation</th><th style="width:18%">Attendance</th></tr>
        </thead>
        <tbody>
            @foreach ($attendees as $a)
                <tr>
                    <td>{{ $a->name }}</td>
                    <td>{{ $a->role ?: '—' }}</td>
                    <td>{{ $a->organisation ?: '—' }}</td>
                    <td>
                        @if ($a->attended)
                            <span class="pill present">Present</span>
                        @else
                            <span class="pill absent">Absent</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No participants recorded.</p>
@endif

<h2>Agenda</h2>
<p class="prose">{{ $meeting->agenda ?: '—' }}</p>

<h2>Minutes</h2>
<p class="prose">{{ $meeting->minutes ?: 'No minutes have been recorded.' }}</p>

<h2>Acknowledgement</h2>
<p class="prose">{{ $ack }}</p>

<table class="footer" width="100%">
    <tr>
        <td>Generated by {{ $generatedBy ?: '—' }} · {{ $fmt($generatedAt) }}</td>
        <td style="text-align:right;">{{ $company }} · {{ $meetingNo }}</td>
    </tr>
</table>

</body>
</html>
