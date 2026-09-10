<?php

namespace Tests\Feature\Transport;

use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\PretripReadiness;
use App\Support\Transport\PretripResult;
use App\Support\Transport\PretripScope;
use App\Support\Transport\TripStatus;
use Tests\TestCase;

/**
 * SNG-TRN-010 steps 0 and 1 — the scope ruling and the vocabulary.
 *
 * These tests guard a boundary rather than a behaviour. Ticket 009's audit found
 * three P0 requirements sitting in no list at all — neither built nor deferred,
 * which is precisely how a P0 requirement disappears. The dispositions below are
 * asserted complete so that cannot happen silently again.
 *
 * No database: nothing here touches one.
 */
class PretripScopeTest extends TestCase
{
    /* ══════════ Step 0 · every RTM row is accounted for ══════════ */

    public function test_all_fourteen_rtm_20_rows_are_accounted_for(): void
    {
        $d = PretripScope::RTM_20_DISPOSITION;

        $this->assertCount(14, $d, 'RTM §20 has fourteen rows');

        foreach (range(1, 14) as $n) {
            $id = 'STOS-REQ-OPS-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $this->assertArrayHasKey($id, $d, "{$id} must be in exactly one list");
            $this->assertContains(
                $d[$id],
                ['in_scope', 'deferred', 'done_earlier', 'later_ticket'],
                "{$id} has an unrecognised disposition",
            );
        }
    }

    public function test_all_nine_rtm_29_rows_are_accounted_for(): void
    {
        $d = PretripScope::RTM_29_DISPOSITION;

        $this->assertCount(9, $d, 'RTM §29 has nine rows');

        foreach (range(1, 9) as $n) {
            $id = 'STOS-REQ-CMP-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $this->assertArrayHasKey($id, $d, "{$id} must be in exactly one list");
            $this->assertContains(
                $d[$id],
                ['in_scope', 'deferred', 'done_earlier', 'no_ticket'],
                "{$id} has an unrecognised disposition",
            );
        }
    }

    public function test_the_three_p0_rows_ruled_in_scope_are_in_scope(): void
    {
        $this->assertSame(
            [PretripScope::OPS_004, PretripScope::OPS_005, PretripScope::CMP_006],
            PretripScope::IN_SCOPE,
        );

        foreach (PretripScope::IN_SCOPE as $id) {
            $map = str_starts_with($id, 'STOS-REQ-OPS-')
                ? PretripScope::RTM_20_DISPOSITION
                : PretripScope::RTM_29_DISPOSITION;
            $this->assertSame('in_scope', $map[$id]);
        }
    }

    public function test_in_scope_and_deferred_never_overlap(): void
    {
        $this->assertSame(
            [],
            array_intersect(PretripScope::IN_SCOPE, PretripScope::DEFERRED),
            'a requirement cannot be both built and deferred',
        );
    }

    public function test_ops_008_record_dispatch_is_deferred_with_its_reason(): void
    {
        // The one P0 deferral that costs something. It must be deferred rather
        // than quietly absent, because "Dispatch timestamp/status recorded" has
        // no other owner in the register.
        $this->assertContains(PretripScope::OPS_008, PretripScope::DEFERRED);
        $this->assertSame('deferred', PretripScope::RTM_20_DISPOSITION[PretripScope::OPS_008]);
    }

    /* ══════════ Step 0 · OPS §28's fourteen items ══════════ */

    public function test_all_fourteen_ops_28_items_are_dispositioned(): void
    {
        $d = PretripScope::OPS_28_DISPOSITION;

        $this->assertCount(14, $d, 'OPS §28 names fourteen items across five categories');

        foreach ($d as $key => $disposition) {
            $this->assertTrue(
                PretripCheckKey::isValid($key),
                "{$key} is dispositioned but is not a declared check key",
            );
            $this->assertContains($disposition, ['built', 'declared', 'cross_ref']);
        }
    }

    public function test_ops_28_built_items_are_exactly_the_generated_checks(): void
    {
        $built = array_keys(array_filter(
            PretripScope::OPS_28_DISPOSITION,
            fn (string $d) => $d === 'built',
        ));

        sort($built);
        $generated = PretripCheckKey::GENERATED;
        sort($generated);

        $this->assertSame($generated, $built, 'every built item is generated, and vice versa');
    }

