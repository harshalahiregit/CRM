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
    //
    // The §27 dimensions the doc names beyond the original eight — productivity,
    // timeliness, training, environmental, security, incident and meeting-action
    // closure — ship at weight 0 by default: they are computed and surfaced on
    // every scorecard, but do not disturb the established overall index until a
    // tenant chooses to weight them (weights are tenant-editable, §34). Rebalance
    // the original eight down if you raise them so the set still sums to 1.0.
    'weights' => [
        'safety'         => (float) env('VPI_W_SAFETY', 0.20),
        'compliance'     => (float) env('VPI_W_COMPLIANCE', 0.18),
        'workforce'      => (float) env('VPI_W_WORKFORCE', 0.12),
        'quality'        => (float) env('VPI_W_QUALITY', 0.14),   // NCRs
        'capa'           => (float) env('VPI_W_CAPA', 0.10),      // corrective-action closure
        'conduct'        => (float) env('VPI_W_CONDUCT', 0.12),   // violations + strikes
        'inspection'     => (float) env('VPI_W_INSPECTION', 0.08),
        'documentation'  => (float) env('VPI_W_DOCUMENTATION', 0.06),
        'productivity'   => (float) env('VPI_W_PRODUCTIVITY', 0.0),
        'timeliness'     => (float) env('VPI_W_TIMELINESS', 0.0),
        'training'       => (float) env('VPI_W_TRAINING', 0.0),
        'environmental'  => (float) env('VPI_W_ENVIRONMENTAL', 0.0),
        'security'       => (float) env('VPI_W_SECURITY', 0.0),
        'incident'       => (float) env('VPI_W_INCIDENT', 0.0),
        'meeting_action' => (float) env('VPI_W_MEETING_ACTION', 0.0),
    ],

    // Per-item deductions from a dimension that starts at 100.
    'deductions' => [
        'ncr_open'        => 8,
        'ncr_overdue'     => 14,
        'capa_open'       => 8,
        'capa_overdue'    => 14,
        'violation_point' => 3,   // per open violation point
        'strike'          => 6,
        'incident'        => 10,  // per open/recent HSSE incident
        'incident_grave'  => 20,  // per grave-severity incident
        'security'        => 12,  // per security incident
        'mom_overdue'     => 10,  // per overdue meeting action
    ],

    // Documents expiring within this many days count as a partial documentation hit.
    'doc_expiring_window_days' => 30,

    // Letter-band thresholds on the overall index (A–E; distinct from VRS A–D).
    'bands' => [
        'A' => 85,   // Excellent
        'B' => 72,   // Good
        'C' => 58,   // Watch (§27 — was "Fair")
        'D' => 42,   // Poor
        // below D => 'E' (Critical)
    ],

    // Band letter → human label (§27 renames C to "Watch").
    'band_labels' => [
        'A' => 'Excellent',
        'B' => 'Good',
        'C' => 'Watch',
        'D' => 'Poor',
        'E' => 'Critical',
    ],
];
