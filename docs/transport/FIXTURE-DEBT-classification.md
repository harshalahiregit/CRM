# Fixture debt — every failing Transport test, classified

**25 September 2026, P1.** Branch `fix/p1-fixture-debt` at `5cfb66bd` (D-150 on top).
`php artisan test --filter=Transport` → **79 failed · 1075 passed · 3 skipped** (matches the expected baseline).
Read-and-report only: no code or test was changed to produce this.

## 1 · Counts

| Bucket | Meaning | Tests |
|---|---|---|
| **A** | Deliberate red — names a gap someone else owns | **10** |
| **B** | Needs a decision — asserts a contract that was changed with no ruling yet | **8** |
| **C** | Fixture debt — setup is legacy; the behaviour under test still exists | **36** |
| **D** | Other | **25** |
| | | **79** |

**The headline is in D, not C.** 20 of the 25 are one **product defect of ours**, not a fixture:

> `PretripService::evaluateDriverDocuments()` borrows the driver verdict's `['licence', 'documents']`
> checks (`PretripService.php:1022`). Since D-134 a driver verdict has only `fleet` and `assignment`,
> so nothing is borrowed and **the pre-trip DRIVER_DOCUMENTS check always passes. A driver whose
> licence lapses after allocation is no longer stopped at pre-trip or at dispatch.** It is the same
> D-134 leftover as `ruleFor()` was. Its expiring-soon branch is dead for drivers too: it reads
> `expiring_soon`, which the driver verdict no longer carries (Fleet's `warnings` replaced it).

This should be a D-number and fixed **before** the fixture work, because it's real and it clears ~20 tests.

The other 5 in D:
- **4 are a stale cache:** `DriverEligibilityService::$fleetCache` holds the Fleet directory for as long as the instance lives. Each test resolves `AllocationService` once in `setUp`, so a driver created later is "not in the fleet directory". Proven: with the cache off in a throwaway worktree, 3 of the 4 pass; the fourth then fails on a C issue (`displayName()`).
- **1 is a false positive:** the demo-seeder guard fires on a Fleet vehicle status write.

## 2 · Every test

| Test | Bucket | Cause | Evidence / replacement |
|---|---|---|---|
| `TransportMasterApiTest::duplicate_licence_and_duplicate_employee_link_are_rejected` | A | the only licence-uniqueness guard; Fleet has none | D-145: "Our test stays **red and in place** until he answers" |
| `TransportMasterAllocationAuditTest::a_driver_with_an_active_assignment_cannot_be_deleted` | A | delete guard, driver side | D-146 (named) |
| `TransportMasterAllocationAuditTest::deletion_succeeds_once_the_assignment_is_released` | A | delete guard, release half | D-146 (named) |
| `TransportMasterAllocationAuditTest::a_vehicle_with_an_active_assignment_cannot_be_deleted` | A | delete guard; Fleet's delete cannot see trip_assignments | D-146: "Not fixed. The three tests stay red and in place." (named) |
| `TransportMasterAllocationAuditTest::expired_licence_blocks_the_driver_with_the_actionable_reason` | A | creates via legacy TransportDriverService → 404; also asserts BRWM §70 wording | PUSHED §3 (the 11). ⚠ see Unsure |
| `TransportMasterAllocationAuditTest::everything_requires_authentication` | A | POST /transport/drivers answers 405 before auth (the D-145 hold) | PUSHED §3 (the 11); 7123e870 msg: "POST /drivers being absent answers 405" |
| `TransportMasterAllocationAuditTest::a_deleted_master_is_soft_deleted_not_destroyed` | A | same: create refused, id null, 405 | PUSHED §3 (the 11); delete surface, waits on D-146 |
| `TransportMasterAllocationAuditTest::an_unassigned_vehicle_still_deletes_freely` | A | POST /vehicles 409 → null id → DELETE /vehicles/ 405 | PUSHED §3 (the 11); delete surface, waits on D-146 |
| `TransportMasterAllocationAuditTest::the_guard_holds_when_the_service_is_called_directly` | A | legacy delete guard called with a Fleet vehicle → TypeError | PUSHED §3 (the 11); same guard as D-146 |
| `DriverDirectoryTest::forty_workers_added_under_a_vendor_appear_in_transport_untouched` | A | asserts `auto` → CrmDriverDirectory; D-134 replaced it with the Composite (P2's test) | PUSHED §3: "D-144(i) — asserts `auto` returns `CrmDriverDirectory`, the rule D-134 deliberately replaced. P2's test, not edited by us." |
| `TransportAllocationApiTest::an_expired_licence_returns_422_with_the_reason` | B | BRWM §70 "assign another eligible driver" absent from Fleet's sentence | D-150 "Left red on purpose" #1 |
| `TransportEligibilityTest::an_expired_licence_blocks_the_driver` | B | same BRWM §70 line, held in the D-150 rewrite | D-150 #1 |
| `TransportEligibilityTest::policy_can_move_a_check_from_blocking_to_advisory` | B | `driver.check.licence.required` no longer does anything | D-150 #3 (CMP §20) |
| `TransportEligibilityTest::a_missing_required_driver_document_blocks` | B | Transport's `driver.required_documents` policy no longer consulted | D-150 #4 |
| `TransportMasterApiTest::a_tenant_cannot_read_or_write_another_tenants_master_records` | B | read half passes; PUT on B's id answers 409 (write refusal) before tenancy → not 404 | LIST Group 1: "will need splitting … the write half moves to Group 2" — not done |
| `TransportMasterApiTest::only_owner_or_admin_may_delete_a_master_record` | B | asserts legacy delete permissions on a retired write surface (id null → 405) | LIST Group 2 names it for replacement by refusal tests — not done |
| `TransportMasterApiTest::the_same_registration_may_exist_in_two_tenants` | B | asserts POST /vehicles → 201; RULING-003 makes it 409 | LIST files it under Group 1 "reads", but it is a write |
| `DispatchTest::version_one_is_the_release_itself` | B | asserts `fleet_state_applied === false` on every release; Fleet's gateway now applies | premise "Fleet did not move" is obsolete since C-05 closed — assert true, or build an unmigrated ref? |
| `DispatchTest::dispatch_does_not_touch_fleet_tables` | C | exp 'allocated' got 'ALLOCATED'; next line reads $driver->availability (no such column) | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `PretripGateTest::releasing_from_pretrip_ok_frees_the_resources` | C | exp 'available' got 'AVAILABLE' | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `PretripGateTest::the_resources_stay_held_after_passing` | C | exp 'allocated' got 'ALLOCATED' | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `TransportAllocationApiTest::release_frees_everything_and_reverts_the_trip` | C | exp 'available' got 'AVAILABLE' | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `TransportAllocationTest::a_vehicle_only_allocation_leaves_the_trip_approved` | C | exp 'allocated' got 'ALLOCATED' | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `TransportAllocationTest::allocating_both_resources_moves_the_trip_to_allocated` | C | exp 'allocated' got 'ALLOCATED' | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `TransportAllocationTest::an_ineligible_driver_blocks_and_leaves_nothing_behind` | C | refusal passes; `$d->availability` is null — DriverProfile has no availability → assert `status` | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `TransportAllocationTest::an_ineligible_vehicle_blocks_and_leaves_nothing_behind` | C | exp 'available' got 'AVAILABLE' | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `TransportAllocationTest::release_then_reallocate_works` | C | exp 'available' got 'AVAILABLE' | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `TransportAllocationTest::releasing_frees_everything_and_reverts_the_trip` | C | exp 'available' got 'AVAILABLE' | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `TransportMasterAllocationAuditTest::a_document_that_lapses_after_creation_blocks_without_any_sweep` | C | 422 passes; fails only on the casing line ⚠ | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `TransportMasterAllocationAuditTest::expired_insurance_blocks_the_vehicle_with_the_actionable_reason` | C | 422 + message pass; fails only on the casing line ⚠ | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `TransportMasterAllocationAuditTest::release_then_reassign_keeps_the_old_assignment_as_history` | C | release/reassign pass up to the casing line ⚠ | C1 · compare with `Vehicle::STATUS_*` (Fleet) not `VehicleStatus::*` |
| `ContainerPassportTest::the_passport_names_the_vehicle_and_driver_when_the_trip_has_them` | C | TransportVehicle/TransportDriver::create; trips hold Fleet ids | C2 · `fleetVehicle()` / `fleetDriver()` from CreatesFleetResources; `Vehicle::find` / `DriverProfile::find` |
| `TransitTest::a_broken_down_vehicle_is_not_freed_by_a_delivery` | C | TransportVehicle::find on a Fleet id → null | C2 · `fleetVehicle()` / `fleetDriver()` from CreatesFleetResources; `Vehicle::find` / `DriverProfile::find` |
| `TransitTest::delivering_a_trip_frees_the_vehicle_and_the_driver` | C | TransportVehicle::find on a Fleet id → null | C2 · `fleetVehicle()` / `fleetDriver()` from CreatesFleetResources; `Vehicle::find` / `DriverProfile::find` |
| `TransportSearchTest::a_driver_name_resolves` | C | TransportDriver::create; search reads the directory | C2 · `fleetVehicle()` / `fleetDriver()` from CreatesFleetResources; `Vehicle::find` / `DriverProfile::find` |
| `TransportSearchTest::a_vehicle_registration_reaches_the_passport_of_what_it_carries` | C | TransportVehicle::create | C2 · `fleetVehicle()` / `fleetDriver()` from CreatesFleetResources; `Vehicle::find` / `DriverProfile::find` |
| `TransportSearchTest::a_vehicle_registration_resolves_however_it_is_spaced` | C | TransportVehicle::create; search reads Fleet | C2 · `fleetVehicle()` / `fleetDriver()` from CreatesFleetResources; `Vehicle::find` / `DriverProfile::find` |
| `TransportSearchTest::a_vehicle_with_no_trips_says_so_instead_of_failing_silently` | C | TransportVehicle::create | C2 · `fleetVehicle()` / `fleetDriver()` from CreatesFleetResources; `Vehicle::find` / `DriverProfile::find` |
| `TransportSearchTest::a_vehicle_with_several_trips_returns_the_list_rather_than_guessing` | C | TransportVehicle::create | C2 · `fleetVehicle()` / `fleetDriver()` from CreatesFleetResources; `Vehicle::find` / `DriverProfile::find` |
| `TransportAllocationRefusalAuditTest::an_unavailable_driver_is_logged_against_br_p0_004` | C | transitionAvailabilityTo TypeError | C3 · set Fleet's own status on the profile (`DriverProfile::ON_LEAVE / INACTIVE / SUSPENDED`) — no Fleet transition helper exists in tests/Concerns |
| `TransportEligibilityTest::a_blocked_driver_is_not_eligible_and_reports_blocked` | C | transitionStatusTo TypeError; then asserts removed `compliance_status` | C3 · set Fleet's own status on the profile (`DriverProfile::ON_LEAVE / INACTIVE / SUSPENDED`) — no Fleet transition helper exists in tests/Concerns |
| `TransportEligibilityTest::a_driver_on_leave_is_not_eligible` | C | transitionAvailabilityTo(DriverProfile) TypeError; then reads old `availability`/`licence` keys | C3 · set Fleet's own status on the profile (`DriverProfile::ON_LEAVE / INACTIVE / SUSPENDED`) — no Fleet transition helper exists in tests/Concerns |
| `TransportEligibilityTest::an_inactive_driver_is_not_eligible` | C | transitionStatusTo TypeError; then reads old `lifecycle` key | C3 · set Fleet's own status on the profile (`DriverProfile::ON_LEAVE / INACTIVE / SUSPENDED`) — no Fleet transition helper exists in tests/Concerns |
| `TransportEligibilityTest::candidate_listing_returns_only_eligible_drivers` | C | transitionAvailabilityTo TypeError | C3 · set Fleet's own status on the profile (`DriverProfile::ON_LEAVE / INACTIVE / SUSPENDED`) — no Fleet transition helper exists in tests/Concerns |
| `TransportAllocationRefusalAuditTest::a_vehicle_that_is_not_available_is_logged` | C | vehicle(available:false) is allocatable → no refusal | C4 · `moveFleetVehicle($v, Vehicle::STATUS_UNDER_MAINTENANCE)` — Fleet creates vehicles AVAILABLE |
| `TransportAllocationRefusalAuditTest::repeated_refusals_each_leave_their_own_row` | C | same | C4 · `moveFleetVehicle($v, Vehicle::STATUS_UNDER_MAINTENANCE)` — Fleet creates vehicles AVAILABLE |
| `TransportAllocationRefusalAuditTest::the_audit_row_survives_the_refusal_but_nothing_else_is_written` | C | same; also asserts `VehicleStatus::NEW` | C4 · `moveFleetVehicle($v, Vehicle::STATUS_UNDER_MAINTENANCE)` — Fleet creates vehicles AVAILABLE |
| `TransportAllocationRefusalAuditTest::the_row_names_the_actor_the_trip_and_the_resource` | C | same | C4 · `moveFleetVehicle($v, Vehicle::STATUS_UNDER_MAINTENANCE)` — Fleet creates vehicles AVAILABLE |
| `TransportMasterAuditTest::compliance_is_non_compliant_without_a_valid_licence` | C | TransportDriver built with `licence_expiry`, ignored → 'compliant' | C5 · restore `licence_valid_until` — the legacy model's column; 7123e870 renamed it to Fleet's `licence_expiry` |
| `TransportMasterAuditTest::the_expiring_window_is_configurable_for_step_5` | C | same → 'compliant' | C5 · restore `licence_valid_until` — the legacy model's column; 7123e870 renamed it to Fleet's `licence_expiry` |
| `TransportMasterDataTest::licence_validity_covers_every_date_case` | C | same → lapsed licence reads valid | C5 · restore `licence_valid_until` — the legacy model's column; 7123e870 renamed it to Fleet's `licence_expiry` |
| `TransportAllocationTest::release_does_not_overwrite_a_resource_state_it_did_not_set` | C | writes BREAKDOWN into transport_vehicles; the vehicle is a Fleet row | C6 · `moveFleetVehicle($v, Vehicle::STATUS_BREAKDOWN)` |
| `DispatchTest::the_shipped_gateway_is_fleets_and_reports_an_unmigrated_vehicle_honestly` | C | docblock needs a trip pointing at a transport_vehicles row; fixture now builds a Fleet one | C6 · build the unmigrated reference on purpose (legacy row + trip.vehicle_id) |
| `TransportAllocationTest::a_failing_driver_does_not_leave_the_vehicle_allocated` | C | fleetDriver() files a licence by default → driver is fit | C6 · pass `licence_number`/`licence_expiry` null (as D-150 did) |
| `PretripApiTest::a_check_from_another_trip_is_a_404` | D | 2nd driver "not in the fleet directory" | DriverEligibilityService::$fleetCache lives as long as the instance; setUp resolves AllocationService once → a driver created later is missing. Proven: cache off in a scratch worktree → 3 of 4 pass |
| `PretripGateTest::a_re_crewed_trip_must_earn_its_checklist_again` | D | new crew's driver "not in the fleet directory" | DriverEligibilityService::$fleetCache lives as long as the instance; setUp resolves AllocationService once → a driver created later is missing. Proven: cache off in a scratch worktree → 3 of 4 pass |
| `PretripGateTest::regeneration_after_re_allocation_reflects_the_new_crew` | D | same; then calls $v2->displayName() on a Fleet Vehicle (C) | DriverEligibilityService::$fleetCache lives as long as the instance; setUp resolves AllocationService once → a driver created later is missing. Proven: cache off in a scratch worktree → 3 of 4 pass |
| `PretripGateTest::the_full_release_recrew_repass_cycle_works` | D | same | DriverEligibilityService::$fleetCache lives as long as the instance; setUp resolves AllocationService once → a driver created later is missing. Proven: cache off in a scratch worktree → 3 of 4 pass |
| `DispatchApiTest::the_get_explains_a_block_before_the_user_acts` | D | first fails on legacy TransportDriver lookup (C), then this | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `DispatchTest::a_licence_that_expires_after_pretrip_blocks_dispatch` | D | revalidation sees no lapse → dispatched | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `DispatchTest::a_newly_applicable_check_that_fails_still_blocks` | D | revalidate not blocking | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `DispatchTest::fixing_the_lapse_reopens_dispatch` | D | lapse never seen | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `DispatchTest::revalidation_names_the_check_that_lapsed` | D | lapse never seen | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripApiTest::the_gate_refuses_a_blocked_checklist_with_the_reason` | D | first fails on legacy TransportDriver lookup (C), then this | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripEvidenceAuditTest::a_refusal_changes_nothing` | D | no refusal → pretrip_ok | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripEvidenceAuditTest::a_refused_gate_leaves_evidence_that_survives_the_exception` | D | no refusal | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripEvidenceAuditTest::a_revoked_confirmation_is_recorded_in_its_own_right` | D | result never changes → nothing revoked | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripEvidenceAuditTest::no_override_is_ever_recorded` | D | expected blocked, got ready | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripEvidenceAuditTest::the_refusal_records_every_check_not_only_the_failures` | D | no refusal row | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripGateTest::a_blocked_checklist_refuses_with_the_exact_reason` | D | gate never refuses | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripGateTest::a_refusal_changes_no_state` | D | no refusal → trip moved to pretrip_ok | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripGateTest::a_refusal_is_audited_and_survives_the_exception` | D | no refusal row | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripGenerationTest::a_relaxed_check_fails_without_blocking` | D | expected fail, got pass | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripGenerationTest::an_expired_driver_licence_blocks_dispatch` | D | lapsed Fleet licence → check PASS, expected critical_fail | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripGenerationTest::completing_a_blocked_check_never_unblocks_the_trip` | D | expected blocked, got ready | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripGenerationTest::completion_never_alters_the_result` | D | expected critical_fail, got pass | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripGenerationTest::re_evaluation_clears_a_completion_when_the_result_changes` | D | result never changes, so the stamp survives | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `PretripGenerationTest::the_blocked_reason_is_actionable` | D | 0 blockers (also calls $driver->displayName(), absent on DriverProfile) | PretripService:1022 borrows ['licence','documents']; driver verdict has only fleet/assignment since D-134 → DRIVER_DOCUMENTS always PASS |
| `TransportDemoSeederTest::the_seeder_never_writes_a_trip_status_directly` | D | D-63 guard's regex `'status' =>` fires on a Fleet VEHICLE status write (seeder:378), not a trip status | false positive — narrow the regex to trip status, or seed vehicle state through Fleet: a decision |

## 3 · Bucket C, grouped by root cause — proposed order, one commit each

| # | Root cause | Tests | Fix |
|---|---|---|---|
| **C1** | Tests compare a Fleet row's status with Transport's lowercase `VehicleStatus` / `DriverAvailability` constants. The helper refuses to translate casing on purpose (its docblock). | **13** | Assert Fleet's constants (`Vehicle::STATUS_*`). For drivers, assert `DriverProfile::status`, since there is no `availability` column. Mechanical, in 6 files. |
| **C2** | Legacy `TransportVehicle` / `TransportDriver` created or looked up in the test body, while search, passport and transit now read Fleet ids | **8** | `fleetVehicle()` / `fleetDriver()`; `Vehicle::find` / `DriverProfile::find`. The driver name comes from the directory (`FleetResourceName::of`). |
| **C3** | Legacy `TransportDriverService::transition*()` called with a `DriverProfile` → TypeError | **5** | Set Fleet's own status on the profile. 3 of the 5 then also need the D-150-style rewrite (old check keys, `compliance_status`), and the mapping of *blocked* to a Fleet status (SUSPENDED?) needs confirming. |
| **C4** | `vehicle(available: false)` assumes a new vehicle is NEW; Fleet creates it AVAILABLE, so nothing is refused | **4** | `moveFleetVehicle($v, Vehicle::STATUS_UNDER_MAINTENANCE)` in the one helper; also clears `VehicleStatus::NEW`. |
| **C5** | **Our own `7123e870`** renamed `licence_valid_until` → `licence_expiry` in tests that still build the **legacy** `TransportDriver`. The model ignores the unknown attribute, so a lapsed licence reads as valid. | **3** | Restore `licence_valid_until` in these legacy-model tests only. The same commit renamed 53 lines in 15 files, so it's worth a sweep for any other legacy-model site. |
| **C6** | Singles | **3** | `a_failing_driver…`: null licence. `release_does_not_overwrite…`: `moveFleetVehicle(BREAKDOWN)`. `the_shipped_gateway…`: build the unmigrated reference on purpose. |
| | | **36** | |

Recommended sequence: **fix the pretrip defect (D, ~20 tests) first**, then C1 → C2 → C3 → C4 → C5 → C6.
The cache (4) and the seeder guard (1) each need a decision before a fix.

## 4 · The helper most C tests go through

`tests/Concerns/CreatesFleetResources.php` (`fleetVehicle`, `fleetDriver`, `moveFleetVehicle`) is
**ours**: all its commits are P1's (`cff29359`, `e4ed0f6d`). It isn't the cause. Most C tests already
call it through their file's private `vehicle()` / `driver()` helpers. The debt is in what the tests do
*after* setup (casing, legacy lookups, legacy service calls), so no single helper edit clears a large
group. The nearest thing is C4: one private helper, `TransportAllocationRefusalAuditTest::vehicle()`,
for 4 tests. No base `TestCase` is involved.

It lacks one thing: a way to move a **driver** to a Fleet status (the C3 group). Adding
`moveFleetDriver()` beside `moveFleetVehicle()` would be ours to add.

## 5 · Unsure — flagged, not decided

- **Three tests the PUSHED doc lists as deliberate that are really C1.** PUSHED §3 lists all 11 red
  `TransportMasterAllocationAuditTest` tests as "Waiting on the delete decision". Three of them
  (`expired_insurance…`, `a_document_that_lapses…`, `release_then_reassign…`) test allocation, not
  delete. They pass their real assertions and fail only on a casing line. `LIST-tests-proposed-for-retirement.md`
  Group 4 says the same: "REPAIR, do not rewrite". I've put them in C; if you'd rather follow PUSHED,
  they move to A.
- **`expired_licence_blocks_the_driver_with_the_actionable_reason`** is kept in A (it's one of PUSHED's 11).
  But its first failure is a legacy `TransportDriverService::create`, which is C, and behind that it asserts the BRWM §70 wording, which is B.
  It isn't a delete test.
- **B vs C for `version_one_is_the_release_itself` and `the_shipped_gateway…`.** Both assume Fleet does
  not move on release. The gateway test's own docblock names the case it wants (an unmigrated vehicle),
  so building that fixture is C. `version_one` asserts "Fleet did not move" on *every* release, so it
  needs a ruling (B).
- **The stale cache (D):** only a test problem in normal web requests, where the service is resolved per
  request. It would be a real problem in any long-lived process (queue worker, Octane). The fix is
  either the tests re-resolving the service, or the service invalidating its cache on assign. That's a decision.