    public function test_every_ops_28_category_is_represented(): void
    {
        $categories = array_unique(array_map(
            fn (string $k) => PretripCheckKey::categoryOf($k),
            array_keys(PretripScope::OPS_28_DISPOSITION),
        ));

        sort($categories);
        $expected = PretripCheckKey::CATEGORIES;
        sort($expected);

        $this->assertSame($expected, $categories, 'all five OPS §28 categories appear');
    }

    /* ══════════ Step 0 · exclusions carry reasons ══════════ */

    public function test_every_exclusion_states_a_reason(): void
    {
        $this->assertNotEmpty(PretripScope::EXCLUDED);

        foreach (PretripScope::EXCLUDED as $subject => $reason) {
            $this->assertIsString($subject);
            $this->assertGreaterThan(
                30,
                strlen($reason),
                "'{$subject}' is excluded without a usable reason",
            );
        }
    }

    public function test_the_five_owner_rulings_are_recorded_as_exclusions(): void
    {
        foreach (['physical inspection', 'trip document engine', 'photo evidence', 'document handover'] as $subject) {
            $this->assertArrayHasKey($subject, PretripScope::EXCLUDED);
        }
        // Q4's reason must name why, not merely that it is out.
        $this->assertStringContainsString('file-upload', PretripScope::EXCLUDED['photo evidence']);
    }

    public function test_offline_is_excluded_despite_the_ticket_dod_asking_for_it(): void
    {
        $this->assertArrayHasKey('offline capture', PretripScope::EXCLUDED);
        $this->assertStringContainsString('SNG-TRN-026', PretripScope::EXCLUDED['offline capture']);
    }

    public function test_the_unratified_sources_are_not_the_basis_of_any_scope(): void
    {
        // The BR-ALLOC-001 lesson: the most vivid description is the memo's.
        // Nothing in scope may be justified by a video script or an unapplied CR.
        foreach (PretripScope::IN_SCOPE as $id) {
            $this->assertStringStartsWith('STOS-REQ-', $id, 'scope comes from the RTM only');
        }
    }

    /* ══════════ Step 0 · Q1, the state ruling, as data ══════════ */

    public function test_q1_ruling_is_recorded_as_constants_not_prose(): void
    {
        $this->assertSame(
            TripStatus::ALLOCATED.'->'.TripStatus::PRETRIP_OK,
            PretripScope::STATE_EDGE_OWNED,
        );
        $this->assertSame(
            TripStatus::PRETRIP_OK.'->'.TripStatus::DISPATCHED,
            PretripScope::STATE_EDGE_DEFERRED,
        );
        $this->assertStringContainsString('Step 9', PretripScope::STATE_RULING_SOURCE);
    }

    public function test_the_owned_edge_uses_states_that_actually_exist(): void
    {
        foreach ([TripStatus::ALLOCATED, TripStatus::PRETRIP_OK, TripStatus::DISPATCHED] as $state) {
            $this->assertTrue(TripStatus::isValid($state));
        }
    }

    public function test_the_owned_edge_is_wired_and_the_deferred_one_is_not(): void
    {
        // Step 6 wired the owned edge. The deferred one must stay dead: dispatch
        // confirmation needs five fields that do not exist and a ticket that
        // does not exist (D-18).
        $this->assertTrue(TripStatus::canTransition(TripStatus::ALLOCATED, TripStatus::PRETRIP_OK));
        $this->assertFalse(TripStatus::canTransition(TripStatus::PRETRIP_OK, TripStatus::DISPATCHED));
    }

    /* ══════════ Step 1 · readiness status, OPS §29 ══════════ */

    public function test_readiness_matches_ops_29_exactly(): void
    {
        $this->assertSame([
            'not_started', 'in_progress', 'ready', 'blocked', 'override_required',
        ], PretripReadiness::ALL, 'OPS §29, in the document order');
    }

