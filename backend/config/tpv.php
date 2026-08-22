<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TPV Onboarding Approval Workflow
    |--------------------------------------------------------------------------
    |
    | mode = 'single'      → the existing one-step admin approval (default; the
    |                        legacy approve/reject/hold flow is used unchanged).
    | mode = 'multi_level' → an ordered chain; each level must approve before the
    |                        next unlocks. The final level finalises the onboarding
    |                        (reusing the existing approval + registration number).
    |
    | These defaults are overridable per tenant once System Configuration ships.
    |
    */
    'approval' => [
        'mode' => env('TPV_APPROVAL_MODE', 'single'),

        'levels' => [
            ['level' => 1, 'role' => 'staff', 'label' => 'Staff Review'],
            ['level' => 2, 'role' => 'admin', 'label' => 'Admin Approval'],
        ],

        'sla_hours' => (int) env('TPV_APPROVAL_SLA_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gate — PPE enforcement (Sangoe TPV Rule 5)
    |--------------------------------------------------------------------------
    |
    | How the site gate reacts when a worker is missing mandatory PPE (per the
    | PPE requirement matrix, cross-checked against issued stock):
    |
    |   'warn' → amber: the guard is shown the missing items, entry not blocked
    |            (default — issuance records can lag the physical reality, and a
    |            guard verifies PPE on the body).
    |   'deny' → red: entry refused until the PPE is issued.
    |   'off'  → the gate does not check PPE at all (legacy behaviour).
    |
    */
    'gate' => [
        'ppe_enforcement' => env('TPV_GATE_PPE_ENFORCEMENT', 'warn'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Communications — automatic dispatch (Sangoe TPV §31)
    |--------------------------------------------------------------------------
    |
    | When true (default), a governance event that the vendor must know about —
    | an NCR raised against them, a major/critical violation recorded — emails
    | the vendor immediately over the tenant's own transport, in addition to
    | appearing in the pull-based alerts feed. Dispatch is best-effort: a
    | delivery failure never rolls back the event, and every attempt (sent /
    | failed / skipped-no-email) is written to the notification log.
    |
    | Set to false to fall back to the manual "Send" workflow only.
    |
    */
    'communications' => [
        'auto_dispatch' => (bool) env('TPV_COMMS_AUTO_DISPATCH', true),
    ],

];
