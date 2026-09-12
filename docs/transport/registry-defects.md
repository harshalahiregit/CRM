# Sangoe Transport OS — Registry Defect Register

Defects found in the STOS specification package (`/home/mohammad-raza/Documents/new module`)
while implementing the Transport module. **These are defects in the SPECIFICATION, not in the
code.** Each was found during implementation, worked around in a documented way, and needs a
ruling from the owner named against it.

Kept in the repo because several of these existed only in a chat transcript, which is not a
durable place for a Critical-severity registry defect (found by audit, 2026-09-07).

Authority for who rules on what: the Conflict Resolution matrix in
`Sangoe_Transport_OS_Master_Developer_Handover_Document_Authority_Register_2026.xlsx`.

| ID | Area | Severity | Owner | Status |
|----|------|----------|-------|--------|
| D-1 | Ticket traceability | High | Step 12 maintainer | Open |
| D-2 | DB entity mapping | Critical | System Architect | Open — worked around |
| D-3 | DB field registry | Critical | System Architect | Open — worked around |
| D-4 | Enum registry | High | Product + Compliance | Open — worked around |
| D-5 | Sprint planning | Low | Product | Open — no action needed |
| D-6 | DB / API contract | Critical | System Architect | Open — resolved by authority order |
| D-7 | Index registry | High | System Architect | Open — worked around |
| D-8 | Permission registry | Critical | System Architect | **Open — worked around, rows FLAGGED in code** |
| D-9 | Field definition | Medium | Product | Open — column built, no values |
| D-10 | API contract refs | High | System Architect | Open |
| D-11 | Missing document | **Critical** | Document control | **Open — the API spec does not exist** |
| D-12 | API registry coverage | High | System Architect + BE lead | Open — paths ruled by owner |
| D-13 | API numbering | Medium | System Architect | Open — Step 11 wins |
| D-14 | Referential integrity | High | — (code defect, not registry) | ✅ **RESOLVED 2026-09-09** |
| D-15 | Ticket refs are fabricated | **Critical** | System Architect | Open — worked around |
| D-16 | Readiness/result enums | High | Product + Compliance | Open — resolved by authority order, mapped in code |
| D-17 | Trip state machine split | **Critical** | Architecture + Product | ⚠️ **Ruled for one edge 2026-09-09 — Blocker 2 still open** |
| D-18 | Dispatch confirmation has no ticket | High | Step 12 maintainer | Built 2026-09-10 under owner authorization; ticket still absent |
| D-19 | Ticket 010 DoD demands offline | Medium | Product + QA | Open — offline excluded, DoD unachievable |
| D-20 | Checklist items with no data model | High | Product | Open — declared, unreachable |
| D-21 | No pre-trip permission; role conflict | **Critical** | Security + Architecture | Open — **rows derived and FLAGGED in code** |
| D-22 | Photo evidence, no upload capability | High | Product | Open — deferred by owner |
| D-23 | A second dispatch-block rule has no owner | Medium | Product + Architecture | Open — recorded only |
| D-24 | Ticket 005 has no requirement anywhere | **Critical** | Product + Step 12 maintainer | Open — build held |
| D-25 | Lane has no entity, yet keys the rate card | **Critical** | System Architect | Open |
| D-26 | Rate entity: four names, zero specification | **Critical** | System Architect | Open |
| D-27 | Rate lifecycle + approval have nowhere to live | High | Architecture + Product | Open |
| D-28 | Vendor/buy-side rates named once, specified nowhere | Medium | Commercial + Product | Open |
| D-29 | Six exception lifecycles; Step 11 contradicts itself | **Critical** | Architecture + Product | Open — blocks ticket 013 |
| D-30 | Exception state `waived` required but undefined | High | Product | Open — blocks ticket 013 |
| D-31 | Exception permission key with no matrix row | **Critical** | Security + Architecture | Open |
| D-32 | Financial/customer impact fields with no calculator | High | Product + Finance | Open |
| D-33 | Exception→task needs another module's Task Engine | Medium | Product + Architecture | Open |
| D-34 | SLA needs a business calendar that does not exist | High | Product | Open |
| D-35 | Ticket 013's five registry references all point at wrong rows | High | Step 12 maintainer | Open — corrected in `ExceptionScope` |
| D-36 | Step 9 has `arrived`; Step 11 STT-007 skips it | Medium | Architecture | Open — 014's to resolve |
| D-37 | Exception category is a required field with no enum | Medium | Architecture | Open — OPS §88 used |
| D-38 | 25 P0 container/LR/DO requirements with no ticket | **Critical** | Step 12 maintainer | Open — blocks Block 1 scope |
| D-39 | Step 9's canonical domain model omits Consignment and Container | **Critical** | Architecture | ✅ **RULED 2026-09-12 — Option A1, approval granted** |
| D-40 | `container_number` uniqueness contradicts required historical reuse | High | Architecture + Product | ✅ **RULED 2026-09-12 — Option B1, master + association** |
| D-41 | LR and DO have two candidate homes; DO is in no enum | High | Architecture | ✅ **RULED 2026-09-12 — LR/DO stay documents; requested from Person 3** |
| D-42 | `CTD-009` gate/port records have no entity anywhere | Medium | Product + Architecture | Deferred — logged, P1 |
| D-43 | `CTD-014` urgent-trip records depend on an unowned P0 | Medium | Step 12 maintainer | Deferred — logged, P1 |

---

## D-1 — Ticket SNG-TRN-009's traceability references do not resolve

`SNG-TRN-009` cites `FRS-P0-009`, `BR-008`, `STATE-002`, `EV-005`. Verified across all 42 documents:

| Cited | Exists? | The real reference |
|---|---|---|
| `FRS-P0-009` | no | `TRP-P0-003` (vehicle), `TRP-P0-004` (driver) |
| `BR-008` | yes, but in STOS-BRM it means **"Auditability"** | `BR-P0-003`, `BR-P0-004` |
| `STATE-002` | no | `STT-004` (`approved → allocated`) |
| `EV-005` | no | `EVT-005` (`TripAssigned`) |

