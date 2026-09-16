@extends('emails.layout')

{{--
    The vendor's own copy.

    They have just handed their contact details to an outside company, so they
    get to see exactly what was sent and to whom — and if the call never comes,
    they have the address to chase without asking us for it.
--}}

@section('content')
    <h2>Your request has been sent to {{ $r->provider_name }}</h2>

    <p>We have passed your request for help with
        <strong>{{ $r->document_label }}</strong> to {{ $r->provider_name }}.
        They will contact you directly.</p>

    <p><strong>This is what we sent them:</strong></p>

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
            <td>{{ $r->contact_email }}</td>
        </tr>
        @if($r->contact_mobile)
            <tr>
                <td><strong>Phone</strong></td>
                <td>{{ $r->contact_mobile }}</td>
            </tr>
        @endif
        @if($r->notes)
            <tr>
                <td><strong>Your note</strong></td>
                <td>{{ $r->notes }}</td>
            </tr>
        @endif
    </table>

    <p>Anything you agree with {{ $r->provider_name }} is between you and them —
        we are not party to it, and we do not see what you discuss. If they do not
        get in touch, you can reach them at
        <a href="mailto:{{ $r->provider_email }}">{{ $r->provider_email }}</a>.</p>

    <p>Once you hold the document, upload it in your portal under
        <strong>Documents</strong> and it will go for review as normal.</p>
@endsection
