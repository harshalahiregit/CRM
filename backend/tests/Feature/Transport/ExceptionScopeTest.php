<?php

namespace Tests\Feature\Transport;

use App\Support\Transport\ExceptionCategory;
use App\Support\Transport\ExceptionScope;
use App\Support\Transport\ExceptionSeverity;
use App\Support\Transport\ExceptionSlaState;
use App\Support\Transport\ExceptionStatus;
use App\Support\Transport\TripStatus;
use Tests\TestCase;

/**
 * SNG-TRN-013 steps 0 and 1 — the scope ruling and the vocabulary.
 *
 * These guard a boundary, not a behaviour. Every number below was read from the
 * document it names, and asserting them here is what stops the vocabulary
 * drifting away from the package one careless edit at a time.
 *
 * No database: nothing here touches one.
 */
class ExceptionScopeTest extends TestCase
{
    /* ══════════ Step 0 · the scope ruling ══════════ */

    public function test_the_registry_references_the_ticket_gives_are_all_corrected(): void
    {
        // Step 12 Ticket_Register:14 names FRS-P0-013, BR-012, DB-012, API-008,
        // EV-009. Every one points at the wrong row; each correction was read
        // from its own document. D-35.
        $c = ExceptionScope::REGISTRY_REF_CORRECTIONS;

        $this->assertCount(5, $c);
        foreach (['FRS-P0-013', 'BR-012', 'DB-012', 'API-008', 'EV-009'] as $wrong) {
            $this->assertArrayHasKey($wrong, $c, "{$wrong} must be recorded as wrong");
            $this->assertNotSame('', trim($c[$wrong]), "{$wrong} must name what it should have been");
        }

        $this->assertStringContainsString('DB-010', $c['DB-012']);
        $this->assertStringContainsString('API-007', $c['API-008']);
        $this->assertStringContainsString('EVT-008', $c['EV-009']);
        $this->assertStringContainsString('TRP-P0-011', $c['FRS-P0-013']);
        $this->assertStringContainsString('TRP-P0-012', $c['FRS-P0-013']);
    }

    public function test_the_two_in_scope_rtm_requirements_are_the_p0_ones(): void
    {
        // STOS-REQ-OPS-009 "Track trip status" and STOS-REQ-OPS-012 "Handle
        // breakdown", both P0, both read from the RTM with the ID/field
        // alignment checked.
        $this->assertSame(
            ['STOS-REQ-OPS-009', 'STOS-REQ-OPS-012'],
            ExceptionScope::IN_SCOPE,
        );
    }

    public function test_every_deferred_requirement_states_a_reason(): void
    {
        $this->assertNotEmpty(ExceptionScope::DEFERRED);

        foreach (ExceptionScope::DEFERRED as $id => $reason) {
            $this->assertMatchesRegularExpression('/^STOS-REQ-[A-Z]+-\d{3}$/', $id);
            $this->assertGreaterThan(
                40, strlen($reason),
                "{$id} needs a real reason, not a shrug",
            );
        }
    }

    public function test_deferred_and_in_scope_never_overlap(): void
    {
        $this->assertSame(
            [],
            array_intersect(ExceptionScope::IN_SCOPE, array_keys(ExceptionScope::DEFERRED)),
            'a requirement cannot be both built and deferred',
        );
    }

    public function test_q3_wires_exactly_one_edge_and_defers_the_next(): void
    {
        $this->assertSame('dispatched->in_transit', ExceptionScope::STATE_EDGE_OWNED);
        $this->assertSame('in_transit->delivered', ExceptionScope::STATE_EDGE_DEFERRED);
    }

    public function test_q3_permits_only_two_departure_columns(): void
    {
        // "departed_at, departed_by columns only. Nothing beyond that."
        $this->assertSame(['departed_at', 'departed_by'], ExceptionScope::DEPARTURE_FIELDS);
    }