    public function test_override_required_is_declared_but_unreachable(): void
    {
        $this->assertContains(PretripReadiness::OVERRIDE_REQUIRED, PretripReadiness::ALL);
        $this->assertNotContains(PretripReadiness::OVERRIDE_REQUIRED, PretripReadiness::REACHABLE);
        $this->assertContains(PretripReadiness::OVERRIDE_REQUIRED, PretripReadiness::UNREACHABLE);
    }

    public function test_reachable_and_unreachable_partition_the_enum(): void
    {
        $this->assertSame(
            PretripReadiness::ALL,
            array_merge(
                array_intersect(PretripReadiness::ALL, PretripReadiness::REACHABLE),
                array_diff(PretripReadiness::ALL, PretripReadiness::REACHABLE),
            ),
        );
        $this->assertSame(
            [],
            array_intersect(PretripReadiness::REACHABLE, PretripReadiness::UNREACHABLE),
        );
    }

    public function test_only_ready_permits_the_transition(): void
    {
        $this->assertTrue(PretripReadiness::permitsTransition(PretripReadiness::READY));

        foreach ([
            PretripReadiness::NOT_STARTED,
            PretripReadiness::IN_PROGRESS,
            PretripReadiness::BLOCKED,
            PretripReadiness::OVERRIDE_REQUIRED,
        ] as $status) {
            $this->assertFalse(
                PretripReadiness::permitsTransition($status),
                "BRW-046: {$status} must not permit dispatch",
            );
        }
    }

    public function test_every_readiness_value_has_a_label(): void
    {
        foreach (PretripReadiness::ALL as $status) {
            $this->assertNotSame($status, PretripReadiness::label($status));
        }
    }

    public function test_cmp_158_variant_is_mapped_rather_than_discarded(): void
    {
        $map = PretripReadiness::CMP_158_MAP;

        $this->assertCount(4, $map, 'CMP §158 has four values');
        $this->assertSame(PretripReadiness::READY, $map['READY']);
        $this->assertSame(PretripReadiness::BLOCKED, $map['BLOCKED']);
        // The one CMP value §29 cannot express is recorded as null, not guessed.
        $this->assertNull($map['READY WITH EXCEPTION']);
    }

    /* ══════════ Step 1 · item result, FLEET §88 ══════════ */

    public function test_results_88_are_fleet_88_verbatim_and_pending_is_not_among_them(): void
    {
        $this->assertSame(
            ['pass', 'pass_warning', 'fail', 'critical_fail'],
            PretripResult::RESULTS_88,
        );
        $this->assertNotContains(
            PretripResult::PENDING,
            PretripResult::RESULTS_88,
            'PENDING is this module addition, not a FLEET §88 value',
        );
        $this->assertContains(PretripResult::PENDING, PretripResult::ALL);
    }

    public function test_only_critical_fail_blocks(): void
    {
        $this->assertTrue(PretripResult::blocks(PretripResult::CRITICAL_FAIL));

        foreach ([
            PretripResult::PENDING,
            PretripResult::PASS,
            PretripResult::PASS_WARNING,
            PretripResult::FAIL,
        ] as $result) {
            $this->assertFalse(
                PretripResult::blocks($result),
                "BRW-052: a non-critical {$result} warns, it does not block",
            );
        }
    }

    public function test_brw_052_maps_criticality_to_the_failure_grade(): void
    {
        $this->assertSame(PretripResult::CRITICAL_FAIL, PretripResult::forFailure(true));
        $this->assertSame(PretripResult::FAIL, PretripResult::forFailure(false));
    }

    public function test_pass_and_pass_with_warning_both_satisfy_their_item(): void
    {
        $this->assertTrue(PretripResult::satisfied(PretripResult::PASS));
        $this->assertTrue(PretripResult::satisfied(PretripResult::PASS_WARNING));
        $this->assertFalse(PretripResult::satisfied(PretripResult::FAIL));
        $this->assertFalse(PretripResult::satisfied(PretripResult::CRITICAL_FAIL));
        $this->assertFalse(PretripResult::satisfied(PretripResult::PENDING));
    }

    public function test_a_pending_result_is_never_gradeable_input(): void
    {
        $this->assertNotContains(PretripResult::PENDING, PretripResult::GRADED);
    }

    /* ══════════ Step 1 · the check vocabulary ══════════ */

