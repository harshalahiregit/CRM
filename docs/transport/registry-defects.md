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
| D-18 | Dispatch confirmation has no ticket | High | Step 12 maintainer | Open — deferred by owner |
| D-19 | Ticket 010 DoD demands offline | Medium | Product + QA | Open — offline excluded, DoD unachievable |
| D-20 | Checklist items with no data model | High | Product | Open — declared, unreachable |
| D-21 | No pre-trip permission; role conflict | **Critical** | Security + Architecture | Open — **rows derived and FLAGGED in code** |
| D-22 | Photo evidence, no upload capability | High | Product | Open — deferred by owner |
| D-23 | A second dispatch-block rule has no owner | Medium | Product + Architecture | Open — recorded only |

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
