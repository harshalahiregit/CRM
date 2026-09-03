@php
    /**
     * Purchase Kickoff Minutes-of-Meeting PDF. Every value is resolved from
     * existing meeting data. Rendered by PurchaseKickoffService::generateMom().
     *
     * Kept structurally in step with pdf/kickoff_mom.blade.php (the shared/TPV
     * engine) — same sections, same attendance semantics — because the two
     * engines are separate by design but must produce the same document.
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

    /**
     * The recorded state, not the boolean projection of it. `attended` is still
     * the fallback for rows marked before attendance_status existed; NULL there
     * means "not marked yet", which must not print as Absent — the document
     * would otherwise assert someone missed a meeting nobody ever marked.
     */
    $statusOf = fn ($a) => $a->attendance_status ?: ($a->attended ? 'Present' : null);
    $pillOf   = [
        'Present' => 'present', 'Late' => 'late', 'Absent' => 'absent',
        'Excused' => 'excused', 'Online' => 'online', 'Offline' => 'offline',
    ];

    $present = $attendees->filter(fn ($a) => $a->attended)->count();

    // Breakdown printed under the heading, so Late / Excused / Online / Offline
    // are visible on the document instead of collapsing into Present / Absent.
    $breakdown = collect(\App\Models\Purchase\PurchaseKickoffParticipant::ATTENDANCE)
        ->map(fn ($s) => [$s, $attendees->filter(fn ($a) => $statusOf($a) === $s)->count()])
        ->filter(fn ($p) => $p[1] > 0)
        ->map(fn ($p) => $p[1] . ' ' . $p[0])
        ->values()
        ->all();
    $unmarked = $attendees->filter(fn ($a) => $statusOf($a) === null)->count();
    if ($unmarked > 0) {
        $breakdown[] = $unmarked . ' not marked';
    }

    // Structured minutes (Meeting.docx §§3, 8, 9, 10). Each section prints only
    // when it has rows; the free-text agenda/minutes below stay exactly as they
    // were, so a meeting minuted as prose still renders in full. Sorted here
    // because these relations carry no default order.
    $sorted      = fn ($rel) => ($rel ?? collect())->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
    $agendaItems = $sorted($meeting->agendaItems);
    $actions     = $sorted($meeting->actionItems);
    $decisions   = $sorted($meeting->momDecisions);
    $issues      = $sorted($meeting->momIssues);

    $ack = $meeting->acknowledged_at
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
        body { font-family: DejaVu Sans, sans-serif; font-size: 11.5px; color: #1f2937; line-height: 1.55; word-wrap: break-word; }
        /* Break long unbroken strings so they wrap in the PDF instead of overflowing. */
        td, th, p, div, li, span { word-wrap: break-word; overflow-wrap: break-word; }
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
        table.att td { padding: 6px 9px; border-bottom: 1px solid #eef0f2; font-size: 10.5px; vertical-align: top; }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 9.5px; font-weight: bold; }
        .present  { background: #ecfdf5; color: #065f46; }
        .absent   { background: #fef2f2; color: #991b1b; }
        .late     { background: #fffbeb; color: #92400e; }
        .excused  { background: #f5f3ff; color: #5b21b6; }
        .online   { background: #eff6ff; color: #1e40af; }
        .offline  { background: #f8fafc; color: #334155; }
        .unmarked { background: #f3f4f6; color: #6b7280; }
        .sub { font-size: 9.5px; color: #6b7280; }
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

<h2>Participants &amp; Attendance ({{ $present }}/{{ $attendees->count() }} attended)</h2>
@if ($attendees->count())
    @if (count($breakdown))
        <p class="sub" style="margin:0 0 6px;">{{ implode(' · ', $breakdown) }}</p>
    @endif
    <table class="att">
        <thead>
            <tr><th style="width:32%">Name</th><th style="width:22%">Role</th><th style="width:28%">Organisation</th><th style="width:18%">Attendance</th></tr>
        </thead>
        <tbody>
            @foreach ($attendees as $a)
                @php $st = $statusOf($a); @endphp
                <tr>
                    <td>
                        {{ $a->name }}
                        @if ($a->designation)<div class="sub">{{ $a->designation }}</div>@endif
                    </td>
                    <td>{{ $a->role ?: '—' }}</td>
                    <td>{{ $a->organisation ?: '—' }}</td>
                    <td>
                        @if ($st)
                            <span class="pill {{ $pillOf[$st] ?? 'unmarked' }}">{{ $st }}</span>
                        @else
                            <span class="pill unmarked">Not marked</span>
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
@if ($agendaItems->count())
    <table class="att">
        <thead>
            <tr><th style="width:5%">#</th><th style="width:47%">Agenda Item</th><th style="width:24%">Owner</th><th style="width:12%">Duration</th><th style="width:12%">Priority</th></tr>
        </thead>
        <tbody>
            @foreach ($agendaItems as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        {{ $item->item }}
                        @if ($item->description)<div class="sub">{{ $item->description }}</div>@endif
                        @if ($item->discussion)<div class="sub"><strong>Discussion:</strong> {{ $item->discussion }}</div>@endif
                        @if ($item->decision)<div class="sub"><strong>Decision:</strong> {{ $item->decision }}</div>@endif
                    </td>
                    <td>{{ $item->owner?->name ?: ($item->owner_names ?: '—') }}</td>
                    <td>{{ $item->duration_minutes ? $item->duration_minutes . ' min' : '—' }}</td>
                    <td>{{ $item->priority ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
@if ($meeting->agenda)
    <p class="prose">{{ $meeting->agenda }}</p>
@elseif (! $agendaItems->count())
    <p class="prose">—</p>
@endif

<h2>Minutes</h2>
<p class="prose">{{ $meeting->minutes ?: ($actions->count() || $decisions->count() || $issues->count()
    ? 'See the decisions, action items and issues recorded below.'
    : 'No minutes have been recorded.') }}</p>

@if ($decisions->count())
    <h2>Decisions ({{ $decisions->count() }})</h2>
    <table class="att">
        <thead>
            <tr><th style="width:13%">Ref</th><th style="width:37%">Decision</th><th style="width:18%">Decided By</th><th style="width:20%">Impact</th><th style="width:12%">Effective</th></tr>
        </thead>
        <tbody>
            @foreach ($decisions as $d)
                <tr>
                    <td>{{ $d->decision_ref ?: '—' }}</td>
                    <td>{{ $d->decision }}</td>
                    <td>{{ $d->decidedBy?->name ?: ($d->decided_by_names ?: '—') }}</td>
                    <td>{{ $d->impact ?: '—' }}</td>
                    <td>{{ $d->effective_date ? $day($d->effective_date) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($actions->count())
    <h2>Action Items ({{ $actions->count() }})</h2>
    <table class="att">
        <thead>
            <tr><th style="width:13%">Ref</th><th style="width:37%">Action</th><th style="width:20%">Responsible</th><th style="width:13%">Target Date</th><th style="width:17%">Status</th></tr>
        </thead>
        <tbody>
            @foreach ($actions as $a)
                <tr>
                    <td>{{ $a->action_ref ?: '—' }}</td>
                    <td>
                        {{ $a->description }}
                        @if ($a->remark)<div class="sub">{{ $a->remark }}</div>@endif
                    </td>
                    <td>
                        {{ $a->responsible?->name ?: ($a->responsible_names ?: '—') }}
                        @if ($a->responsible_org)<div class="sub">{{ $a->responsible_org }}</div>@endif
                    </td>
                    <td>{{ $a->target_date ? $day($a->target_date) : '—' }}</td>
                    <td>
                        {{ $a->status_label }}
                        @if ($a->priority)<div class="sub">{{ $a->priority }} priority</div>@endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($issues->count())
    <h2>Issues Raised ({{ $issues->count() }})</h2>
    <table class="att">
        <thead>
            <tr><th style="width:13%">Ref</th><th style="width:33%">Issue</th><th style="width:16%">Category</th><th style="width:11%">Severity</th><th style="width:16%">Owner</th><th style="width:11%">Due</th></tr>
        </thead>
        <tbody>
            @foreach ($issues as $i)
                <tr>
                    <td>{{ $i->issue_ref ?: '—' }}</td>
                    <td>
                        {{ $i->title }}
                        @if ($i->description)<div class="sub">{{ $i->description }}</div>@endif
                        <div class="sub">Status: {{ $i->status_label }}@if ($i->is_converted) · Converted to {{ $i->converted_to }} {{ $i->converted_ref }}@endif</div>
                    </td>
                    <td>{{ $i->category ?: '—' }}</td>
                    <td>{{ $i->severity ?: '—' }}</td>
                    <td>{{ $i->owner?->name ?: ($i->owner_names ?: '—') }}</td>
                    <td>{{ $i->due_date ? $day($i->due_date) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

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
