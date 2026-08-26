<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Risk factors
    |--------------------------------------------------------------------------
    |
    | Each factor is one question the assessor answers about the vendor. The
    | chosen option carries `points`; the vendor's raw score is the sum of the
    | chosen points, normalised to 0–100 against the maximum possible, and then
    | banded into a tier (below). Config-driven until the admin Settings UI
    | (Phase C) makes it tenant-editable.
    |
    */

    'factors' => [
        'work_criticality' => [
            'label'   => 'Work criticality',
            'options' => [
                'low'      => ['label' => 'Low — ancillary / non-critical', 'points' => 1],
                'medium'   => ['label' => 'Medium — supports operations',   'points' => 2],
                'high'     => ['label' => 'High — core operations',         'points' => 3],
                'critical' => ['label' => 'Critical — safety / uptime critical', 'points' => 4],
            ],
        ],
        'hse_exposure' => [
            'label'   => 'HSE exposure',
            'options' => [
                'low'    => ['label' => 'Low — office / low-hazard',                'points' => 1],
                'medium' => ['label' => 'Medium — general site work',               'points' => 2],
                'high'   => ['label' => 'High — hazardous (height / hot / confined)', 'points' => 4],
            ],
        ],
        'contract_value' => [
            'label'   => 'Contract value band',
            'options' => [
                'under_10l' => ['label' => 'Under ₹10 L',      'points' => 1],
                '10l_50l'   => ['label' => '₹10 L – ₹50 L',    'points' => 2],
                '50l_1cr'   => ['label' => '₹50 L – ₹1 Cr',    'points' => 3],
                'over_1cr'  => ['label' => 'Over ₹1 Cr',       'points' => 4],
            ],
        ],
        'site_access' => [
            'label'   => 'Site access level',
            'options' => [
                'escorted'   => ['label' => 'Escorted only',                 'points' => 1],
                'general'    => ['label' => 'General areas',                  'points' => 2],
                'restricted' => ['label' => 'Restricted / critical areas',    'points' => 3],
                'confined'   => ['label' => 'Confined / high-risk zones',     'points' => 4],
            ],
        ],
        'workforce_size' => [
            'label'   => 'Workforce size',
            'options' => [
                'under_10' => ['label' => 'Under 10',   'points' => 1],
                '10_50'    => ['label' => '10 – 50',    'points' => 2],
                '50_200'   => ['label' => '50 – 200',   'points' => 3],
                'over_200' => ['label' => 'Over 200',   'points' => 4],
            ],
        ],
        'subcontracting' => [
            'label'   => 'Subcontracting',
            'options' => [
                'none'   => ['label' => 'None — direct workforce only', 'points' => 1],
                'single' => ['label' => 'Single tier',                  'points' => 2],
                'multi'  => ['label' => 'Multi-tier',                   'points' => 3],
            ],
        ],
        // §7 risk dimensions the doc calls out explicitly.
        'legal' => [
            'label'   => 'Legal risk',
            'options' => [
                'low'    => ['label' => 'Low — clean legal standing',            'points' => 1],
                'medium' => ['label' => 'Medium — minor disputes',              'points' => 2],
                'high'   => ['label' => 'High — active litigation / sanctions', 'points' => 4],
            ],
        ],
        'cyber_data' => [
            'label'   => 'Cyber / data risk',
            'options' => [
                'low'    => ['label' => 'Low — no system/data access',      'points' => 1],
                'medium' => ['label' => 'Medium — limited data access',     'points' => 2],
                'high'   => ['label' => 'High — sensitive systems/data',    'points' => 4],
            ],
        ],
        'reputational' => [
            'label'   => 'Reputational risk',
            'options' => [
                'low'    => ['label' => 'Low — no adverse history',         'points' => 1],
                'medium' => ['label' => 'Medium — some concerns',          'points' => 2],
                'high'   => ['label' => 'High — public / brand exposure',   'points' => 3],
            ],
        ],
        'environmental' => [
            'label'   => 'Environmental risk',
            'options' => [
                'low'    => ['label' => 'Low — negligible impact',              'points' => 1],
                'medium' => ['label' => 'Medium — managed emissions/waste',     'points' => 2],
                'high'   => ['label' => 'High — significant environmental impact', 'points' => 4],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tier bands
    |--------------------------------------------------------------------------
    | The normalised score (0–100) is banded to the highest tier whose lower
    | bound it meets. Ordered highest-first at read time.
    */
    'tiers' => [
        'Critical' => 80,
        'High'     => 60,
        'Medium'   => 40,
        'Low'      => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring depth per tier — what the classification implies operationally.
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'Critical' => 'Intensive — weekly reviews, full audits, mandatory prequalification',
        'High'     => 'Enhanced — fortnightly reviews, prequalification required',
        'Medium'   => 'Standard — monthly reviews',
        'Low'      => 'Light — quarterly reviews',
    ],
];
