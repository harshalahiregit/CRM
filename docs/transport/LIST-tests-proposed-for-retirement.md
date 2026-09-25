# The 42 — what I propose to do with each, before doing any of it

**Nothing in this list has been removed.** 25 September 2026, P1.

My earlier framing of "42 assertions of one fact" was wrong in the same way your first assumption
was: reading them properly, they fall into **four** groups, not three. The fourth is the largest.

---

## Group 1 — reads. Unchanged, must keep passing. (5)

| Test | File |
|---|---|
| `status_counts_cover_every_declared_state` | MasterApi |
| `vehicle_view_is_allowed_for_every_internal_role` | MasterAllocationAudit ✓ already green |
| `a_client_cannot_reach_the_master_surface_at_all` | MasterAllocationAudit |
| `a_tenant_cannot_read_or_write_another_tenants_master_records` | MasterAllocationAudit |
| `the_same_registration_may_exist_in_two_tenants` | MasterAllocationAudit |

Two of these are named "read **or write**" and will need splitting: the read half stays as is, the
write half moves to Group 2. A read going red is a regression, not a consequence of the ruling.

## Group 2 — writes become refusals. Six, not forty. (replaces 11)

One per endpoint, asserting the refusal is **route-level** and readable:

```
vehicle create · vehicle update · vehicle transition · vehicle delete
driver  create · driver  update
```

Each asserts: the route is not registered; the response names **where vehicles are created now**;
and — per your instruction, and stronger than the matrix it replaces — **no role bypasses it,
owner and admin included.** A bare 404 or 403 is not acceptable here; we spent Tuesday on a guard
that refused correctly with an unreadable message.

**Retiring, because Group 2 replaces them (11):**

`a_vehicle_can_be_created_listed_and_read` · `a_driver_can_be_created_listed_and_read`
(both split — the list/read half moves to Group 1) · `an_admin_can_edit_a_vehicle_by_putting_the_whole_record_back` ·
`an_admin_can_edit_a_driver_by_putting_the_whole_record_back` · `ordinary_fields_are_editable_and_only_the_guarded_four_are_not` ·
`an_admin_can_delete_a_vehicle_and_a_driver` · `a_dispatcher_may_read_masters_but_not_write_them` (split) ·
`accounts_may_read_masters_but_not_write_them` (split) · `only_owner_or_admin_may_delete_a_master_record` ·
`vehicle_create_and_update_are_owner_operations_admin_only` · `vehicle_delete_is_owner_and_admin_only` ·
`driver_view_create_update_delete_follow_the_same_matrix` (split)

## Group 3 — what the write tests protected. Checked against Fleet, one by one. (7)

| Our test | Fleet's equivalent | Verdict |
|---|---|---|
| `duplicate_registration_is_rejected_with_a_readable_message` | `VehicleOnboardingTest::one_plate_is_one_vehicle_however_it_is_typed` + `a_retired_plate_is_explained_not_silently_rejected` | **retire** |
| `a_vehicle_can_be_updated_but_status_is_not_an_editable_field` | `VehicleOnboardingTest::status_cannot_be_set_by_hand` | **retire** |
| `vehicle_status_moves_only_through_the_transition_endpoint` | `VehicleStatusTransitionTest` — its own header says *"Absorbed from Dev 1's retiring endpoint"* | **retire** |
| `a_document_can_be_filed_and_renewed_against_a_vehicle` | `VehicleDocumentTest` (12 tests) | **retire** |
| `a_driver_document_type_cannot_be_filed_against_a_vehicle` | `VehicleDocumentTest::a_driver_document_cannot_be_filed_against_a_vehicle`, and the reverse in `DriverDocumentTest` | **retire** |
| `a_document_cannot_expire_before_it_becomes_valid` | `VehicleDocumentTest` / `DriverDocumentTest` verification flow | **retire** |
| `a_licence_cannot_expire_before_it_starts` | `DriverDocumentTest::verifying_the_licence_is_what_sets_the_expiry` + `an_older_licence_never_moves_the_date_backwards` | **retire** |

### ⚠ One does NOT retire — and it is the finding

**`duplicate_licence_and_duplicate_employee_link_are_rejected`**

Measured, both sides:

- `StoreTransportDriverRequest` enforces uniqueness on `licence_normalized`, `driver_code` and
  `hr_employee_id`, scoped to the tenant.
- **Fleet enforces none of the three.** No uniqueness check in `DriverService`, no Fleet request
  class, and the only unique index on `driver_profiles` is
  `(company_id, source, source_id)` — one profile per *person*, which is a different rule.
- No Fleet test asserts a duplicate licence is rejected. Grep returns nothing.

So **two drivers can be given the same licence number in Fleet today**, and retiring our test
would remove the only thing in the codebase that says they should not be. That is a guard about to
disappear in a cleanup.

**For Shivam, not for us to fix**: it is his master, his request layer and his index. Logged as
D-145. Our test stays red and in place until he answers — a red test naming a real missing guard is
worth more than a green suite that has forgotten it.

## Group 4 — not about the write surface at all. REPAIR, do not rewrite. (19)

This is the group neither of us accounted for. `TransportMasterAllocationAuditTest` mostly uses
creation as **setup** and then tests allocation. Its subject never moved:

```
an_already_assigned_vehicle_cannot_be_given_to_a_second_trip
an_already_assigned_driver_cannot_be_given_to_a_second_trip
simultaneous_requests_for_one_vehicle_leave_exactly_one_winner
the_database_refuses_a_second_active_row_without_the_service
release_then_reassign_keeps_the_old_assignment_as_history
tenant_a_cannot_see_assign_or_release_tenant_b_resources
the_same_vehicle_may_be_active_in_both_tenants_at_once
a_vehicle_with_an_active_assignment_cannot_be_deleted
a_driver_with_an_active_assignment_cannot_be_deleted
deletion_succeeds_once_the_assignment_is_released
the_guard_holds_when_the_service_is_called_directly
an_unassigned_vehicle_still_deletes_freely
a_deleted_master_is_soft_deleted_not_destroyed
expired_insurance_blocks_the_vehicle_with_the_actionable_reason
expired_licence_blocks_the_driver_with_the_actionable_reason
a_document_that_lapses_after_creation_blocks_without_any_sweep
each_driver_axis_transitions_independently
neither_driver_axis_is_editable_through_update
everything_requires_authentication
```

These get the **fixture migration**, exactly like the other ten files: build through Fleet, assert
the same thing. Nothing about their intent changes.

Three of them need a decision of their own, because they test **deleting a master** — and delete is
now Fleet's. They move to Fleet's delete endpoint or they follow Group 2.

---

## Summary

| Group | Action | Count |
|---|---|---|
| 1 | reads, unchanged | 5 (2 to split) |
| 2 | rewritten as six refusal tests | 11 → 6 |
| 3 | retire, covered by Fleet | 6 |
| 3⚠ | **stays red — D-145, a real gap** | 1 |
| 4 | repair the fixtures, intent unchanged | 19 |

**Proposed for removal: 17** (11 in Group 2, 6 in Group 3). Every one of the six in Group 3 has a
named Fleet test taking over. **Awaiting your sight of this list before anything goes.**
