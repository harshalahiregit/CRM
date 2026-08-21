<?php

/*
| Vendor Performance Index (Sangoe TPV §27) — an ADDITIVE superset of the VRS
| scorecard (config/vrs.php). VPI keeps the three VRS dimensions and layers on
| the governance signals the rest of the program now produces (NCR, CAPA,
| violations/strikes, inspections, document expiry) for a broader, weighted
| index with an A–E band. Kept in config so the governance team can retune it
| without a code change. The base VRS is untouched.
*/
return [

    // Dimension weights for the overall index (should sum to 1.0).
    'weights' => [
        'safety'        => (float) env('VPI_W_SAFETY', 0.20),
        'compliance'    => (float) env('VPI_W_COMPLIANCE', 0.18),
        'workforce'     => (float) env('VPI_W_WORKFORCE', 0.12),
        'quality'       => (float) env('VPI_W_QUALITY', 0.14),   // NCRs
        'capa'          => (float) env('VPI_W_CAPA', 0.10),      // corrective-action closure
        'conduct'       => (float) env('VPI_W_CONDUCT', 0.12),   // violations + strikes
        'inspection'    => (float) env('VPI_W_INSPECTION', 0.08),
        'documentation' => (float) env('VPI_W_DOCUMENTATION', 0.06),
    ],

    // Per-item deductions from a dimension that starts at 100.
    'deductions' => [
        'ncr_open'        => 8,
        'ncr_overdue'     => 14,
        'capa_open'       => 8,
        'capa_overdue'    => 14,
        'violation_point' => 3,   // per open violation point
        'strike'          => 6,
    ],

    // Documents expiring within this many days count as a partial documentation hit.
    'doc_expiring_window_days' => 30,

    // Letter-band thresholds on the overall index (A–E; distinct from VRS A–D).
    'bands' => [
        'A' => 85,   // Excellent
        'B' => 72,   // Good
        'C' => 58,   // Fair
        'D' => 42,   // Poor
        // below D => 'E' (Critical)
    ],
];