**Impact:** non-negotiable rule 7 ("every change traceable to requirement → ticket → test →
release") is unsatisfiable as written for this ticket. Implementation traced to the real IDs
instead; recorded in `app/Support/Transport/AllocationScope.php`.

## D-2 — Tickets 003/004 point at the wrong canonical tables (off-by-one)

| Ticket | Cites | That entity actually is | Should cite |
|---|---|---|---|
| 003 Vehicle master | `DB-003` | `trip_assignments` | `DB-004` `vehicles` |
| 004 Driver master | `DB-004` | `vehicles` | `DB-005` `drivers` |

Read literally, ticket 003 would build the allocation table. Proceeding on the sensible reading.
Recorded in `2026_12_16_000006_create_transport_drivers_table.php`.

## D-3 — `DB_Fields` specifies zero columns for DB-003, DB-004 and DB-005

All 20 `FLD-*` rows describe other entities. `trip_assignments`, `vehicles` and `drivers` are
LOCKED registry entities with **no canonical schema**. Every column of `transport_vehicles` and
`transport_drivers` therefore derives from reference tier (STOS-FLEET §6/§9/§10, STOS-DB
§39/§42-44, CMP §22, BRM BR-044/045/048, CTD §21, INT §76, Step 2 BO-009).

This is the same gap as the missing `containers` entity (blocking decision 3), repeating for the
three tables allocation depends on.

## D-4 — No canonical enum for vehicle status, ownership type, or compliance status

Step 11 `Enums` covers `ENUM-001…008` (trip, advance, exception, expense, document, risk,
viability). Nothing for vehicles or drivers. FLEET §7's 13 vehicle states are labelled
*"Recommended"*, i.e. reference tier. Same for CMP §23's five driver compliance states, which are
**named but never defined** — no threshold for `EXPIRING`, no trigger for `UNDER_REVIEW`, no stated
distinction between `BLOCKED` and `NON_COMPLIANT`.

Recorded in `VehicleStatus.php`, `VehicleOwnership.php`, `DriverComplianceStatus.php`.

## D-5 — Sprint plan and Step 12 disagree on sprint contents

The Backlog & Sprint Plan puts Vehicle/Driver in "Sprint 1 — Master Data" and Transport Order in
"Sprint 2"; Step 12 puts masters in S2 and Order in S3. Step 12 is the implementation authority
and wins. **No action needed** — recorded so the discrepancy is not rediscovered as a problem.

## D-6 — Step 5's data model contradicts Step 11

Step 5 `01_DB_Schema` defines **separate `vehicle_documents` and `driver_documents` tables with
UUID primary keys**; Step 11 defines a single `transport_documents` (DB-019) with `id`. Step 5 also
uses a third table-naming scheme (`trips`, `policies`, `transport_customers`).

Steps 1–8 are reference tier and cannot override Step 11, so Step 11 wins: one polymorphic
`transport_documents`, bigint keys. Recorded because Step 5 is the most schema-like document in the
package and a future reader will hit it and assume it is authoritative.

## D-7 — `driver_code` is mandated by an index rule with no field behind it

STOS-DB §152 requires an index on *"driver code/employee reference"* and §16 requires every major
entity to have a *"public/reference number"* — but Step 11 defines no such column for DB-005
(see D-3). `transport_drivers.driver_code` exists to satisfy an index rule whose column the
registry never specified.

## D-8 — The Vehicle and Driver masters have no permission rows at all

Step 11's `Permissions` sheet has 13 rows covering only Trip, Advance, Expense, POD, Collection,
ControlRoom and Registry. **No `Vehicle` or `Driver` domain exists**, and the API registry
(`API-001…015`) has no master-data or candidate-listing endpoint.

Tickets 003 and 004 are marked `BE/FE`, so they require an API surface — but there is no canonical
permission to gate it on, and inventing one would violate FORBID-001.

**This is the one defect that actively blocks work**: the FE half of tickets 003/004 cannot be
built until a permission is ruled. Candidate-listing for SNG-TRN-009 step 7 is planned to reuse
`transport.trip.assign` (PERM-004) rather than invent a key — a dispatcher who may assign must be
able to see what they can assign.

---

## Related: deferred scope

Machine-readable in `app/Support/Transport/AllocationScope.php` (`DEFERRED`, `EXCLUDED`,
`RTM_17_DISPOSITION`). Narrative deferrals live in the docblocks of the migration or enum that
owns each subject. Notable ones:

- **Trailer** — ticket 003's text names it; no trailer entity exists in Step 11 (ruled out of scope).
- **ENUM-006 shortfall** — no dedicated value for RC, PUC, National Permit, Tax or Inspection
  (vehicles) or ID and training (drivers); all collapse to `vehicle_doc` / `driver_doc`.
  Extending a LOCKED enum is a Product+Compliance change, not a developer's.
- **Renewal tasks** — FLEET §12 and BRWM require expiry to create a renewal task. No P0 ticket owns
  a transport task engine.
- **Attendance-derived availability** — STOS-DB §120 places attendance in HR and has STOS consume
  it. Implementing that means reading the HR module, which the owner has ruled out of bounds. This
  is a **deliberate divergence from the spec**, taken to obey a standing constraint.
- **Driver PII masking** — SEC §41 and CTD §21 require field-level RBAC on driver contact data. No
  P0 ticket owns it; no masking layer was invented.


---

## D-9 — `allocation_type` is a field with no defined values

STOS-DB §47 lists "allocation type" among the allocation-history fields. That phrase occurs
**exactly once in all 42 documents** and is never defined — no enum, no examples, no change rule.

`trip_assignments.allocation_type` exists as a nullable string so the table needs no ALTER when the
values are ruled, but **no enum was invented**. Same treatment as `transport_documents.source`,
where §25 lists sources without giving a canonical enum.

**Needs:** Product to define the values, or confirm the field is free text.

## D-10 — API-004's request and response contracts do not resolve

`API-004` names `ASN-REQ-001` as its Request Ref and `ASN-RES-001` as its Response Ref. Searched all
42 documents: **neither identifier appears anywhere else**. The only contract detail that exists is
CTR-007/008, which specify two body fields and nothing about the response.

The endpoint therefore follows the platform's existing envelope (`{status, message, data}`), which is
what every other Transport endpoint already returns — a convention, not a guess, but not the
specified contract either, because there isn't one.

## D-11 — The API specification document is missing from the package

`16.STOS-API v1.0.docx` **contains the Artificial Intelligence specification.** Its first two lines
are `SANGOE TRANSPORT OS` / `ARTIFICIAL INTELLIGENCE & INTELLIGENCE ARCHITECTURE SPECIFICATION`,
followed by `STOS-AI v1.0`, and its extracted text is identical to `15.STOS-AI v1.0.docx` — same
MD5 on the text, 48,675 bytes each.

**Correction, 2026-09-09.** This entry previously said the two files were "byte-identical (verified
by MD5)". That is wrong and the claim has been withdrawn: re-verified directly against the folder,
the two `.docx` files have *different* MD5s (`5b074a65…` and `7b9e0bba…`) because their zip
containers differ. It is the CONTENT that is duplicated, not the file. The substance of the defect
is unaffected — and is in fact easier to check, since the wrong title is visible on the first line.

So the package ships 42 documents but only 41 distinct specifications, and **the missing one is the
API spec** — which is why D-10 cannot be resolved from the folder. This remains the most
consequential documentation defect found: an entire named deliverable is absent.

