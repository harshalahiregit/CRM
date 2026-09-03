<?php

/**
 * Shipped defaults for the Medical module. Anything a tenant may want to change
 * is also exposed through the TPV / Purchase settings `medical` group, which
 * deep-merges over these values; this file is the baseline behaviour.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Certificate
    |--------------------------------------------------------------------------
    */
    'certificate' => [
        // MED-TPV-2026-000123 / MED-PUR-2026-000123
        'prefix'           => 'MED',
        'validity_months'  => 12,
        // Printed on the PDF and encoded in the QR, so a certificate can be
        // checked without logging in. Falls back to the app URL.
        'verify_path'      => '/verify/medical/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality check
    |--------------------------------------------------------------------------
    */
    'qc' => [
        // A certificate a doctor of ours signed can be trusted straight through
        // when a tenant wants that; external ones always face a reviewer.
        'auto_approve_internal' => false,
        // The back-and-forth cap. The 11th round is refused and the reviewer has
        // to approve or reject instead of asking again.
        'max_iterations'        => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prerequisite
    |--------------------------------------------------------------------------
    */
    'prerequisite' => [
        // Safety induction is blocked until medical clearance exists.
        'block_induction'    => true,
        'pending_message'    => 'Medical Report is Pending',
        // Tenant-wide default for the "Not Applicable for the Project" bypass.
        // A work package's own flag overrides this.
        'not_applicable_default' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reverse geocoding
    |--------------------------------------------------------------------------
    | Turns the captured coordinates into a readable place. Best-effort: a
    | failure never blocks an examination from being saved.
    */
    'geocoding' => [
        'enabled'    => env('MEDICAL_GEOCODING_ENABLED', true),
        'endpoint'   => env('MEDICAL_GEOCODING_ENDPOINT', 'https://nominatim.openstreetmap.org/reverse'),
        'user_agent' => env('MEDICAL_GEOCODING_UA', 'SangoeCRM/1.0 (medical-certificates)'),
        'timeout'    => (int) env('MEDICAL_GEOCODING_TIMEOUT', 4),
    ],

];