    public function test_all_twelve_ops_87_fields_are_dispositioned(): void
    {
        $d = ExceptionScope::OPS_87_DISPOSITION;

        $this->assertCount(12, $d, 'OPS §87 lists twelve fields an exception must contain');

        foreach ($d as $field => $disposition) {
            $this->assertContains(
                $disposition, ['built', 'deferred'],
                "'{$field}' has an unrecognised disposition",
            );
        }

        // The two the package asks for and no model can supply.
        $this->assertSame('deferred', $d['financial impact']);
        $this->assertSame('deferred', $d['customer impact']);
    }

    public function test_every_automatic_source_is_named_and_unavailable(): void
    {
        // 013 ships with manual raising only. Each rule that would raise an
        // exception without a person is listed with what it is waiting for.
        $s = ExceptionScope::AUTOMATIC_SOURCES;

        $this->assertArrayHasKey('BR-P0-008', $s);
        $this->assertArrayHasKey('BR-P0-009', $s);
        $this->assertArrayHasKey('BR-P0-010', $s);
        $this->assertStringContainsString('GPS', $s['BR-P0-010']);
    }

    public function test_all_six_rulings_are_recorded(): void
    {
        $r = ExceptionScope::RULINGS;

        $this->assertCount(6, $r);
        foreach (['Q1', 'Q2', 'Q3', 'Q4', 'Q5', 'Q6'] as $q) {
            $this->assertArrayHasKey($q, $r);
            $this->assertNotSame('', trim($r[$q]));
        }
    }

    public function test_the_owners_words_and_the_implementers_reasoning_stay_separate(): void
    {
        // A constant named RULINGS that mixed the two would attribute the
        // implementer's argument to the person who authorised the work.
        $rulings   = ExceptionScope::RULINGS;
        $rationale = ExceptionScope::RULING_RATIONALE;

        $this->assertSame(array_keys($rulings), array_keys($rationale));

        foreach ($rulings as $q => $decision) {
            $this->assertNotSame(
                $decision, $rationale[$q],
                "{$q}'s decision and its rationale must not be the same text",
            );
            $this->assertGreaterThan(
                80, strlen($rationale[$q]),
                "{$q} must carry real reasoning, not a restatement",
            );
        }
    }

    public function test_the_verbatim_rulings_are_not_paraphrased(): void
    {
        // Spot-checks against the approval message. If someone "tidies" these,
        // the record stops being verbatim and the constant starts lying.
        $r = ExceptionScope::RULINGS;

        $this->assertStringContainsString('8 total', $r['Q1']);
        $this->assertSame('waived declared in the enum, not wired.', $r['Q2']);
        $this->assertStringContainsString('Option (b)', $r['Q3']);
        $this->assertStringContainsString('departed_at, departed_by columns only', $r['Q3']);
        $this->assertStringContainsString('note-only', $r['Q4']);
        $this->assertStringContainsString('elapsed wall-clock only', $r['Q5']);
        $this->assertStringContainsString('no auto-ownership', $r['Q6']);
    }

    public function test_implementer_choices_are_recorded_as_choices(): void
    {
        // Places the documents are silent, decided in order to build at all,
        // put to the owner and approved. Not answers to Q1..Q6.
        $d = ExceptionScope::DESIGN_DECISIONS;

        $this->assertCount(4, $d);
        foreach ($d as $subject => $reason) {
            $this->assertGreaterThan(
                60, strlen($reason),
                "'{$subject}' must say why it was decided that way",
            );
        }
    }

    public function test_every_exclusion_states_a_reason(): void
    {
        foreach (ExceptionScope::EXCLUDED as $subject => $reason) {
            $this->assertGreaterThan(
                40, strlen($reason),
                "'{$subject}' is excluded without an adequate reason",
            );
        }
    }

