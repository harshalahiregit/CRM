@extends('emails.layout')

@section('content')
    <h2>Your temporary access has expired</h2>

    <p>Your temporary access to the {{ $companyName }} procurement portal has now
        ended, and you have been signed out.</p>

    {{-- The vendor is already locked out by the time this arrives, so the only
         useful content is what to do about it. --}}
    <p>Nothing you submitted has been lost. To regain access, contact your
        administrator — they can extend the period or make your account
        permanent.</p>

    <p><strong>Vendor code:</strong> {{ $vendor->purchase_vendor_code }}</p>
@endsection
