@extends('emails.layout')

@section('content')
    <h2>You are now a permanent vendor</h2>

    <p>Your account with {{ $companyName }} has been converted from a temporary
        engagement to a <strong>permanent vendor account</strong>.</p>

    {{-- The countdown is the thing they have been watching, so its disappearance
         is the news. Saying only "your account was updated" would leave them
         guessing whether the expiry still applies. --}}
    <p>The temporary access period no longer applies. Your portal will not expire,
        and you do not need to do anything to keep it.</p>

    <p><strong>Vendor code:</strong> {{ $vendor->purchase_vendor_code }}</p>

    @if($vendor->company_name)
        <p><strong>Registered as:</strong> {{ $vendor->company_name }}</p>
    @endif

    {{-- Promotion raises the paperwork: three documents become eleven. Without
         this, the vendor's portal drops from complete to 18% overnight with
         nothing asking them for the difference, and they are non-compliant on
         documents nobody has requested. --}}
    @if(!empty($newlyRequired))
        <p>One thing does change. A permanent account is asked for more paperwork
            than a temporary one, so the following
            {{ count($newlyRequired) === 1 ? 'document is' : 'documents are' }} now
            required and we do not have
            {{ count($newlyRequired) === 1 ? 'it' : 'them' }} yet:</p>

        <ul>
            @foreach($newlyRequired as $doc)
                <li>{{ $doc }}</li>
            @endforeach
        </ul>

        <p>Please upload {{ count($newlyRequired) === 1 ? 'it' : 'them' }} in your
            portal under <strong>Documents</strong>. Nothing you filed before needs
            redoing — the requirement changed, not your file.</p>
    @endif

    <p>You can sign in to the procurement portal exactly as before, with the same
        credentials.</p>
@endsection
