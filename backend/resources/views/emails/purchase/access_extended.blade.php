@extends('emails.layout')

@section('content')
    <h2>Your temporary access has been extended</h2>

    <p>Your access to the {{ $companyName }} procurement portal now runs to
        <strong>{{ $until }}</strong>.</p>

    {{-- They may already have had a 7-day or 3-day warning about the old date.
         Saying the earlier notice no longer applies stops them planning around
         an expiry that has moved. --}}
    <p>If you had a notice about an earlier end date, it no longer applies. You do
        not need to do anything — your sign-in is unchanged.</p>

    <p><strong>Vendor code:</strong> {{ $vendor->purchase_vendor_code }}</p>
@endsection