    public function test_qc_incidents_are_recorded_as_a_separate_entity(): void
    {
        // The Quality domain has its own incident → RCA → CAPA lifecycle with no
        // ticket. Merging it into exceptions would invent a model neither
        // document describes.
        $this->assertStringContainsString('QC-001', ExceptionScope::INCIDENT_IS_NOT_EXCEPTION);
        $this->assertStringContainsString('CAPA', ExceptionScope::INCIDENT_IS_NOT_EXCEPTION);
    }

    /* ══════════ Step 1 · status — Q1 and Q2 ══════════ */

    public function test_step_9_declares_seven_states_in_its_own_order(): void
    {
        $this->assertSame([
            'open', 'acknowledged', 'in_progress',
            'mitigation_planned', 'resolved', 'verified', 'closed',
        ], ExceptionStatus::STEP_9);
    }

    public function test_the_enum_declares_step_9_plus_waived(): void
    {
        // Q1 gives seven; Q2 adds waived. Eight, and the scope file says eight.
        $this->assertCount(8, ExceptionStatus::ALL);
        $this->assertSame(ExceptionScope::STATE_COUNT_DECLARED, count(ExceptionStatus::ALL));
        $this->assertSame(
            ExceptionStatus::STEP_9,
            array_values(array_diff(ExceptionStatus::ALL, [ExceptionStatus::WAIVED])),
        );
    }

    public function test_waived_is_in_no_registry_which_is_why_it_is_declared_here(): void
    {
        // It comes from FRS TRP-P0-012 alone and is BR-P0-011's named override.
        $this->assertNotContains(ExceptionStatus::WAIVED, ExceptionStatus::STEP_9);
        $this->assertNotContains(ExceptionStatus::WAIVED, ExceptionStatus::SM_EXC);
        $this->assertNotContains(ExceptionStatus::WAIVED, ExceptionStatus::ENUM_004);
        $this->assertContains(ExceptionStatus::WAIVED, ExceptionStatus::ALL);
    }

    public function test_sm_exc_and_enum_004_are_recorded_as_the_documents_have_them(): void
    {
        $this->assertSame(['open', 'acknowledged', 'resolved'], ExceptionStatus::SM_EXC);
        $this->assertSame(
            ['open', 'acknowledged', 'in_progress', 'resolved', 'closed'],
            ExceptionStatus::ENUM_004,
        );
    }

    public function test_reachable_is_exactly_sm_excs_three(): void
    {
        // Step 11 LOCKS transitions for these three and no others.
        $this->assertSame(ExceptionStatus::SM_EXC, ExceptionStatus::REACHABLE);
    }

    public function test_declared_and_unreachable_partition_the_vocabulary(): void
    {
        $partition = array_merge(
            ExceptionStatus::REACHABLE,
            array_keys(ExceptionStatus::UNREACHABLE),
        );

        sort($partition);
        $all = ExceptionStatus::ALL;
        sort($all);

        $this->assertSame($all, $partition, 'every declared state is either reachable or explained');
    }

    public function test_every_unreachable_state_says_why(): void
    {
        foreach (ExceptionStatus::UNREACHABLE as $state => $reason) {
            $this->assertGreaterThan(
                40, strlen($reason),
                "{$state} is unreachable without an adequate reason",
            );
        }
    }

    public function test_only_the_two_locked_transitions_are_wired(): void
    {
        // STT-015 open → acknowledged, STT-016 acknowledged → resolved.
        $this->assertSame([
            'open'         => ['acknowledged'],
            'acknowledged' => ['resolved'],
        ], ExceptionStatus::TRANSITIONS);
    }

