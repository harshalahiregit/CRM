<?php

/*
| Purchase Vendor Performance Index — the Purchase-side mirror of config/vpi.php
| (parity rule). Purchase has no VRS scorecard to build on, so the index is
| computed directly from the governance engines mirrored onto purchase_*:
| compliance, NCRs (quality), CAPA closure, conduct (violations), inspections and
| document currency. Weighted 0-100 with an A-E band. Tunable without code.
*/
return [

    // Dimension weights for the overall index (should sum to 1.0).
    'weights' => [
        'compliance'    => (float) env('PVPI_W_COMPLIANCE', 0.28),
        'quality'       => (float) env('PVPI_W_QUALITY', 0.20),   // NCRs
        'capa'          => (float) env('PVPI_W_CAPA', 0.14),
        'conduct'       => (float) env('PVPI_W_CONDUCT', 0.18),   // violations
        'inspection'    => (float) env('PVPI_W_INSPECTION', 0.12),
        'documentation' => (float) env('PVPI_W_DOCUMENTATION', 0.08),
    ],

    // Per-item deductions from a dimension that starts at 100.
    'deductions' => [
        'ncr_open'        => 8,
        'ncr_overdue'     => 14,
        'capa_open'       => 8,
        'capa_overdue'    => 14,
        'violation_point' => 3,   // per open violation point
    ],

    'doc_expiring_window_days' => 30,

    // Letter-band thresholds on the overall index (A-E).
    'bands' => [
        'A' => 85,
        'B' => 72,
        'C' => 58,
        'D' => 42,
        // below D => 'E'
    ],
];
