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

    // Dimension weights for the overall index (must sum to 1.0).
    //
    // §27 — the four governance dimensions the program actually computes from live
    // data (training currency, environmental conduct, security incidents, HSSE
    // incidents) now CARRY WEIGHT and genuinely move the index; the original eight
    // were rebalanced down to make room so the set still sums to 1.0. The three
    // remaining doc dimensions — productivity, timeliness and meeting-action
    // closure — stay at weight 0 because they have no real data feed yet (they are
    // structural stubs scoring a flat 100); a tenant can weight them once a feed
    // exists (weights are tenant-editable, §34).
    'weights' => [
        'safety'         => (float) env('VPI_W_SAFETY', 0.16),
        'compliance'     => (float) env('VPI_W_COMPLIANCE', 0.15),
        'workforce'      => (float) env('VPI_W_WORKFORCE', 0.10),
        'quality'        => (float) env('VPI_W_QUALITY', 0.12),   // NCRs
        'capa'           => (float) env('VPI_W_CAPA', 0.08),      // corrective-action closure
        'conduct'        => (float) env('VPI_W_CONDUCT', 0.10),   // violations + strikes
        'inspection'     => (float) env('VPI_W_INSPECTION', 0.06),
        'documentation'  => (float) env('VPI_W_DOCUMENTATION', 0.05),
        'productivity'   => (float) env('VPI_W_PRODUCTIVITY', 0.0),   // no feed yet
        'timeliness'     => (float) env('VPI_W_TIMELINESS', 0.0),     // no feed yet
        'training'       => (float) env('VPI_W_TRAINING', 0.05),
        'environmental'  => (float) env('VPI_W_ENVIRONMENTAL', 0.03),
        'security'       => (float) env('VPI_W_SECURITY', 0.04),
        'incident'       => (float) env('VPI_W_INCIDENT', 0.06),
        'meeting_action' => (float) env('VPI_W_MEETING_ACTION', 0.0), // no feed yet
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
