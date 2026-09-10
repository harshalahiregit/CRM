@extends('emails.layout')

@section('content')
    <h2>Your temporary access ends in {{ $when }}</h2>

    <p>Your access to the {{ $companyName }} procurement portal was granted for a
        fixed period, and that period ends in <strong>{{ $when }}</strong>.</p>

    {{-- Say what stops, not just that something expires. A vendor who cannot
         picture the consequence has no reason to act on the warning. --}}
    <p>When it does, you will be signed out and will not be able to sign in again
        until your administrator extends the period or makes your account
        permanent. Work already submitted is not affected.</p>

    @if($vendor->access_expires_at)
        <p><strong>Access ends:</strong> {{ $vendor->access_expires_at->format('d M Y, H:i') }}</p>
    @endif

    <p><strong>Vendor code:</strong> {{ $vendor->purchase_vendor_code }}</p>

    <p>If you expect to keep working with us beyond that date, contact your
        administrator now rather than on the day.</p>
@endsection
