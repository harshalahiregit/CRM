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

];
