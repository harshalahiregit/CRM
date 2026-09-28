{{--
    Where each person joined the meeting from.

    The attendance table above says WHO was present. This says how the system
    knows — the address the join came from, the device it came from, and the
    location if the person's browser offered one. It is a separate table on
    purpose: the attendance row is already six columns wide, and squeezing an
    IP, a device string and a pair of coordinates into a twelfth of a page
    produced four wrapped lines per person and a document nobody could read.

    It prints only for people who actually joined through the CRM. A tick made
    by hand has no evidence behind it and must not be given the appearance of
    some — an empty row here is the honest record of a manual mark, so those
    rows are left out and the count below says how many there were.

    Expects: $attendees (a collection of roster rows).
--}}
@php
    $joined = $attendees->filter(fn ($a) => $a->join_ip || $a->join_device || $a->join_latitude);
    $coords = function ($a) {
        return $a->join_latitude !== null && $a->join_longitude !== null
            ? sprintf('%.5f, %.5f', $a->join_latitude, $a->join_longitude)
            : null;
    };
    $unevidenced = $attendees->filter(fn ($a) => $a->attended)->count() - $joined->count();
@endphp

@if ($joined->count())
    <h2>Join Evidence ({{ $joined->count() }} recorded)</h2>
    <p class="sub" style="margin:0 0 6px;">
        Captured when each person opened the meeting from the CRM. A location is shown only where
        the person's browser offered one; it is not looked up from the address.
    </p>
    <table class="att">
        <thead>
            <tr>
                <th style="width:24%">Name</th>
                <th style="width:15%">Joined at</th>
                <th style="width:19%">Device</th>
                <th style="width:17%">IP address</th>
                <th style="width:25%">Location</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($joined as $a)
                <tr>
                    <td>
                        {{ $a->name }}
                        @if ($a->designation)<div class="sub">{{ $a->designation }}</div>@endif
                    </td>
                    <td>{{ $a->joined_at ? $a->joined_at->format('d M Y, H:i') : '—' }}</td>
                    <td>{{ $a->join_device ?: '—' }}</td>
                    <td>{{ $a->join_ip ?: '—' }}</td>
                    <td>
                        @if ($a->join_location_label)
                            {{ $a->join_location_label }}
                            @if ($coords($a))<div class="sub">{{ $coords($a) }}</div>@endif
                        @elseif ($coords($a))
                            {{ $coords($a) }}
                        @else
                            <span class="muted">Not shared</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if ($unevidenced > 0)
        <p class="sub" style="margin:0 0 10px;">
            {{ $unevidenced }} further {{ $unevidenced === 1 ? 'person was' : 'people were' }}
            marked present by hand, so there is no join record for {{ $unevidenced === 1 ? 'them' : 'them' }}.
        </p>
    @endif
@endif