**Needs:** Document control to supply the real STOS-API document.

## D-12 — No candidate-listing and no release endpoint exist in any API list

Step 11's registry runs API-001…015; Step 4's parallel list runs API-001…012. Neither contains an
endpoint for listing eligible vehicles/drivers, nor one for releasing an assignment.

But **PLN-002 and PLN-003 are P0** ("Only eligible vehicles/drivers suggested") and cannot be met
without a way to ask, and an allocation that can be made but never undone is not a workflow.

Paths ruled by the owner on 2026-09-08, chosen to add as little as possible:
- `GET /trips/{trip}/candidates` — one call returning both collections
- `DELETE /trips/{trip}/assign` — reuses API-004's own path rather than inventing a second noun

Both gated on `transport.trip.assign` (PERM-004), since no separate permission exists to express a
narrower read (see D-8).

## D-13 — Step 4 and Step 11 disagree on API numbering

Step 4 lists assign as `API-005` and viability as `API-004`; Step 11 lists assign as `API-004` and
viability as `API-003`. The two lists are offset by one from API-003 onwards.

Step 11 is the canonical technical authority and wins. Recorded because a reader tracing a Step 4
reference will land on the wrong endpoint.

## D-14 — Deleting an assigned master orphaned its allocation ✅ RESOLVED

**This was a defect in our code, not in the registry.** Found by audit on 2026-09-09.

A vehicle or driver could be deleted while **actively assigned to a trip**. The assignment row
survived — history was never destroyed — but its `vehicle()` / `driver()` relation resolved to null,
and the trip went on reporting itself crewed by a record no ordinary query could find.

That violates a rule the folder states plainly:
- **STOS-DB §156** — "Critical relationships must not silently become orphaned."
- **STOS-DB §157** — names this exact case among the orphans a system must detect:
  *"vehicle allocation without vehicle"*.

**Fix.** `TransportVehicleService::delete()` and `TransportDriverService::delete()` now refuse when an
ACTIVE assignment exists, naming the trip:

> "This vehicle is currently assigned to trip TRP-2026-000042. Release the assignment first, or
> retire the vehicle instead."

**Blocked, not cascaded — deliberately.** Releasing an assignment frees a vehicle and a driver and
moves a trip's state. Doing all of that silently inside a delete would hide one destructive act
inside another, and the person deleting a duplicate record is rarely the person who should decide
that a live trip loses its vehicle. FLEET §7 gives Retire/Sold as a vehicle's normal end of life
anyway; deletion is for a record created in error.

Only ACTIVE assignments block. Released ones are history and must not pin a master forever.

Enforced at the **service layer**, so the rule holds for a console command or a future caller as much
as for the API. Covered by five tests in `TransportMasterAllocationAuditTest`.

---

## RESOLVED — allocation refusals now leave evidence

Not a registry defect: a specified requirement that is **not yet implemented**.

Both hard rules name an audit artefact in their Audit Evidence column:

| Rule | Audit Evidence required |
|---|---|
| BR-P0-003 (vehicle overlap, Hard/Critical) | **"Allocation conflict log"** |
| BR-P0-004 (driver unavailable / expired document, Hard/Critical) | **"Document status + override"** |

Today a refused allocation throws before the transaction opens, so it writes **zero** audit rows and
nothing to the `transport` log channel — verified empirically. Blocking correctly is only half of
each rule; both also require the block to leave a trace.

**Built 2026-09-09.** `AllocationService::assertEligible()` writes a
`transport.allocation.refused` entry against the TRIP before throwing, and — critically — it does so
**outside `DB::transaction()`**, so the row survives the exception rather than rolling back with the
refusal it exists to record.

The context carries `rule` (BR-P0-003 / BR-P0-004 / null), `sources` (what governs the refusal when
no BR-P0 rule does), the resource, every check with its outcome, and `document_status` — which
document, valid until when, valid or not — as structured data rather than a sentence.

Ruled by the owner: **all five eligibility refusals are logged**, not only the two the rules mandate,
because a trail that records a document refusal but drops a capacity one is worse than either.
Precondition and validation failures (wrong trip state, no resource supplied) stay unlogged — they
are not allocation conflicts.

| Column | Status |
|---|---|
| BR-P0-003 "Allocation conflict log" | ✅ **satisfied** |
| BR-P0-004 "Document status …" | ✅ **satisfied** |
| BR-P0-004 "… + override" | ⚠️ **partial** — PLN-007 is P1, so no override can exist. The key is present and `null` so the shape is right when that ticket lands, rather than reading as "no override was used" when the truth is "overrides do not exist yet". |

Covered by 14 tests in `TransportAllocationRefusalAuditTest`.

---

## D-15 — Ticket SNG-TRN-010's registry references are a counter, not pointers

`SNG-TRN-010` cites `FRS-P0-010`, `BR-009`, `DB-009`, `API-006`, `EV-006`. Every one misresolves:

| Cited | Actually resolves to |
|---|---|
| `FRS-P0-010` | **Does not exist** in any of the 42 documents. The real requirement is FRS `TRP-P0-005`. |
| `BR-009` | *"Family/Promoter Dispute Risk"* — a row in the **business risk model**, unrelated to transport. |
| `DB-009` | `trip_documents` — "LR/POD/EWB/attachments index". Not a checklist. |
| `API-006` | `POST /trips/{trip}/expenses` — expense submission. |
| `EV-006` | `AdvanceRequested`. |

This generalises D-1 and D-2 from "some refs are wrong" to **the refs are not references at all**.
Proof, which does not depend on judgement: the ticket pack cites up to **`EV-021`** when Step 11's
event registry defines **twelve** events, and up to **`API-020`** when it defines **fifteen**. They
are a sequential counter over tickets that happens to collide with real IDs at the low end — which
is why `EV-005` looked correct for ticket 009 and misled the earlier audit.

**Consequence for this ticket:** there is no canonical entity, API, event or permission for pre-trip
checks anywhere in the LOCKED baseline. STOS-DB describes 100+ entity sections — including
`tyre_inspections` at §56 — and no checklist either. The only document that names a table is Step 5
(`trip_checklists`, zero columns, and *below* Step 11 in authority).

`trip_pretrip_checks` is therefore built under Step 9's Change Control **Class D** ("new entity,
relationship, state transition" — *"Allowed without architecture review? No"*). **FLAGGED for
Architect ratification.**

---

## D-16 — Three documents define the readiness enum three different ways

| Source | Values |
|---|---|
| OPS §29 "READINESS STATUS" | `NOT_STARTED · IN_PROGRESS · READY · BLOCKED · OVERRIDE_REQUIRED` |
| CMP §158 "COMPLIANCE READINESS RESULT" | `READY · READY WITH EXCEPTION · NOT READY · BLOCKED` |
| FLEET §88 "INSPECTION RESULT" | `PASS · PASS WITH WARNING · FAIL · CRITICAL FAIL` |

