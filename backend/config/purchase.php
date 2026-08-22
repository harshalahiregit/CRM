<?php

/*
| Purchase module configuration. Mirrors the gate-PPE-enforcement toggle from
| config/tpv.php (parity rule) for the Purchase worker gate.
*/
return [

    /*
    |--------------------------------------------------------------------------
    | Gate — PPE enforcement (mirror of TPV Rule 5)
    |--------------------------------------------------------------------------
    |
    | How the worker gate reacts when a scanned worker holds no issued PPE:
    |
    |   'warn' → admit, but the gate surfaces a warning (default — issuance
    |            records can lag reality and a guard verifies PPE on the body).
    |   'deny' → refuse entry until PPE is issued.
    |   'off'  → the gate does not check PPE at all (legacy behaviour).
    |
    */
    'gate' => [
        'ppe_enforcement' => env('PURCHASE_GATE_PPE_ENFORCEMENT', 'warn'),
    ],

];
