{{-- Medical fitness certificate / prescription.
     Everything that makes the document verifiable sits on its face: licence,
     signature, camera capture, IP, place, barcode and QR. Laid out with tables
     because dompdf's flex support is not reliable enough to trust a legal
     document to. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22px 26px; }
        body  { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; }
        h1, h2, h3 { margin: 0; }
        .muted { color: #6b7280; }
        .head  { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 10px; }
        .head h1 { font-size: 15px; letter-spacing: .4px; }
        .title { text-align: center; font-size: 13px; font-weight: bold; letter-spacing: 1px;
                 text-transform: uppercase; margin: 12px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        .grid td { vertical-align: top; padding: 3px 6px 3px 0; }
        .label { color: #6b7280; width: 96px; }
        .box   { border: 1px solid #d1d5db; border-radius: 4px; padding: 7px 9px; margin-bottom: 8px; }
        .box h3 { font-size: 10px; text-transform: uppercase; letter-spacing: .6px;
                  color: #374151; margin-bottom: 5px; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; }
        .verdict { font-size: 13px; font-weight: bold; }
        .fit    { color: #047857; }
        .unfit  { color: #b91c1c; }
        .score  { font-size: 20px; font-weight: bold; }
        .sig    { height: 46px; }
        .capture{ height: 74px; border: 1px solid #e5e7eb; }
        .foot   { margin-top: 10px; border-top: 1px solid #e5e7eb; padding-top: 6px; font-size: 8.5px; color: #6b7280; }
        .stamp  { font-size: 8.5px; color: #6b7280; line-height: 1.45; }
    </style>
</head>
<body>

{{-- Letterhead + the certificate number, which is the document's identity --}}
<table class="head">
    <tr>
        <td>
            <h1>{{ $company['name'] }}</h1>
            @if($company['address'])<div class="muted">{{ $company['address'] }}</div>@endif
            @if($company['email'])<div class="muted">{{ $company['email'] }}</div>@endif
        </td>
        <td style="text-align:right; width: 210px;">
            @if($barcode)<img src="{{ $barcode }}" style="height:34px;">@endif
            <div style="font-weight:bold; letter-spacing:.5px;">{{ $medical->certificate_no }}</div>
        </td>
    </tr>
</table>

<div class="title">Medical Fitness Certificate</div>
<div style="text-align:center;" class="muted">
    {{ $medical->is_reexam ? 'Re-examination' : 'Examination' }}
    &middot; attempt {{ $medical->attempt_no }}
    &middot; {{ $medical->origin_label }}
</div>

{{-- Who was examined --}}
<div class="box" style="margin-top:10px;">
    <h3>Worker</h3>
    <table class="grid">
        <tr>
            <td class="label">Name</td><td><strong>{{ $subject['worker_name'] ?? '—' }}</strong></td>
            <td class="label">Worker code</td><td>{{ $subject['worker_code'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Vendor</td><td>{{ $subject['vendor_name'] ?? '—' }}</td>
            <td class="label">Designation</td><td>{{ $subject['designation'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Date of birth</td><td>{{ $subject['dob'] ?? '—' }}</td>
            <td class="label">Gender</td><td>{{ $subject['gender'] ?? '—' }}</td>
        </tr>
    </table>
</div>

{{-- What was measured --}}
<div class="box">
    <h3>Examination — {{ optional($medical->exam_date)->format('d M Y') }}</h3>
    <table class="grid">
        <tr>
            <td class="label">Height</td><td>{{ $medical->height_cm ? $medical->height_cm.' cm' : '—' }}</td>
            <td class="label">Weight</td><td>{{ $medical->weight_kg ? $medical->weight_kg.' kg' : '—' }}</td>
            <td class="label">BMI</td><td>{{ $medical->bmi ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Blood pressure</td>
            <td>{{ $medical->bp_systolic ? $medical->bp_systolic.'/'.$medical->bp_diastolic.' mmHg' : '—' }}</td>
            <td class="label">Pulse</td><td>{{ $medical->pulse_bpm ? $medical->pulse_bpm.' bpm' : '—' }}</td>
            <td class="label">SpO₂</td><td>{{ $medical->spo2 ? $medical->spo2.'%' : '—' }}</td>
        </tr>
        <tr>
            <td class="label">Temperature</td><td>{{ $medical->temperature_c ? $medical->temperature_c.' °C' : '—' }}</td>
            <td class="label">Resp. rate</td><td>{{ $medical->respiratory_rate ?: '—' }}</td>
            <td class="label">Blood group</td><td>{{ $medical->blood_group ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Vision (L/R)</td>
            <td>{{ $medical->vision_left ?: '—' }} / {{ $medical->vision_right ?: '—' }}</td>
            <td class="label">Colour vision</td><td>{{ $medical->colour_vision ?: '—' }}</td>
            <td class="label">Hearing</td><td>{{ $medical->hearing ?: '—' }}</td>
        </tr>
    </table>
</div>

@if(!empty($medical->investigations))
<div class="box">
    <h3>Investigations</h3>
    <table class="grid">
        @foreach($medical->investigations as $item)
            <tr>
                <td style="width:150px;">{{ $item['name'] ?? '—' }}</td>
                <td style="width:120px;"><strong>{{ $item['result'] ?? '—' }}</strong></td>
                <td class="muted">{{ $item['remarks'] ?? '' }}</td>
            </tr>
        @endforeach
    </table>
</div>
@endif

@if($medical->allergies || $medical->current_medication || !empty($medical->medical_history['conditions']))
<div class="box">
    <h3>Declared history</h3>
    @if(!empty($medical->medical_history['conditions']))
        <div><span class="muted">Conditions:</span> {{ implode(', ', (array) $medical->medical_history['conditions']) }}</div>
    @endif
    @if($medical->allergies)<div><span class="muted">Allergies:</span> {{ $medical->allergies }}</div>@endif
    @if($medical->current_medication)<div><span class="muted">Medication:</span> {{ $medical->current_medication }}</div>@endif
</div>
@endif

{{-- The verdict, and the score that goes onto the worker's profile --}}
<div class="box">
    <h3>Opinion</h3>
    <table class="grid">
        <tr>
            <td style="width:62%;">
                <div class="verdict {{ $medical->is_passing ?? $medical->isPassing() ? 'fit' : 'unfit' }}">
                    {{ $medical->fitness_label }}
                </div>
                @if($medical->restrictions)
                    <div><span class="muted">Restrictions:</span> {{ $medical->restrictions }}</div>
                @endif
                @if($medical->doctor_remarks)
                    <div><span class="muted">Remarks:</span> {{ $medical->doctor_remarks }}</div>
                @endif
                <div class="muted" style="margin-top:4px;">
                    Valid until <strong>{{ optional($medical->valid_until ?? $medical->expiry_date)->format('d M Y') ?: '—' }}</strong>
                </div>
            </td>
            <td style="text-align:center;">
                <div class="muted">Health score</div>
                <div class="score">{{ $medical->health_score ?? '—' }}<span style="font-size:11px;">/{{ $scoreScale }}</span></div>
                <div class="muted">{{ $healthBand }}</div>
            </td>
        </tr>
    </table>
</div>

{{-- Who signed it, and the proof that ties the signature to a time and place --}}
<table>
    <tr>
        <td style="width:56%; vertical-align:top; padding-right:10px;">
            <div class="box" style="min-height:118px;">
                <h3>Examining doctor</h3>
                <div><strong>{{ $medical->examiner_name ?: '—' }}</strong>
                    @if($medical->doctor_qualification) <span class="muted">{{ $medical->doctor_qualification }}</span>@endif
                </div>
                <div><span class="muted">Licence no:</span> <strong>{{ $medical->doctor_license_no ?: '—' }}</strong></div>
                @if($medical->doctor_council)<div><span class="muted">Council:</span> {{ $medical->doctor_council }}</div>@endif
                @if($medical->clinic_name)<div><span class="muted">Clinic:</span> {{ $medical->clinic_name }}</div>@endif
                @if($signature)
                    <img class="sig" src="{{ $signature }}" alt="signature">
                    <div class="muted" style="border-top:1px solid #d1d5db; width:150px;">Signature</div>
                @endif
            </div>
        </td>
        <td style="vertical-align:top;">
            <div class="box" style="min-height:118px;">
                <h3>Capture</h3>
                <table>
                    <tr>
                        <td>@if($capture)<img class="capture" src="{{ $capture }}" alt="capture">@else<span class="muted">No photo</span>@endif</td>
                        <td style="text-align:right;">@if($qr)<img src="{{ $qr }}" style="height:74px;">@endif</td>
                    </tr>
                </table>
                <div class="stamp" style="margin-top:5px;">
                    IP {{ $medical->system_ip ?: '—' }}<br>
                    @if($medical->geo_place)Place: {{ $medical->geo_place }}<br>@endif
                    @if($medical->geo_location)Coordinates: {{ $medical->geo_location }}<br>@endif
                    Signed {{ optional($medical->created_at)->format('d M Y H:i') }}
                </div>
            </div>
        </td>
    </tr>
</table>

<div class="foot">
    Verify this certificate at {{ $verifyUrl }} — or scan the QR above.
    @if($mapUrl) Location: {{ $mapUrl }} @endif
    <br>
    This certificate is issued on the basis of the examination recorded above and is valid only for the period stated.
</div>

</body>
</html>