Step 11's `Enums` sheet carries **none** of them, so there is no canonical list to defer to.

**Worked around by authority order and by level.** FLEET §88 is not really a competitor — it grades
one inspection, not a checklist — so it became `PretripResult` (per item). OPS §29 governs the
checklist status (`PretripReadiness`) because STOS-OPS owns the dispatch process and because §29
alone distinguishes "nobody started" from "started, unfinished". CMP §158 is **mapped, not
discarded** (`PretripReadiness::CMP_158_MAP`); its one inexpressible value, *READY WITH EXCEPTION*,
is recorded as an explicit `null` rather than smoothed over.

One addition is flagged: `PretripResult::PENDING`. §88 grades a *completed* inspection and has no
word for one not yet done. It is kept out of `RESULTS_88` so the boundary stays visible.

---

## D-17 — The trip state machine diverges at exactly this transition ⚠️ ruled for one edge

```
Step 9  (Product Constitution)  APPROVED → ALLOCATED → PRETRIP_OK → DISPATCHED   two edges
Step 11 (Canonical Registry)    approved → allocated → dispatched                one edge, STT-005
Step 5  (Implementation Pack)   assigned → precheck → ready
LSM §33/§34                     PRE_TRIP_CHECK, plus a `BLOCKED` trip state in no enum
```

Ticket 009 never had to choose — `approved → allocated` is identical in Step 9 and Step 11.
**SNG-TRN-010 is the first ticket where the two genuinely diverge**, which is why Blocker 2 stopped
being theoretical here.

**RULED 2026-09-09 by the owner, for this transition only:** Step 9 wins, consistent with every
prior ruling. `PRETRIP_OK` — declared and dead since SNG-TRN-007 — is now live.
`allocated → pretrip_ok` is wired and guarded; `pretrip_ok → dispatched` stays declared and unwired
(see D-18). Recorded as data in `PretripScope::STATE_EDGE_OWNED` / `STATE_EDGE_DEFERRED`.

**What is NOT resolved.** Neither of Step 9's two edges has an `STT-*` row of its own — STT-005 is
the only registry transition covering this ground, and under the ruling its precondition and side
effect land on the first edge while its destination lands on the second. And **Blocker 2 as a whole
remains open**: the Order machine (Step 9's six states vs Step 11's four) has never been formally
ruled, and working code rests on that reading. Still needs **Architect sign-off**.

---

## D-18 — No ticket owns dispatch confirmation

The register runs **010 Pre-trip → 011 Advance → 012 Cost**. Nothing owns:

- **FRS `TRP-P0-006`** "Dispatch confirmation" — ETD, ETA/TAT, pickup contact, destination,
  instructions; freeze on release; version any later change. **None of those five fields exists on
  `transport_trips`.**
- **BRW-046…050**, including BRW-050's side effects (vehicle → In Operation, driver → On Trip,
  start GPS/reefer monitoring, activate SLA).
- **`STT-006`** `dispatched → in_transit`.
- **RTM `STOS-REQ-OPS-008`** "Record dispatch" — P0, acceptance *"Dispatch timestamp/status
  recorded"*.

**Deferred by the owner 2026-09-09.** Building it would mean inventing five columns and a versioning
rule no approved document specifies. What SNG-TRN-010 *does* satisfy is STT-005's stated side effect,
"Record departure readiness": `pretrip_ok` is reached with an actor and a timestamp against every
check.

**Known consequence, recorded rather than fixed:** `PretripService::GENERATABLE_FROM` is
`[approved, allocated]`, so once a trip reaches `pretrip_ok` its checklist cannot be regenerated. If
a document lapses after the gate is passed, nothing re-checks it. This is safe **only** because
`pretrip_ok → dispatched` is unwired, so the trip cannot move. **The ticket that owns D-18 must
re-validate readiness at dispatch time.**

### Resolution — built 2026-09-10, still without a ticket

The owner authorised a bounded "Record dispatch" scope in writing, in place of a Step 12 ticket. The
authorization is recorded verbatim in `DispatchScope::AUTHORIZATION`; **the register still owns no
dispatch ticket, so this defect stays open** against Step 12 rather than being closed by the code.

What was built, against the list above:

