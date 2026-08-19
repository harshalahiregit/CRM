<?php

/*
| Vendor Rating System (Doc 5) — the weights and deduction rules behind a
| vendor's live scorecard. Kept in config so the governance team can retune the
| model without a code change.
*/
return [

    // Dimension weights for the overall score (should sum to 1.0). Safety-first.
    'weights' => [
        'safety'     => (float) env('VRS_W_SAFETY', 0.45),
        'compliance' => (float) env('VRS_W_COMPLIANCE', 0.35),
        'workforce'  => (float) env('VRS_W_WORKFORCE', 0.20),
    ],

    // Points deducted from the Safety dimension (starts at 100) per open incident
    // of each severity, and per active safety strike.
    'safety_deductions' => [
        'incident' => [
            'Fatal'    => 40,
            'Serious'  => 20,
            'Moderate' => 8,
            'Minor'    => 3,
        ],
        'strike' => 6,
    ],

    // Letter-band thresholds on the overall score.
    'bands' => [
        'A' => 85,   // Excellent
        'B' => 70,   // Good
        'C' => 55,   // Fair
        // below C['threshold'] => 'D' (Poor)
    ],
];