    public function test_nothing_transitions_into_an_unreachable_state(): void
    {
        $unreachable = array_keys(ExceptionStatus::UNREACHABLE);

        foreach (ExceptionStatus::TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                $this->assertNotContains(
                    $to, $unreachable,
                    "{$from} → {$to} reaches a state declared unreachable",
                );
            }
        }
    }

    public function test_resolved_is_terminal_by_construction(): void
    {
        // Absence from TRANSITIONS is what makes it terminal — no second list.
        $this->assertArrayNotHasKey(ExceptionStatus::RESOLVED, ExceptionStatus::TRANSITIONS);
        $this->assertFalse(ExceptionStatus::canTransition('resolved', 'open'));
        $this->assertFalse(ExceptionStatus::canTransition('resolved', 'closed'));
    }

    public function test_the_gate_refuses_every_unwired_jump(): void
    {
        $this->assertTrue(ExceptionStatus::canTransition('open', 'acknowledged'));
        $this->assertTrue(ExceptionStatus::canTransition('acknowledged', 'resolved'));

        // The one a caller would most plausibly try, and the one Step 11 does
        // not LOCK: SM-EXC's `open` row says "Acknowledged/Resolved", but only
        // STT-015 exists.
        $this->assertFalse(ExceptionStatus::canTransition('open', 'resolved'));
        $this->assertFalse(ExceptionStatus::canTransition('open', 'in_progress'));
        $this->assertFalse(ExceptionStatus::canTransition('acknowledged', 'waived'));
        $this->assertFalse(ExceptionStatus::canTransition('open', 'waived'));
    }

    public function test_active_and_terminal_cover_every_state(): void
    {
        $covered = array_merge(ExceptionStatus::ACTIVE, ExceptionStatus::TERMINAL);
        sort($covered);
        $all = ExceptionStatus::ALL;
        sort($all);

        $this->assertSame($all, $covered, 'a state is either still live or finished');
    }

    public function test_every_state_has_a_label(): void
    {
        foreach (ExceptionStatus::ALL as $s) {
            $this->assertArrayHasKey($s, ExceptionStatus::LABELS);
            $this->assertNotSame($s, ExceptionStatus::label($s), "{$s} needs a human label");
        }
    }

    public function test_there_is_no_deleted_or_cancelled_state(): void
    {
        // OPS §154: an exception "must never disappear from the system".
        foreach (['deleted', 'cancelled', 'canceled', 'archived'] as $forbidden) {
            $this->assertNotContains($forbidden, ExceptionStatus::ALL);
        }
    }

    /* ══════════ Step 1 · severity — ENUM-003 ══════════ */

    public function test_severity_is_enum_003_verbatim(): void
    {
        $this->assertSame(['low', 'medium', 'high', 'critical'], ExceptionSeverity::ALL);
    }

    public function test_the_default_is_fld_014s(): void
    {
        $this->assertSame('medium', ExceptionSeverity::DEFAULT);
    }

    public function test_every_severity_carries_ops_89s_definition(): void
    {
        foreach (ExceptionSeverity::ALL as $s) {
            $this->assertArrayHasKey($s, ExceptionSeverity::DEFINITIONS);
            $this->assertStringEndsWith('.', ExceptionSeverity::DEFINITIONS[$s]);
        }

        $this->assertSame(
            'Safety, major financial, customer or compliance risk.',
            ExceptionSeverity::DEFINITIONS['critical'],
        );
    }

    public function test_rank_orders_least_to_most_severe(): void
    {
        $this->assertTrue(ExceptionSeverity::atLeast('critical', 'low'));
        $this->assertTrue(ExceptionSeverity::atLeast('high', 'high'));
        $this->assertFalse(ExceptionSeverity::atLeast('low', 'medium'));
        $this->assertSame(0, ExceptionSeverity::rank('nonsense'));
    }

    public function test_br_p0_011_reaches_critical_and_nothing_else(): void
    {
        // "No CRITICAL exception can be marked resolved without resolution
        // evidence." The rule's reach is stated once, here.
        $this->assertTrue(ExceptionSeverity::requiresResolutionEvidence('critical'));

        foreach (['low', 'medium', 'high'] as $s) {
            $this->assertFalse(
                ExceptionSeverity::requiresResolutionEvidence($s),
                "BR-P0-011 does not reach {$s}",
            );
        }
    }

    /* ══════════ Step 1 · category — OPS §88 ══════════ */

    public function test_all_eight_ops_88_categories_are_declared_in_order(): void
    {
        $this->assertSame([
            'resource', 'compliance', 'operational', 'temperature',
            'financial', 'customer', 'fleet', 'documentation',
        ], ExceptionCategory::ALL);
    }

    public function test_the_scope_and_the_enum_agree_on_the_categories(): void
    {
        $this->assertSame(
            ExceptionCategory::ALL,
            array_keys(ExceptionScope::OPS_88_CATEGORIES),
        );
        $this->assertSame(
            ExceptionScope::OPS_88_CATEGORIES,
            ExceptionCategory::DEFINITIONS,
        );
    }

    public function test_every_category_keeps_the_documents_own_gloss(): void
    {
        foreach (ExceptionCategory::ALL as $c) {
            $this->assertNotNull(ExceptionCategory::definition($c));
            $this->assertArrayHasKey($c, ExceptionCategory::LABELS);
        }

        $this->assertSame('Breakdown/maintenance', ExceptionCategory::definition('fleet'));
        $this->assertSame('Delay/deviation', ExceptionCategory::definition('operational'));
    }

    public function test_no_category_is_unreachable(): void
    {
        // Unlike status, a category is a label a person picks — none needs a
        // data model behind it, so all eight are selectable.
        foreach (ExceptionCategory::ALL as $c) {
            $this->assertTrue(ExceptionCategory::isValid($c));
        }
    }

    public function test_automatic_sources_name_only_real_categories(): void
    {
        foreach (array_keys(ExceptionCategory::AUTOMATIC_SOURCE) as $c) {
            $this->assertContains($c, ExceptionCategory::ALL, "{$c} is not an OPS §88 category");
        }
    }

    /* ══════════ Step 1 · SLA state — OPS §104 ══════════ */

    public function test_ops_104s_three_states_are_declared_verbatim(): void
    {
        $this->assertSame(['on_track', 'at_risk', 'overdue'], ExceptionSlaState::OPS_104);
    }

    public function test_the_two_extra_states_are_honesty_not_invention(): void
    {
        // STOPPED: the clock is not running once resolved. NONE: policy may set
        // no SLA, and CMP §10 forbids inventing one.
        $extra = array_values(array_diff(ExceptionSlaState::ALL, ExceptionSlaState::OPS_104));

        $this->assertSame(['stopped', 'none'], $extra);
    }

    public function test_only_overdue_is_a_breach(): void
    {
        $this->assertTrue(ExceptionSlaState::isBreached('overdue'));

        foreach (['on_track', 'at_risk', 'stopped', 'none'] as $s) {
            $this->assertFalse(ExceptionSlaState::isBreached($s), "{$s} is not a breach");
        }
    }

    public function test_a_stopped_or_unset_clock_is_not_live(): void
    {
        $this->assertFalse(ExceptionSlaState::isLive('stopped'));
        $this->assertFalse(ExceptionSlaState::isLive('none'));
        $this->assertTrue(ExceptionSlaState::isLive('overdue'));
    }

    public function test_every_sla_state_has_a_label(): void
    {
        foreach (ExceptionSlaState::ALL as $s) {
            $this->assertArrayHasKey($s, ExceptionSlaState::LABELS);
        }
    }

    /* ══════════ the trip edge this ticket opens ══════════ */

    public function test_in_transit_is_declared_on_the_trip_machine(): void
    {
        $this->assertContains(TripStatus::IN_TRANSIT, TripStatus::ALL);
    }

    public function test_no_enum_here_collides_with_a_trip_status(): void
    {
        // `closed` exists on both machines and means different things. The
        // collision is real; this asserts we know about it rather than
        // discovering it through a mis-set column.
        $shared = array_intersect(ExceptionStatus::ALL, TripStatus::ALL);

        $this->assertSame(
            ['closed'], array_values($shared),
            'a new overlap between exception and trip vocabularies needs a deliberate decision',
        );
    }
}