| Item | Status |
| --- | --- |
| FRS `TRP-P0-006`'s five fields | Built — `DispatchScope::FIELD_MAP`; migration `2026_12_16_000011`. TAT is derived, not stored (`TAT_DEFERRED`). |
| Freeze on release; version any later change | Built — `DispatchService::confirm()` / `amend()`; `dispatch_version` 0 → 1 at release, +1 per amendment. |
| "Version history" (TRP-P0-006's audit column) | Built — `DispatchService::history()`, reconstructed from `transport_audit_logs`. No versions table; see `DispatchScope::VERSION_HISTORY`. |
| RTM `STOS-REQ-OPS-008` "Record dispatch" | Built — `pretrip_ok → dispatched` with actor and timestamp. |
| BRW-050's eight side effects | Dispositioned individually in `DispatchScope::BRW_050_DISPOSITION` — 2 built, 2 boundary, 2 no_ticket, 2 blocked. |
| Vehicle → In Operation, Driver → On Trip | **Not built — boundary.** Owner's ruling of 2026-09-10: Trip side must not write `transport_vehicles` / `transport_drivers`. Routed through `FleetResourceGateway`; the shipped implementation records intent and returns false. One `bind()` in `TransportNumberingServiceProvider::register()` is the whole handover to Developer A. |
| `STT-006` `dispatched → in_transit` | **Not built.** Transit is SNG-TRN-013, blocked on the owner's Q1/Q3 ruling. |
| Change approval after release | **Not built.** No approval entity exists in Step 11; every approval in the package is P1. An amendment carries a reason and a version but no approver, and the API and the screen both say so rather than letting silence read as approval. |

**The re-validation requirement above is now met.** Owner's ruling of 2026-09-10, option (b):
`DispatchService::assertDispatchable()` calls `PretripService::revalidate()`, which re-evaluates the
generated checks against live sources **and writes nothing**. A trip whose facts have changed since
the checklist was confirmed is refused, and the refusal names the check, what it says now and what it
said before — separately from ordinary blockers, because "it never passed" and "it passed and has
since lapsed" are different problems for a dispatcher (BRW-048, UX §35).

Verified end to end in a browser on 2026-09-10: a driver licence expired after `pretrip_ok` leaves
the pre-trip panel reading *Ready · 5 of 5 confirmed* — correctly, that is the record of what was
confirmed — while the dispatch panel reads *Blocked* and explains the discrepancy under **"Passed at
pre-trip, not passing now"**.

**Still open for whoever writes the ticket:** notifications (SNG-TRN-021, P1), the shareable dispatch
pack (D-22 — Transport has no document generation), dispatch override (BRW-049, P1), and TAT's
definition, which no document gives.

---

## D-19 — Ticket 010's DoD requires offline tests for a P1 backlog capability

The DoD reads *"Offline + audit + permission tests."* But offline is **SNG-TRN-026 — P1, Backlog,
Sprint S13**, and STOS-DEV §118 states offline *"should only be developed where explicitly
required"*, naming driver workflows as candidates for **future** work. STOS-TEST §152 is conditional
(*"Where offline support exists"*).

The DoD is therefore **unachievable as written**. Offline is excluded and recorded in
`PretripScope::EXCLUDED`. Audit and permission tests are delivered in full.

---

## D-20 — Most named checklist items have no data model

Five documents name checklist items; the union is 23. **Five have data behind them.** The other 18
would read a column no migration creates and no Step 11 entity defines: maintenance state, tyre
condition, lights, brakes, engine, fuel, genset hours, temperature set point, equipment readiness,
safety equipment, trailer, driver training, rate card, customer requirement, service requirement,
required approvals, document handover.

Same class of finding as FLEET §17 in ticket 009. **Ruled 2026-09-09 (Q2, Q5): declare the checks,
leave them unreachable** — the same treatment trailer and GPS received in 009. All 23 are declared in
`PretripCheckKey` with a `SOURCES` entry; `TransportPolicyService` refuses to enable any of the 18,
because generating a row nothing can evaluate would strand the trip at `IN_PROGRESS` forever with
nothing on screen to explain it.

Stated plainly: **out of the box this checklist verifies commercial approval, crew assignment and
document validity. It does not verify the physical condition of the vehicle.**

---

## D-21 — No permission row for pre-trip, and a three-way role conflict

Step 11's Permissions sheet has 13 rows (PERM-001…013) and **none** covers pre-trip or dispatch.
D-8 recurring, one ticket later.

Worse, three documents name three different roles for the same act:

| Source | Who performs the pre-trip check |
|---|---|
| FRS `TRP-P0-005` | **Supervisor/Driver**, with "Supervisor sign-off for critical failures" |
| UAT-004 | **Supervisor** |
| OPS §35 | **"Driver must complete required inspection"** |
| Step 11 SM-TRP | `allocated` and `dispatched` are owned by the **Dispatcher** |

**"Supervisor" is not one of Step 11's nine role names.** And a further constraint that no document
anticipates: `transport_drivers` links only to an optional `hr_employee_id` and has **no user
account**, so no driver can authenticate — OPS §33's driver app does not exist. Checklists are
completed by staff on the driver's behalf; driver self-service belongs with SNG-TRN-026.

**Resolved for implementation 2026-09-09, the same way D-8 was — derived, recorded, FLAGGED.**

Two keys were added to `TransportPermission`, neither quoted from the registry:

| Key | Owner | Operations | Dispatcher | Accounts | Approver | Driver | Customer | Supplier | Admin |
|---|---|---|---|---|---|---|---|---|---|
| `transport.pretrip.view` | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | — | ✅ |
| `transport.pretrip.perform` | ✅ | ✅ | ✅ | — | — | ⚠️ | — | — | ✅ |

The derivation:

- **"Supervisor" → Operations.** The label appears in no role list anywhere in the 42 documents.
  Among Step 11's nine roles, a transport supervisor is Operations — the role that owns operational
  execution.
- **Dispatcher is included on the strength of SM-TRP**, which makes the Dispatcher the owner of both
  `allocated` and `dispatched` — the two states this ticket moves between.
- **The write row is PERM-004's row exactly.** Whoever may crew a trip is who may certify that crew
  fit to leave. Accounts and Approver keep their `N` from PERM-004, so they can watch a block but not
  clear one.
- **Customer and Supplier get nothing, not even view.** A dispatch block names a specific driver's
  expired licence; PERM-001 gives those roles only `Own`/`Assigned` scope on the trip itself.
- **One key covers generate, complete and pass.** FRS implies a three-way split (Driver completes,
  Supervisor signs off, Dispatcher dispatches) but with Driver unimplementable the actor set is
  identical for all three, and inventing a distinction the role model cannot express would be the
  over-reach FORBID-001 forbids.

⚠️ **The Driver actor is named by two documents and cannot be implemented.** `transport_drivers`
links only to an optional `hr_employee_id` and has **no user account**, so a driver cannot
authenticate; OPS §33's driver app does not exist. Rather than grant a row that can never be
exercised, the omission is recorded as data in
`TransportPermission::UNIMPLEMENTABLE_ACTORS` so a later ticket can find it. Checklists are
completed by staff on the driver's behalf until SNG-TRN-026 creates a driver identity.

**Still needs Security + Architecture confirmation.** All of the above is inference from a registry
that says nothing.

---

## D-22 — Photo evidence is required, and Transport has no way to accept a file

FRS `TRP-P0-005` audit column reads *"Checklist audit + photo evidence"*; UAT-004's evidence is
*"Checklist + photos"*; UX §94 asks for a camera-first workflow.

**Transport has no file-upload capability at all.** `transport_documents.file_path` exists and has
never been populated by anything — no controller, request or service in the module accepts a file.

**Ruled out of scope for SNG-TRN-010 by the owner 2026-09-09 (Q4)**, recorded in
`PretripScope::EXCLUDED` with the reason. The checklist row carries `remarks` (OPS §41) but no
attachment. Whoever builds upload capability for Transport should expect to revisit this ticket.

---

## D-23 — BR-P0-002 is a second dispatch block, and nothing composes the two

Found during SNG-TRN-010's step 9 sweep. The S3 `BRWM_Core_Rules` sheet has twenty BR-P0 rules and
**none of them is a pre-trip rule** — which is itself worth recording, because it means no Audit
Evidence column mandates anything for the pre-trip gate (unlike BR-P0-003/004, whose evidence columns
drove ticket 009's fix). Evidence here is written to ticket 009's standard rather than to a stated
requirement.

But one of the twenty *does* block dispatch:

> **BR-P0-002** | Commercial | "Trip below minimum contribution margin **cannot dispatch** without
> override" | Configurable | Role-based | **Critical** | Trigger: *Viability calc* | Action: *Block
> dispatch* | Approval: Owner/Finance | Audit Evidence: *Override reason*

Its trigger is the viability calculation, so it belongs to **SNG-TRN-008 (Trip Viability)**, which is
not built. The pre-trip gate does **not** evaluate margin and must not — that would be implementing
another ticket's rule.

**The consequence, recorded so it is not discovered late:** once 008 lands there will be *two*
independent dispatch blocks — readiness (BRW-046) and margin (BR-P0-002) — and **no document says how
they compose**. Whoever owns D-18 (dispatch confirmation) must gate on both, and BR-P0-002's override
path is P1 alongside every other override in the package.

**Needs:** Product + Architecture to state the composition rule before dispatch is built.

---

# ══════════════════════════════════════════════════════════════════════
# D-24 … D-34 — found during the SNG-TRN-005 and SNG-TRN-013 pre-build
# analyses. NEITHER TICKET WAS BUILT, so these are findings, not
# workarounds: no code depends on any of them.
# ══════════════════════════════════════════════════════════════════════

## D-24 — SNG-TRN-005 (Rate Card) is a P0 ticket with no requirement behind it

Searched the full text of all 42 documents. `rate card`, `pricing`, `tariff`, `freight`,
`quotation` and `lane` appear as **requirements** in none of them:

| Source | Rate-card requirement? |
|---|---|
| STOS-RTM (the traceability matrix) | **None.** §15 Customer & Sales and §16 Transport Order have no rate row. |
| FRS Step 3 — 22 P0 + 12 P1 rows | **None.** The FRS begins at "Order to Trip". |
| Step 5 execution pack — 25 tasks | **None.** |
| Step 6 build blueprint — 18 tickets | **None.** |

It is the **only ticket in the register with neither an RTM row nor an FRS row**. Its own four
refs also misresolve: `FRS-P0-005` does not exist, `BR-004` resolves to *"Next Best Action"* in the
business risk model, `DB-005` is `drivers`, and `FRS-RAT` appears in one cell and nowhere else.

RTM **§48** forbids introducing a feature without a requirement ID; **§50** says an untraceable item
must be marked a TRACEABILITY EXCEPTION and **"Do not mark it complete."**

Authority to build does exist elsewhere — Step 9 makes `Rate / Quote` canonical entity #3 with change
rule "Versioned", Step 11 carries DB-016 `transport_rates`, Step 12 marks it P0/Ready. Three of the
four top authorities support it; only the traceability matrix is silent.

**Held by the owner, 2026-09-10**, pending a requirement row or a written exception. Also noted: the
`Dependencies` sheet omits rate → viability even though ticket 008's *Depends On* names 005.

---

## D-25 — The rate card's primary key dimension has no entity

Three documents key rate cards on **lane**:

- Step 2 `BO-005` — *"Rate Card / Contract | **Customer + lane** | …"*
- Step 9 entity #3 — *"Rate / Quote | … | Order, Trip, customer, **lane**"*
- Step 11 `DB-016` — *"**Lane**/customer/vendor rate cards"*

**No Lane entity exists** in Step 11's twenty, and no ticket builds one. Worse, the RTM rates the
master that would make a lane real as **P1**:

> `STOS-REQ-MDM-004` — *"Maintain location master · Origin/destination reusable"* · **P1**

So a rate card built now would be keyed on a dimension the specification says is not due yet, and the
key would change when the location master lands — on a table whose historical rows FIN §12 says must
never be rewritten. Ticket 006 already met its own *"Order has customer, lane, rate"* acceptance with
a free-text `route` column for the same reason.

---

## D-26 — One entity, four names, and no specification at all

| Source | Name |
|---|---|
| Step 11 `DB-016` | `transport_rates` |
| STOS-DB §99 | `rate_cards` |
| Step 5 | `order_rates` — a *different*, order-level entity |
| Step 4 `DM-04` | "Rate Card / Rate Contract", `rate_id` |

`DB-016` has **no fields, no indexes, no API, no event, no permission and no enum** anywhere in
Step 11. The ticket is **BE-only** yet its user story is *"As an owner, I can **configure**…"* — the
package specifies no way for an owner to do that.

FIN §10 lists **eleven** rate dimensions and FIN §11 **eleven** charge components; six of the
dimensions have no field anywhere in the system.

---

## D-27 — The rate lifecycle and its approval have nowhere to live

Step 2 `BO-005` gives rate cards a lifecycle — **Draft / Approved / Expired** — but neither Step 9's
nor Step 11's state machines carry a Rate machine, and there are no `STT-*` rows for it.

FIN §13 requires rate approval to capture proposed rate, previous rate, margin impact, reason,
approver and effective date; `BRW-014` requires a rate to come from an *"Approved customer contract,
Approved rate card, or Authorized manual rate"*. Step 11 has **no approval entity**, and
**no contract entity** — so two of BRW-014's three permitted sources cannot exist.

---

## D-28 — The buy-side is named once and specified nowhere

*"vendor rate cards"* occurs in exactly one place in 42 documents: `DB-016`'s purpose line. Nothing
states what a supplier rate contains, how it differs from a customer rate, or how the two resolve
against one trip. Supplier rates would change the entity's shape materially.

---

## D-29 — Six exception lifecycles, and Step 11 contradicts itself

| Source | States |
|---|---|
| **Step 9** (highest authority) | OPEN → ACKNOWLEDGED → IN_PROGRESS → MITIGATION_PLANNED → RESOLVED → VERIFIED / CLOSED |
| **Step 11 `SM-EXC`** (LOCKED) | open → acknowledged → resolved — **`resolved` marked terminal** |
| **Step 11 `ENUM-004`** | open, acknowledged, **in_progress**, resolved, **closed** |
| Step 5 | open → acknowledged → **mitigated** → resolved → closed |
| FRS `TRP-P0-012` | open → acknowledged → resolved / **waived** |
| LSM §74 | DETECTED → CLASSIFIED → PRIORITIZED → ASSIGNED → NOTIFIED → ACTION_IN_PROGRESS → RESOLVED → VERIFIED → CLOSED, + OVERDUE → ESCALATED |

**Step 11 contradicts itself twice**: `SM-EXC` has three states where `ENUM-004` has five, and
`SM-EXC` calls `resolved` terminal while `ENUM-004` places `closed` after it. Nine distinct state
tokens across the package — the worst state divergence found, worse than the Trip machine.

Only `STT-015` (open→acknowledged) and `STT-016` (acknowledged→resolved) are defined.

**Blocks SNG-TRN-013.** Awaiting the owner's Q1 ruling.

---

## D-30 — The exception state `waived` is required and exists nowhere

FRS `TRP-P0-012`'s output is *"Open→acknowledged→resolved/**waived**"* and its control is
*"Waiver requires reason/role"*. `BR-P0-011`'s Override column is *"Owner waiver"*.

No enum or state machine in the package contains `waived`.

Notable: this is the **first override written into a P0 Hard rule's own definition**, unlike
PLN-007, CMP-007 and BRW-049, which were all deferred as P1. That makes it a closer call than the
other overrides. **Blocks SNG-TRN-013.** Awaiting the owner's Q2 ruling.

---

## D-31 — An exception permission key with no matrix row

`API-007` names the permission `transport.exception.create`, so unlike D-8 and D-21 **the key name
is specified**. But Step 11's Permissions sheet has **no Exception row**, so who holds it is not.

Worse, OPS §90 requires owners to be assigned **automatically** from *category; branch; role;
escalation matrix*, and OPS §91 escalates *L1 Employee → L2 Supervisor → L3 HOD → L4 Management →
L5 CEO*. **Branch has no model**, the escalation matrix has no entity, and "Supervisor"/"HOD" are not
among Step 11's nine role names — the same defect as D-21.

---

## D-32 — Financial and customer impact are required fields with no calculator

OPS §87 requires every exception to contain *financial impact* and *customer impact*. LSM §83 goes
further: *"every exception/state should calculate financial impact"*, with examples
(*Billing Blocked = ₹X, Idle Vehicle Risk = ₹X/day*).

**No formula exists anywhere in the package**, and no cost model exists until SNG-TRN-012/018. The
fields can be stored; nothing can populate them.

---

## D-33 — Exception→task requires another module's Task Engine

OPS §92: *"OPS shall use Sangoe's **shared Task Engine**"*, and §93 requires automatic task creation
(allocate driver, collect document, verify POD, resolve delay…).

The Task module is outside Transport, and the standing rule for this work is that no other module may
be modified. Exception-driven task creation therefore cannot be built inside SNG-TRN-013 without a
cross-module decision.

---

## D-34 — SLA computation requires a business calendar that does not exist

`BRWM §58` requires every SLA-controlled process to define **seven** elements: start event, target
duration, warning duration, overdue duration, escalation level, **business calendar**, exception
handling.

`BRWM §59` then requires the calendar to support *working hours; weekends; holidays; branch location;
customer-specific calendar*. **None of these has an entity.** Branch is the same missing model as
D-31 and the ticket-009 "wrong branch" gap.

Elapsed wall-clock SLA is buildable. Business-calendar SLA is not.

---

## Standing ruling — no Idempotency-Key header for R1 (Q6)

`API-004` marks Idempotency **Required**. The Q6 ruling waived idempotency headers for Release 1
except on the GPS and e-way-bill ingest endpoints, and that waiver is retained here because the
integrity a key would buy is **already guaranteed by other means**:

- `TripAssignmentService` takes a row lock inside a transaction before reading resource state;
- three unique indexes over STORED generated columns make a second ACTIVE assignment impossible for
  the same vehicle, driver or trip — enforced even for a raw INSERT that never took the lock;
- an exactly-matching repeat request returns the existing assignment and writes nothing — no second
  audit row, no second event.

What a key would add beyond this is response replay, which no consumer needs. A *different* resource
on an allocated trip is still refused on its merits. Tested in `TransportAllocationApiTest`.

---

## D-35 — Every registry reference on ticket SNG-TRN-013 is wrong

Step 12 `Ticket_Register:14` gives five cross-references. All five were checked against the document
they name, and none resolves:

| Ticket says | What that row actually is | The real row |
|---|---|---|
| `FRS-P0-013` | FRS `TRP-P0-013` = **Delivery / POD capture** (ticket 014's requirement) | `TRP-P0-011` Live trip control + `TRP-P0-012` Exception management |
| `BR-012` | no such rule id in BRWM | `BR-P0-010` (Transit) + `BR-P0-011` (Exception) |
| `DB-012` | not `trip_exceptions` | `DB-010` |
| `API-008` | not the exception endpoint | `API-007` |
| `EV-009` | the event registry uses `EVT-nnn` | `EVT-008` |

Same fabricated sequential counter already recorded against tickets 007 and 009 (D-12, D-15): the
register's cross-references were generated **by position, not by lookup**. The pattern now holds
across three tickets, so **no ticket's registry references should be trusted without checking the
target document.**

Corrected in `ExceptionScope::REGISTRY_REF_CORRECTIONS`, asserted by `ExceptionScopeTest`.

---

## D-36 — Step 9 has an `arrived` state that Step 11 skips

Step 9's Trip machine runs `… → DISPATCHED → IN_TRANSIT → **ARRIVED** → DELIVERED → …`.

Step 11 `STT-007` goes straight from `in_transit` to `delivered`, and Step 11's `ENUM-001` omits
`arrived` altogether (it also omits `pretrip_ok`, `pod_pending` and `settlement_pending` — 12 values
against Step 9's 16).

`TripStatus::ARRIVED` is declared and unreachable. SNG-TRN-013 stops at `in_transit`, so this does
not block it — but **SNG-TRN-014 (POD) must resolve it**, because it owns `in_transit → delivered`
and has to decide whether a trip passes through `arrived` on the way.

---

## D-37 — Exception category is a required field with no enum

OPS §87 lists **category** among the twelve fields every exception "must contain", and OPS §88 gives
eight of them with a one-line gloss each (Resource, Compliance, Operational, Temperature, Financial,
Customer, Fleet, Documentation).

Step 11's Enums sheet has **no category enum**: `ENUM-003` covers severity, `ENUM-004` covers status,
and nothing covers category. So unlike severity, there is no LOCKED row to defer to.

OPS §88 is used as the vocabulary — it is the only list, and it sits in the document that owns the
exception engine. Recorded because a later ticket adding a ninth category, or a control room
grouping by a different set, would fragment it. `ExceptionCategory::AUTOMATIC_SOURCE` maps five of
the eight to the requirement that would raise them automatically once its ticket exists, so that
ticket reuses the category rather than inventing one.

---

## D-38 — Twenty-five P0 requirements for Container/LR/DO, and no ticket owns any of them

The RTM carries a whole **CTD (Container Traceability)** domain:

| Requirement | Priority |
|---|---|
| `STOS-REQ-CTD-001` … `CTD-021` — search by container number, link container to customer/order/LR/DO/vehicle/driver/compliance/temperature/incidents/CAPA/documents/POD/feedback/billing/invoice, chronological timeline | 17 × P0, 4 × P1 |
| `STOS-REQ-MDM-008` — "Maintain container master/reference" | P0 |
| `STOS-REQ-ORD-004` — "Link container to order" | P0 |
| `STOS-REQ-ORD-005` — "Capture LR details" | P0 |
| `STOS-REQ-ORD-006` — "Capture DO details" | P0 |

There is also a dedicated master-baseline specification, **STOS-CTD v1.0**, 1 927 lines.

Step 12's 30-ticket register contains **no Consignment, Container, LR or DO ticket**. It runs
Customer → Vehicle → Driver → Rate Card → Order → Trip → Viability → Allocation → Pre-trip →
Advance → Cost → Transit → POD → Billing → …

Same shape as D-18 (dispatch): the REQUIREMENT is specified and P0, the TICKET does not exist. Larger,
because this is twenty-five requirements and a whole specification document rather than one row.

---

## D-39 — Step 9's canonical domain model has no Consignment and no Container

Step 9's `Domain_Model` sheet lists **twenty** canonical objects: Customer, Order, Rate/Quote, Trip,
Vehicle, Driver, **LR/Bilty**, E-Way Bill, Advance, Cost Entry, POD, Invoice, Collection, Settlement,
Exception, Risk, Policy, Notification, Document, Accounting Event.

Neither *Consignment* nor *Container* is among them. LR/Bilty (#7) is described as the
*"Consignment document"* — which reads as Step 9 folding the consignment INTO the LR.

The sheet's own header states: *"Canonical entities and ownership rules; **duplicate business objects
are prohibited without architecture approval**."*

STOS-CTD §8 contradicts that folding directly and at length:

> "These must not be treated as identical concepts. **Container** — the physical transport unit.
> **Consignment** — the commercial/operational shipment being transported. A consignment may contain
> one container; contain multiple containers; have other cargo references. The architecture must
> therefore support Consignment ↔ Container as a controlled relationship."

Step 9 outranks STOS-CTD in the authority order (Step 9 > 10 > 11 > 12 > 13 > everything else), so
**creating Consignment and Container as entities needs the architecture approval Step 9 itself names.**
It cannot be taken as already granted.

### RULED 2026-09-12 — Option A1, and this IS the approval Step 9 asks for

Consignment and Container become **canonical entities**. Step 9's `Domain_Model` header requires
*"architecture approval"* for a new business object by name; that approval is granted explicitly and
in writing, so **Step 9 is amended, not contradicted**.

Grounds recorded with the ruling:

- **STOS-CTD v1.0** is a master-baseline specification written entirely about these two objects.
- The RTM carries **17 P0** `CTD-*` requirements plus `MDM-008` and `ORD-004/005/006`.
- **STOS-MS-001 §14** opens the 30 September demonstration with *"Search by Container Number / Open
  Container 360"* — unbuildable without them.

The omission from Step 9's twenty-object list is a gap in a summary, not a deliberate exclusion.

---

## D-40 — `container_number` cannot be both unique and historically reusable

STOS-CTD §7 requires the container number to:

> "be unique **where applicable**; follow configurable format validation; be searchable; be
> **normalized for search**; **retain original entered value** where required; maintain historical
> associations. Container reuse across different trips is allowed **historically** but not
> simultaneously where business rules prohibit it."

A `UNIQUE(tenant_id, container_number)` constraint makes the second sentence impossible: the same
physical container (`ABCD1234567`) carries a different consignment every few weeks, and that history
is the entire point of the Digital Passport.

Three separable requirements are hiding in one line — a **container master** (one row per physical
unit, where the number IS unique), a **consignment↔container association** (many over time, unique
only while active), and a **normalized search key** alongside the original entered value. The
"unique where applicable" wording does not say which of the three it governs.

### RULED 2026-09-12 — Option B1, master + association (three tables)

`UNIQUE(tenant_id, container_number)` is **rejected**: it makes the historical reuse CTD §7 requires
impossible, and a container is a physical unit that outlives any one consignment.

- Uniqueness lives on the **master**: `UNIQUE(tenant_id, container_number_normalized)`.
- The **association** table carries `attached_at` / `detached_at` and enforces one *active*
  attachment per container — history allowed, simultaneity refused.
- `container_number` (the original entered value) is kept beside the normalized key, per CTD §7.

The association table is built in Block 1, not deferred.

---

## D-41 — LR and DO have two candidate homes, and DO has no enum value

**LR is already modelled as a document, twice over.** Step 9 #7 makes LR/Bilty a canonical
*document*; Step 11 `ENUM-006` lists `lr` among `document_type`; and this codebase already has
`transport_documents` with `document_type='lr'`, a `document_number`, `issued_on`, validity dates,
versioning and `IDX-010 UNIQUE(tenant, entity_type, entity_id, document_type, version)`.

Creating a separate `transport_lr_records` table would give LR **two homes** — the exact thing
Step 9's domain model and the team's "no duplicate master data" rule forbid.

**DO is worse: it is in no enum at all.** `ENUM-006` reads
`lr|ewaybill|invoice|pod|driver_doc|vehicle_doc|insurance|permit|fitness|other`. There is no
`do`/`delivery_order`, and that enum's owner is recorded as *Product+Compliance* — not this section.

Meanwhile RTM `ORD-005`/`ORD-006` place *"Capture LR details"* and *"Capture DO details"* under the
**Transport Order** module, suggesting they are attributes captured against the order rather than
entities of their own.

---

## D-42 — `CTD-009` links the container to gate/port records that do not exist

`STOS-REQ-CTD-009` ("Link container to gate/port records", P1) requires gate and port events to be
visible on the Digital Passport. STOS-CTD §28 (Gate Pass), §29 (Port Entry) and §30 (Port Detention)
describe them narratively, and `STOS-REQ-OPS-006/007` ("Manage gate pass", "Manage port entry slip")
are both P1.

**No entity exists** — not in Step 9's domain model, not in Step 11's DB registry, not in any
migration. There is nothing to link to.

**Deferred**, owner Product + Architecture. Block 1 provides the consignment/container anchor these
records would attach to when someone builds them, so nothing here forecloses it.

---

## D-43 — `CTD-014` depends on an unowned P0

`STOS-REQ-CTD-014` ("Link container to urgent-trip records", P1) requires urgent events to be visible.
Urgency comes from `STOS-REQ-OPS-014` — "Handle urgent trips", **P0**, acceptance *"Urgent flag and
extra cost captured"*.

No ticket in Step 12's register owns `OPS-014`, and nothing has built an urgent flag. A P1 that
depends on an unbuilt P0 cannot be delivered.

**Deferred**, owner Step 12 maintainer. Noted alongside D-38: this is the second requirement found
stranded behind a P0 that no ticket claims.

### RULED 2026-09-12 — LR and DO stay documents

`transport_lr_records` and `transport_delivery_orders` are **not built**. LR and DO are documents on
the consignment, carried by the existing `transport_documents` table.

Three changes are needed in **Person 3's** files and have been requested in writing
(`docs/transport/REQUEST-person3-document-entity.md`):

1. `TransportDocumentEntity::CONSIGNMENT` added to `ALL` and `ACTIVE`
2. a match arm for `TransportConsignment` in `TransportDocumentService::resolveEntity()`
3. `delivery_order` added to `ENUM-006` / `TransportDocumentType`

Block 2's document panel codes against a stub until those land.
