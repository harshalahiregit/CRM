@extends('emails.layout')

{{--
    The lead, as the agency receives it.

    Written to be acted on without a login and without a reply to us: everything
    the agency needs is on the page, and Reply-To is the vendor, so hitting
    reply reaches them and not our support inbox.
--}}

@section('content')
    <h2>Callback request — {{ $r->document_label }}</h2>

    <p>{{ $r->contact_name }}@if($r->company_name) of <strong>{{ $r->company_name }}</strong>@endif
        has asked to be contacted about <strong>{{ $r->document_label }}</strong>.</p>

    <table cellpadding="6" cellspacing="0" style="border-collapse:collapse;">
        <tr>
            <td><strong>Name</strong></td>
            <td>{{ $r->contact_name }}</td>
        </tr>
        @if($r->company_name)
            <tr>
                <td><strong>Company</strong></td>
                <td>{{ $r->company_name }}</td>
            </tr>
        @endif
        <tr>
            <td><strong>Email</strong></td>
            <td><a href="mailto:{{ $r->contact_email }}">{{ $r->contact_email }}</a></td>
        </tr>
        @if($r->contact_mobile)
            <tr>
                <td><strong>Phone</strong></td>
                <td>{{ $r->contact_mobile }}</td>
            </tr>
        @endif
        <tr>
            <td><strong>Needs</strong></td>
            <td>{{ $r->document_label }}</td>
        </tr>
    </table>

    @if($r->notes)
        <p><strong>Their note:</strong><br>{{ $r->notes }}</p>
    @endif

    <p><strong>Reply to this email to reach them directly</strong>, or call the
        number above. They shared these details on
        {{ $r->consented_at?->format('d M Y') }} so that you could get in touch.</p>
@endsection
