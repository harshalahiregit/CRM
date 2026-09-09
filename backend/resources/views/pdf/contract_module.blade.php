{{--
    The contract as it is signed, printed and filed.

    Own template, not the Sales one: this module's contract has two signature
    blocks, an audit trail per signer and the authenticity marks, none of which
    the Sales template knows about. Sharing it would have meant changing a
    document another module already issues.

    DomPDF notes: no flexbox and no grid, so the layout is tables. `page-break-
    inside: avoid` keeps a signature block from splitting across two pages, which
    is the one break that makes a signed document look tampered with.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 100px 40px 90px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2430; line-height: 1.55; }

        header { position: fixed; top: -70px; left: 0; right: 0; height: 55px; }
        footer { position: fixed; bottom: -68px; left: 0; right: 0; height: 60px;
                 border-top: 1px solid #d8dce4; padding-top: 6px; }

        .muted { color: #6b7280; }
        .tiny  { font-size: 8.5px; }
        h1 { font-size: 17px; margin: 0 0 2px; }
        h2 { font-size: 12.5px; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #e5e7eb; }

        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .meta .k { color: #6b7280; width: 130px; }

        .page-break { page-break-before: always; }
        .sig-wrap { page-break-inside: avoid; }
        .sig-box { border: 1px solid #d8dce4; border-radius: 6px; padding: 10px; vertical-align: top; width: 50%; }
        .sig-img { max-height: 52px; max-width: 200px; }
        .sig-line { border-bottom: 1px solid #9aa2b1; height: 34px; }
        .typed { font-family: DejaVu Sans, sans-serif; font-style: italic; font-size: 20px; padding-top: 6px; }
        .stamp { border: 2px solid #1f4ed8; color: #1f4ed8; border-radius: 6px;
                 padding: 8px 10px; text-align: center; font-weight: bold; font-size: 10px; }
        .pending { color: #b45309; font-weight: bold; }

        /* The rich editor emits these; without sizes they print at browser
           defaults, which are far too large for a contract page. */
        .rich h1, .rich h2, .rich h3 { font-size: 13px; margin: 10px 0 5px; }
        .rich p { margin: 0 0 7px; }
        .rich ul, .rich ol { margin: 0 0 8px; padding-left: 18px; }
        .rich li { margin-bottom: 3px; }
        .rich table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        .rich table td, .rich table th { border: 1px solid #d8dce4; padding: 5px 7px; }
        .rich img { max-width: 100%; }
        .rich blockquote { margin: 8px 0; padding-left: 10px; border-left: 3px solid #d8dce4; color: #4b5563; }
    </style>
</head>
<body>

<header>
    <table>
        <tr>
            <td style="vertical-align:middle">
                @if($logo)
                    <img src="{{ $logo }}" style="max-height:38px">
                @else
                    <strong style="font-size:14px">{{ config('app.name') }}</strong>
                @endif
            </td>
            <td style="text-align:right; vertical-align:middle" class="tiny muted">
                {{ $contract->reference_no }}<br>
                {{ $contract->category?->name }}
            </td>
        </tr>
    </table>
</header>

{{-- The authenticity marks sit in the footer so they repeat on EVERY page.
     A verification code on the last page only proves the last page. --}}
<footer>
    <table>
        <tr>
            <td style="width:70px"><img src="{{ $qr }}" style="width:56px;height:56px"></td>
            <td class="tiny muted" style="vertical-align:middle">
                Verify this document at<br>{{ $verify_url }}<br>
                Generated {{ $generated_at->format('d M Y H:i') }}
            </td>
            <td style="text-align:right; vertical-align:middle">
                <img src="{{ $barcode }}" style="height:26px"><br>
                <span class="tiny muted">{{ $contract->reference_no }}</span>
            </td>
        </tr>
    </table>
</footer>

<main>
    <h1>{{ $contract->title }}</h1>
    <div class="muted" style="margin-bottom:12px">
        {{ $contract->category?->name ?? 'Contract' }} · {{ $contract->reference_no }}
    </div>

    <table class="meta">
        <tr>
            <td class="k">Between</td>
            <td><strong>{{ config('app.name') }}</strong></td>
        </tr>
        <tr>
            <td class="k">And</td>
            <td><strong>{{ $contract->party_name ?? '—' }}</strong>
                @if($contract->party_email)<br><span class="muted">{{ $contract->party_email }}</span>@endif
            </td>
        </tr>
        @if($contract->value)
            <tr>
                <td class="k">Contract value</td>
                <td>{{ $contract->currency }} {{ number_format((float) $contract->value, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td class="k">Term</td>
            <td>
                {{ optional($contract->start_date)->format('d M Y') ?? '—' }}
                to
                {{ optional($contract->end_date)->format('d M Y') ?? 'open-ended' }}
            </td>
        </tr>
        <tr>
            <td class="k">Status</td>
            <td>{{ ucfirst($contract->status) }}</td>
        </tr>
    </table>

    @if($contract->description)
        <h2>Overview</h2>
        {{-- Rich HTML from the editor, already run through the allowlist
             sanitizer on save. e() would print the tags as literal text. --}}
        <div class="rich">{!! $contract->description !!}</div>
    @endif

    {{-- The long form. Each page starts a new printed page so the clause
         numbering people quote matches what they are holding. --}}
    @foreach($contract->pages as $i => $page)
        <div class="{{ $i === 0 ? '' : 'page-break' }}">
            <h2>{{ $page->title ?: 'Terms and Conditions' }}</h2>
            <div class="rich">{!! $page->content !!}</div>
        </div>
    @endforeach

    {{-- ── Signatures ────────────────────────────────────────────────── --}}
    <div class="page-break"></div>
    <h2>Signatures</h2>
    <p class="muted tiny" style="margin-top:0">
        This agreement requires the signature of both parties. Each signature below is
        recorded with the time it was given, the network address it was given from and,
        where the signer permitted it, their location.
    </p>

    <table class="sig-wrap" style="margin-top:10px">
        <tr>
            @foreach(['party', 'company'] as $key)
                @php $sig = $signatures[$key] ?? null; @endphp
                <td class="sig-box" style="{{ $loop->first ? 'margin-right:8px' : '' }}">
                    <div class="tiny muted" style="text-transform:uppercase; letter-spacing:.05em">
                        {{ $key === 'party' ? 'Customer / Vendor' : 'For ' . config('app.name') }}
                    </div>

                    @if($sig && $sig->signed_at)
                        @if($sig->method === 'type')
                            <div class="typed">{{ $sig->signer_name }}</div>
                        @elseif($sig->method === 'stamp')
                            <div class="stamp" style="margin:8px 0">
                                {{ config('app.name') }}<br>AUTHORISED SIGNATORY
                            </div>
                        @elseif($sig->image)
                            <div style="margin:6px 0"><img src="{{ $sig->image }}" class="sig-img"></div>
                        @else
                            <div class="sig-line"></div>
                        @endif

                        <div style="margin-top:6px"><strong>{{ $sig->signer_name }}</strong></div>
                        @if($sig->signer_email)<div class="tiny muted">{{ $sig->signer_email }}</div>@endif

                        <div class="tiny muted" style="margin-top:8px; line-height:1.5">
                            Signed {{ $sig->signed_at->format('d M Y, H:i') }}<br>
                            @if($sig->viewed_at)
                                Document first opened {{ $sig->viewed_at->format('d M Y, H:i') }}<br>
                            @endif
                            IP {{ $sig->signed_ip ?? 'not recorded' }}<br>
                            Location {{ $sig->place ?? 'not provided' }}<br>
                            @if($sig->certificate_no)
                                Certificate <strong>{{ $sig->certificate_no }}</strong>
                            @endif
                        </div>
                    @else
                        <div class="sig-line" style="margin-top:14px"></div>
                        <div class="pending tiny" style="margin-top:6px">Awaiting signature</div>
                        <div class="tiny muted" style="margin-top:22px">&nbsp;</div>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>

    {{-- ── Issuance certificate ──────────────────────────────────────── --}}
    @if($contract->fully_signed_at)
        <div class="sig-wrap" style="margin-top:16px; border:1px solid #16a34a; border-radius:6px; padding:10px">
            <strong style="color:#15803d">Certificate of Issuance</strong>
            <p class="tiny" style="margin:6px 0 0">
                This is to certify that contract <strong>{{ $contract->reference_no }}</strong> was executed
                by both parties and completed on
                <strong>{{ $contract->fully_signed_at->format('d M Y \a\t H:i') }}</strong>.
                Its authenticity can be verified at {{ $verify_url }} or by scanning the code on any page.
            </p>
            <table class="tiny" style="margin-top:8px">
                @foreach($signatures as $sig)
                    @if($sig->signed_at)
                        <tr>
                            <td style="width:170px" class="muted">{{ $sig->party_label }}</td>
                            <td>{{ $sig->signer_name }}</td>
                            <td class="muted">{{ $sig->certificate_no }}</td>
                        </tr>
                    @endif
                @endforeach
            </table>
        </div>
    @endif
</main>

</body>
</html>
