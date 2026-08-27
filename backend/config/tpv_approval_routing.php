<?php

/*
| §12 — Dimension-based approval routing. Which approver levels an approval must
| pass through, chosen by the request's dimensions: Risk / Project / Value /
| Work type / Workforce size / Site / Department. The first rule whose match set
| is satisfied wins; `default_levels` applies when nothing matches. Config
| baseline, tenant-editable (§34).
*/
return [
    'dimensions' => ['risk', 'project', 'value', 'work_type', 'workforce_size', 'site', 'department'],

    // [ { match: {dimension: value}, levels: ["role", ..] } ]
    'rules' => [
        ['match' => ['risk' => 'High'],  'levels' => ['manager', 'head']],
        ['match' => ['risk' => 'Critical'], 'levels' => ['manager', 'head', 'director']],
        ['match' => ['value' => 'over_1cr'], 'levels' => ['manager', 'head', 'director']],
    ],

    'default_levels' => ['manager'],
];
