<?php

/*
| §10 (Purchase) — Configurable onboarding checklists. Beyond the per-vendor-type document
| set, the doc asks for checklists that vary by Risk Level / Project / Site /
| Work Type, plus a general checklist (not only documents) that gates activation.
|
| A rule matches on one dimension value and contributes its items. The `general`
| block always applies; when `gates_activation` is true its items must be ticked
| before a vendor can be activated. Config baseline, tenant-editable (§34).
*/
return [
    // Dimensions a checklist rule may key on.
    'dimensions' => ['risk_level', 'project', 'site', 'work_type'],

    // [ { match: {dimension: value}, items: [..] } ]
    'rules' => [
        ['match' => ['risk_level' => 'High'], 'items' => [
            'Job safety analysis reviewed',
            'Insurance certificate verified',
            'HSE plan submitted',
        ]],
        ['match' => ['risk_level' => 'Critical'], 'items' => [
            'Executive sponsor sign-off',
            'On-site safety audit completed',
        ]],
    ],

    // Always-applied general checklist (beyond documents).
    'general' => [
        'gates_activation' => true,
        'items' => [
            'Company profile complete',
            'Primary contact verified',
            'Bank details captured',
        ],
    ],
];
