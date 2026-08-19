<?php

/*
| HSSE Authority Matrix (Doc 1). The named authorities and the governance actions
| each owns — the single reference for "who signs off what". Config-driven so the
| governance team can retune ownership without a code change; surfaced read-only
| in the app via GET /tpv/authority-matrix.
*/
return [

    // The four named authorities and what they are accountable for.
    'authorities' => [
        'PMC' => [
            'label'          => 'PMC (Project Management Consultant)',
            'responsibilities' => [
                'Overall project governance and commercial oversight',
                'Vendor engagement and scope approval',
                'Final sign-off on onboarding activation',
            ],
        ],
        'Safety' => [
            'label'          => 'Safety / HSSE Department',
            'responsibilities' => [
                'HSSE compliance, permits and incident governance',
                'Workforce medical/induction gates',
                'Stop-work authority and suspension on safety breach',
            ],
        ],
        'Accounts' => [
            'label'          => 'Accounts / Finance',
            'responsibilities' => [
                'Statutory-document and insurance verification',
                'Commercial terms and payment gating',
            ],
        ],
        'Admin' => [
            'label'          => 'Admin / Site Administration',
            'responsibilities' => [
                'Badge issuance and gate access control',
                'Register upkeep (visitors, vehicles, drills)',
            ],
        ],
    ],

    // Governance action → the authorities that own / must sign off on it.
    'matrix' => [
        ['action' => 'Vendor Onboarding Approval',       'gate' => 'Internal Approval', 'authorities' => ['PMC', 'Safety', 'Accounts', 'Admin']],
        ['action' => 'Statutory / Insurance Verification','gate' => 'Commercial / Legal', 'authorities' => ['Accounts']],
        ['action' => 'Workforce Readiness (medical/induction)', 'gate' => 'Workforce', 'authorities' => ['Safety']],
        ['action' => 'Badge Activation / Site Access',    'gate' => 'Activation', 'authorities' => ['Admin', 'Safety']],
        ['action' => 'Permit-to-Work Approval',           'gate' => 'Safety', 'authorities' => ['Safety']],
        ['action' => 'Incident Closure (RCA + CAPA)',     'gate' => 'Safety', 'authorities' => ['Safety', 'PMC']],
        ['action' => 'Vendor Suspension (compliance)',    'gate' => 'Safety', 'authorities' => ['Safety', 'Accounts']],
        ['action' => 'Vendor Offboarding',                'gate' => 'Governance', 'authorities' => ['PMC', 'Accounts']],
    ],
];