    public function test_every_declared_key_has_label_source_and_category(): void
    {
        foreach (PretripCheckKey::ALL as $key) {
            $this->assertArrayHasKey($key, PretripCheckKey::LABELS, "{$key} has no label");
            $this->assertArrayHasKey($key, PretripCheckKey::SOURCES, "{$key} names no source document");
            $this->assertContains(
                PretripCheckKey::categoryOf($key),
                PretripCheckKey::CATEGORIES,
                "{$key} belongs to no OPS §28 category",
            );
        }
    }

    public function test_generated_and_unreachable_partition_the_vocabulary(): void
    {
        $union = array_merge(PretripCheckKey::GENERATED, PretripCheckKey::UNREACHABLE);
        sort($union);
        $all = PretripCheckKey::ALL;
        sort($all);

        $this->assertSame($all, $union, 'every key is either generated or declared-only');
        $this->assertSame(
            [],
            array_intersect(PretripCheckKey::GENERATED, PretripCheckKey::UNREACHABLE),
        );
    }

    public function test_exactly_five_checks_are_generated(): void
    {
        $this->assertCount(5, PretripCheckKey::GENERATED);
        $this->assertCount(23, PretripCheckKey::ALL);
    }

    public function test_the_generated_checks_are_the_ones_with_data_behind_them(): void
    {
        $this->assertSame([
            'commercial.order_approved',
            'driver.assigned',
            'driver.documents_valid',
            'vehicle.assigned',
            'vehicle.compliance_valid',
        ], PretripCheckKey::GENERATED);
    }

    public function test_q2_physical_inspection_keys_are_declared_and_unreachable(): void
    {
        foreach ([
            PretripCheckKey::VEHICLE_TYRES,
            PretripCheckKey::VEHICLE_LIGHTS,
            PretripCheckKey::VEHICLE_BRAKES,
            PretripCheckKey::VEHICLE_ENGINE,
            PretripCheckKey::VEHICLE_FUEL,
            PretripCheckKey::VEHICLE_SAFETY,
            PretripCheckKey::REEFER_GENSET,
            PretripCheckKey::REEFER_TEMPERATURE,
            PretripCheckKey::REEFER_EQUIPMENT,
        ] as $key) {
            $this->assertTrue(PretripCheckKey::isValid($key), "{$key} must be declared");
            $this->assertFalse(PretripCheckKey::isGenerated($key), "Q2: {$key} must not be generated");
        }
    }

    public function test_q5_handover_is_declared_and_unreachable(): void
    {
        $this->assertTrue(PretripCheckKey::isValid(PretripCheckKey::DOCUMENTS_HANDOVER));
        $this->assertFalse(PretripCheckKey::isGenerated(PretripCheckKey::DOCUMENTS_HANDOVER));
    }

    public function test_required_documents_is_a_cross_reference_not_a_duplicate_check(): void
    {
        // Q3: the required-document set is evaluated inside the two eligibility
        // document checks. A third check would report the same fact twice.
        $this->assertSame(
            'cross_ref',
            PretripScope::OPS_28_DISPOSITION[PretripCheckKey::DOCUMENTS_REQUIRED],
        );
        $this->assertFalse(PretripCheckKey::isGenerated(PretripCheckKey::DOCUMENTS_REQUIRED));
        $this->assertTrue(PretripCheckKey::isGenerated(PretripCheckKey::DRIVER_DOCUMENTS));
        $this->assertTrue(PretripCheckKey::isGenerated(PretripCheckKey::VEHICLE_COMPLIANCE));
    }

    public function test_check_keys_are_namespaced_by_their_category(): void
    {
        foreach (PretripCheckKey::ALL as $key) {
            $this->assertStringStartsWith(
                PretripCheckKey::categoryOf($key).'.',
                $key,
                "{$key} must be prefixed with its own category",
            );
        }
    }

    public function test_no_check_key_collides_with_an_eligibility_check_key(): void
    {
        // The eligibility services use bare keys (status, documents, capacity).
        // Pre-trip keys are dotted, so a verdict from either service can never be
        // mistaken for the other when both appear on the same screen.
        foreach (PretripCheckKey::ALL as $key) {
            $this->assertStringContainsString('.', $key);
        }
    }
}
