<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telemetry ingestion
    |--------------------------------------------------------------------------
    |
    | `token` is the shared secret a GPS/reefer unit presents in the
    | X-Device-Token header. There is NO default and no fallback: when it is
    | unset the ingest endpoint refuses every request (503) rather than
    | accepting anonymous writes. An open ingest endpoint lets anyone on the
    | internet write positions and temperatures for a real truck, which is a
    | forged delivery record, so this fails closed on purpose.
    |
    | Per-device credentials (a token or HMAC per unit, rotatable) are the real
    | answer and belong in the hardware-onboarding sprint. This is one secret
    | for the whole fleet — enough to keep the endpoint shut, not enough to tell
    | two devices apart.
    |
    */

    'ingest' => [
        'token' => env('STOS_INGEST_TOKEN'),

        // A device buffers through a tunnel and replays later. History always
        // accepts those; the LIVE row must not be dragged backwards by them, so
        // a ping older than the live row's last_ping_at is appended to
        // telemetry_records and skipped for vehicle_live_status.
        'reject_stale_live_updates' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reefer excursion rule
    |--------------------------------------------------------------------------
    |
    | A frozen load runs at -18 C. Genset OFF while the body is WARMER than the
    | threshold means the cold chain is breaking right now.
    |
    | One threshold for the whole fleet is a simplification: the real rule is
    | per load (ice cream is not the same as pharma is not the same as chilled
    | produce), and it belongs on the consignment once Dispatch owns trips.
    |
    */

    'telemetry' => [
        'excursion_temperature'  => (float) env('STOS_EXCURSION_TEMP', -18.0),
        'excursion_generator_off' => 'off',

        // M2 rule: an excursion needs the vehicle to be MOVING as well.
        //
        // Read this before changing it. A reefer parked at the depot with the
        // genset deliberately off is the noise this suppresses — but a LOADED
        // trailer standing in a yard with a dead genset is spoiling cargo and
        // will now raise nothing. The gap closes when Dispatch tells us whether
        // the vehicle is loaded; until then this is a knowingly noisy-vs-silent
        // trade, made in favour of silence because M2 specified it.
        'excursion_requires_motion' => (bool) env('STOS_EXCURSION_REQUIRES_MOTION', true),

        // A device quiet for longer than this counts as offline; half of it is
        // "degraded" on the GPS-health badge.
        'stale_ping_minutes' => (int) env('STOS_STALE_PING_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fuel benchmarks (STOS-COST)
    |--------------------------------------------------------------------------
    |
    | Expected kilometres per litre by vehicle type, and how far a fill may fall
    | below it before it is flagged. Per TYPE rather than per vehicle because a
    | per-vehicle benchmark is a master-data screen nobody has built yet; when
    | that lands, a column on `vehicles` overrides this table.
    |
    */

    'fuel' => [
        'benchmark_kmpl' => [
            'truck' => 3.5, 'trailer' => 3.0, 'tipper' => 3.2, 'tanker' => 3.4,
            'reefer' => 2.8, 'lcv' => 8.0, 'other' => 4.0,
        ],
        // A fill more than this fraction below benchmark is an exception.
        // 0.15 = worse than 85% of expected.
        'variance_tolerance' => (float) env('STOS_FUEL_TOLERANCE', 0.15),
        'receipt_max_kb'     => 8192,
    ],

    /*
    |--------------------------------------------------------------------------
    | Allocation scoring (STOS-FLEET)
    |--------------------------------------------------------------------------
    |
    | Weights for the eligible-vehicle ranking. They sum to 1.0; each component
    | is scored 0-1 and the reasons shown to the user are generated from the
    | same numbers, so the explanation can never drift from the ranking.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Driver directory
    |--------------------------------------------------------------------------
    |
    | Where the people STOS can put behind a wheel come from.
    |
    |   'auto'       — use the CRM's customer/vendor directories when they are
    |                  present, and the STOS-local register when they are not.
    |                  This is what lets one codebase run integrated AND
    |                  standalone without a build flag.
    |   'crm'        — force the CRM directories.
    |   'standalone' — force the STOS register, even inside the CRM.
    |
    | `driver_keywords` drives the "drivers only" filter. Designation is FREE
    | TEXT in every one of the CRM's people registers, so this is a keyword
    | match and the screen says as much rather than implying a structured field.
    |
    */

    'directory' => [
        'driver' => env('STOS_DRIVER_DIRECTORY', 'auto'),
        'driver_keywords' => ['driver', 'chauffeur', 'operator'],
    ],

    'allocation' => [
        'weights' => [
            'proximity'   => 0.35,   // near the pickup
            'efficiency'  => 0.22,   // cheaper to run
            'utilisation' => 0.13,   // spread wear across the fleet
            // Driver compliance carries real weight: a truck whose regular
            // driver cannot legally drive it is a worse pick than one 50 km
            // further away, because the paperwork problem stops the load and
            // the distance only costs fuel.
            'driver'      => 0.30,
        ],
        // Beyond this, proximity scores zero rather than going negative.
        'max_useful_distance_km' => 400.0,
        // The window "recent utilisation" looks back over.
        'utilisation_days' => 7,
    ],

];
