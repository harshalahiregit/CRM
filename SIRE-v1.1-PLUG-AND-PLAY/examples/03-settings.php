<?php

/*
 * ============================================================================
 * OPTIONAL — every SIRE setting, with its default.
 * ============================================================================
 *
 * NOTHING here needs setting for SIRE to work. Each key has a safe default --
 * SLA off, AI off, gates on -- and lives in the CRM's existing settings store
 * behind SireSettingsAdapter. SIRE adds no configuration table.
 * ============================================================================
 */

// Whichever implementation config('sire.adapters.settings') names -- SIRE's
// shipped one, or your CRM's once you bind it. Same call either way.
$settings = app(\App\Contracts\Sire\Integration\SireSettingsAdapter::class);
$tenantId = 1;

// ---- SLA -------------------------------------------------------------------
// Absent: falls back to the per-severity targets on sire_severities. With those
// also null, there is no SLA — which is a correct, quiet default.
//
// Most SPECIFIC match wins (most keys in `match`); a tie goes to the earlier
// entry. Order is a tie-break, never the primary rule.
$settings->set($tenantId, 'sire.sla.policies', [
    ['match' => ['type' => 'bug', 'priority' => 'p1'], 'ack_minutes' => 15, 'resolve_minutes' => 240],
    ['match' => ['severity' => 'critical'],            'ack_minutes' => 60, 'resolve_minutes' => 960],
    // A null target DISABLES that clock deliberately — it does not fall through.
    ['match' => ['type' => 'enhancement'],             'ack_minutes' => 480, 'resolve_minutes' => null],
    ['match' => [],                                    'ack_minutes' => 480, 'resolve_minutes' => 4800],
]);

$settings->set($tenantId, 'sire.sla.warning_threshold', 0.8);

// ---- Release gates ---------------------------------------------------------
// Absent: the four defaults apply. An EMPTY array means "no governance" and is
// reported as `ungoverned` rather than shown as a row of confident ticks.
$settings->set($tenantId, 'sire.release.gates', [
    ['key' => 'no_open_critical',            'enabled' => true, 'blocking' => true],
    ['key' => 'qa_failures_resolved',        'enabled' => true, 'blocking' => true],
    ['key' => 'approvals_complete',          'enabled' => true, 'blocking' => true],
    // blocking:false makes a gate advisory — it reports without stopping a release.
    ['key' => 'regression_testing_complete', 'enabled' => true, 'blocking' => false],
]);

// ---- AI --------------------------------------------------------------------
// OFF BY DEFAULT. Opting a tenant in is a decision.
// Both flags are required: a capability shipped later must not switch itself on
// for a tenant that enabled AI for something else.
$settings->set($tenantId, 'sire.ai.enabled', true);
$settings->set($tenantId, 'sire.ai.capabilities', [
    'classification'           => true,
    'duplicate_detection'      => true,
    'root_cause_suggestion'    => true,
    'developer_test_cases'     => true,
    'qa_test_cases'            => true,
    'regression_risk'          => true,
    'recurrence_risk'          => true,
    'knowledge_recommendation' => false,   // verify KnowledgeBaseService first
    'release_risk'             => true,
    'release_note_refinement'  => true,
    'engineering_insights'     => true,
]);

// No provider needed — all 13 capabilities run locally.
// $settings->set($tenantId, 'sire.ai.provider', 'null');

// ---- Workflow --------------------------------------------------------------
// QA pass advances straight to Ready for Release. Off, it rests at QA_PASSED.
$settings->set($tenantId, 'sire.auto_ready_for_release', true);

// Silence specific notification events for this tenant.
$settings->set($tenantId, 'sire.notifications.disabled', []);
