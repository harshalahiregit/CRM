<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Purchase vendor prequalification questionnaire
    |--------------------------------------------------------------------------
    |
    | The Purchase-side mirror of config/vendor_prequalification.php: a sectioned,
    | weighted questionnaire. Each chosen option carries `points`; the vendor's
    | score is the sum of chosen points normalised to 0–100 against the maximum,
    | then banded to an outcome (below). Higher is better — the opposite polarity
    | to the lean admin-set Risk Score. Purchase owns its own copy so the two
    | vendor modules share no config; the taxonomy is intentionally identical.
    |
    */

    'sections' => [
        'financial' => [
            'label'     => 'Financial standing',
            'questions' => [
                'turnover' => [
                    'label'   => 'Annual turnover vs contract value',
                    'options' => [
                        'under_1x' => ['label' => 'Below contract value', 'points' => 0],
                        '1_2x'     => ['label' => '1–2×',                 'points' => 1],
                        '2_5x'     => ['label' => '2–5×',                 'points' => 2],
                        'over_5x'  => ['label' => 'Over 5×',             'points' => 3],
                    ],
                ],
                'financials' => [
                    'label'   => 'Audited financial statements',
                    'options' => [
                        'none'       => ['label' => 'None available',   'points' => 0],
                        'one_year'   => ['label' => '1 year',           'points' => 1],
                        'two_years'  => ['label' => '2 years',          'points' => 2],
                        'three_plus' => ['label' => '3+ years',         'points' => 3],
                    ],
                ],
            ],
        ],
        'technical' => [
            'label'     => 'Technical capability',
            'questions' => [
                'similar_projects' => [
                    'label'   => 'Similar projects completed',
                    'options' => [
                        'none'      => ['label' => 'None',      'points' => 0],
                        'few'       => ['label' => '1–2',       'points' => 1],
                        'several'   => ['label' => '3–5',       'points' => 2],
                        'extensive' => ['label' => '5+',        'points' => 3],
                    ],
                ],
                'equipment' => [
                    'label'   => 'Plant & equipment adequacy',
                    'options' => [
                        'inadequate' => ['label' => 'Inadequate', 'points' => 0],
                        'partial'    => ['label' => 'Partial',    'points' => 1],
                        'adequate'   => ['label' => 'Adequate',   'points' => 2],
                        'strong'     => ['label' => 'Strong',     'points' => 3],
                    ],
                ],
            ],
        ],
        'hse' => [
            'label'     => 'HSE management system',
            'questions' => [
                'hse_system' => [
                    'label'   => 'Documented HSE management system',
                    'options' => [
                        'none'       => ['label' => 'None',                 'points' => 0],
                        'basic'      => ['label' => 'Basic',                'points' => 1],
                        'documented' => ['label' => 'Documented',          'points' => 2],
                        'certified'  => ['label' => 'Certified (ISO 45001)', 'points' => 3],
                    ],
                ],
                'incident_history' => [
                    'label'   => 'Incident / LTI history',
                    'options' => [
                        'poor'      => ['label' => 'Poor',      'points' => 0],
                        'average'   => ['label' => 'Average',   'points' => 1],
                        'good'      => ['label' => 'Good',      'points' => 2],
                        'excellent' => ['label' => 'Excellent', 'points' => 3],
                    ],
                ],
                // §6 — HSE taxonomy depth.
                'hse_organization' => [
                    'label'   => 'HSE organization & staffing',
                    'options' => [
                        'none'     => ['label' => 'No dedicated HSE staff', 'points' => 0],
                        'shared'   => ['label' => 'Shared responsibility',  'points' => 1],
                        'dedicated' => ['label' => 'Dedicated HSE officer', 'points' => 2],
                        'team'     => ['label' => 'Full HSE team',          'points' => 3],
                    ],
                ],
                'safety_statistics' => [
                    'label'   => 'Safety statistics (TRIR / LTIFR)',
                    'options' => [
                        'unavailable' => ['label' => 'Not maintained', 'points' => 0],
                        'high'        => ['label' => 'Above industry',  'points' => 1],
                        'average'     => ['label' => 'At industry',     'points' => 2],
                        'low'         => ['label' => 'Below industry',  'points' => 3],
                    ],
                ],
                'training_system' => [
                    'label'   => 'HSE training system',
                    'options' => [
                        'none'       => ['label' => 'None',        'points' => 0],
                        'adhoc'      => ['label' => 'Ad-hoc',      'points' => 1],
                        'structured' => ['label' => 'Structured',  'points' => 2],
                        'certified'  => ['label' => 'Accredited',  'points' => 3],
                    ],
                ],
                'risk_assessment_system' => [
                    'label'   => 'Risk-assessment system (JSA/HIRA)',
                    'options' => [
                        'none'       => ['label' => 'None',       'points' => 0],
                        'basic'      => ['label' => 'Basic',      'points' => 1],
                        'documented' => ['label' => 'Documented', 'points' => 2],
                        'mature'     => ['label' => 'Mature',     'points' => 3],
                    ],
                ],
                'emergency_preparedness' => [
                    'label'   => 'Emergency preparedness',
                    'options' => [
                        'none'       => ['label' => 'None',       'points' => 0],
                        'plan'       => ['label' => 'Plan only',  'points' => 1],
                        'drilled'    => ['label' => 'Plan + drills', 'points' => 2],
                        'integrated' => ['label' => 'Integrated with site', 'points' => 3],
                    ],
                ],
            ],
        ],
        'track_record' => [
            'label'     => 'Track record & references',
            'questions' => [
                'references' => [
                    'label'   => 'Client references',
                    'options' => [
                        'none'       => ['label' => 'None',   'points' => 0],
                        'one'        => ['label' => '1',      'points' => 1],
                        'two'        => ['label' => '2',      'points' => 2],
                        'three_plus' => ['label' => '3+',     'points' => 3],
                    ],
                ],
                'reputation' => [
                    'label'   => 'Market reputation / blacklist check',
                    'options' => [
                        'flagged'      => ['label' => 'Flagged / blacklisted', 'points' => 0],
                        'unknown'      => ['label' => 'Unknown',               'points' => 1],
                        'satisfactory' => ['label' => 'Satisfactory',          'points' => 2],
                        'strong'       => ['label' => 'Strong',                'points' => 3],
                    ],
                ],
            ],
        ],
        'legal' => [
            'label'     => 'Legal & statutory',
            'questions' => [
                'registrations' => [
                    'label'   => 'Statutory registrations (GST/PAN/PF/ESIC/labour)',
                    'options' => [
                        'missing'  => ['label' => 'Missing',  'points' => 0],
                        'partial'  => ['label' => 'Partial',  'points' => 1],
                        'most'     => ['label' => 'Most',     'points' => 2],
                        'complete' => ['label' => 'Complete', 'points' => 3],
                    ],
                ],
                'litigation' => [
                    'label'   => 'Pending litigation / disputes',
                    'options' => [
                        'major'       => ['label' => 'Major disputes', 'points' => 0],
                        'minor'       => ['label' => 'Minor',          'points' => 1],
                        'none_recent' => ['label' => 'None recent',    'points' => 2],
                        'clean'       => ['label' => 'Clean',          'points' => 3],
                    ],
                ],
            ],
        ],
        'insurance' => [
            'label'     => 'Insurance cover',
            'questions' => [
                'coverage' => [
                    'label'   => 'Coverage (WC / PL / CAR)',
                    'options' => [
                        'none'          => ['label' => 'None',          'points' => 0],
                        'partial'       => ['label' => 'Partial',       'points' => 1],
                        'adequate'      => ['label' => 'Adequate',      'points' => 2],
                        'comprehensive' => ['label' => 'Comprehensive', 'points' => 3],
                    ],
                ],
                'validity' => [
                    'label'   => 'Policy validity',
                    'options' => [
                        'expired'    => ['label' => 'Expired',            'points' => 0],
                        'expiring'   => ['label' => 'Expiring soon',      'points' => 1],
                        'valid'      => ['label' => 'Valid',              'points' => 2],
                        'valid_long' => ['label' => 'Valid > 6 months',   'points' => 3],
                    ],
                ],
            ],
        ],
        // §6 — Company capability.
        'company' => [
            'label'     => 'Company capability',
            'questions' => [
                'regional_capability' => [
                    'label'   => 'Regional / geographic capability',
                    'options' => [
                        'none'     => ['label' => 'No local presence',  'points' => 0],
                        'limited'  => ['label' => 'Limited',            'points' => 1],
                        'regional' => ['label' => 'Regional coverage',  'points' => 2],
                        'national' => ['label' => 'National / multi-region', 'points' => 3],
                    ],
                ],
                'manpower_capability' => [
                    'label'   => 'Manpower capability / bench strength',
                    'options' => [
                        'inadequate' => ['label' => 'Inadequate', 'points' => 0],
                        'partial'    => ['label' => 'Partial',    'points' => 1],
                        'adequate'   => ['label' => 'Adequate',   'points' => 2],
                        'strong'     => ['label' => 'Strong',     'points' => 3],
                    ],
                ],
            ],
        ],
        // §6 — Commercial.
        'commercial' => [
            'label'     => 'Commercial',
            'questions' => [
                'commercial_capability' => [
                    'label'   => 'Commercial / financial capability',
                    'options' => [
                        'weak'     => ['label' => 'Weak',     'points' => 0],
                        'moderate' => ['label' => 'Moderate', 'points' => 1],
                        'sound'    => ['label' => 'Sound',    'points' => 2],
                        'strong'   => ['label' => 'Strong',   'points' => 3],
                    ],
                ],
                'contract_history' => [
                    'label'   => 'Contract history / repeat business',
                    'options' => [
                        'none'      => ['label' => 'None',            'points' => 0],
                        'some'      => ['label' => 'Some',            'points' => 1],
                        'good'      => ['label' => 'Good',            'points' => 2],
                        'extensive' => ['label' => 'Extensive',       'points' => 3],
                    ],
                ],
            ],
        ],
        // §6 — Compliance (licences as a discrete prequal item).
        'compliance' => [
            'label'     => 'Compliance',
            'questions' => [
                'licences' => [
                    'label'   => 'Statutory / trade licences',
                    'options' => [
                        'missing'  => ['label' => 'Missing',  'points' => 0],
                        'partial'  => ['label' => 'Partial',  'points' => 1],
                        'most'     => ['label' => 'Most',     'points' => 2],
                        'complete' => ['label' => 'Complete', 'points' => 3],
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Outcome bands — normalised score (0–100) → status.
    |--------------------------------------------------------------------------
    | The spec's "82/100" qualification bar is the Qualified threshold.
    */
    'outcomes' => [
        'Qualified'     => 82,
        'Conditional'   => 60,
        'Not_Qualified' => 0,
    ],
];
