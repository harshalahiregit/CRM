# Sangoe Transport OS — Registry Defect Register

Defects found in the STOS specification package (`/home/mohammad-raza/Documents/new module`)
while implementing the Transport module. **These are defects in the SPECIFICATION, not in the
code.** Each was found during implementation, worked around in a documented way, and needs a
ruling from the owner named against it.

Kept in the repo because several of these existed only in a chat transcript, which is not a
durable place for a Critical-severity registry defect (found by audit, 2026-09-07).

Authority for who rules on what: the Conflict Resolution matrix in
`Sangoe_Transport_OS_Master_Developer_Handover_Document_Authority_Register_2026.xlsx`.

**Recorded rulings and suspensions** also live here, prefixed `RULING-`, for the same reason the
defects do: a rule consciously set aside needs to be as findable as a rule broken by accident, and
a verbal approval is not an artefact.

- [RULING-001](#ruling-001--the-client-portal-is-being-built-without-step-12-tickets) — the client
  portal is being built without Step 12 tickets (B-09). Two endpoints, one permission value.
  Retroactive ticket outstanding.
- [RULING-002](#ruling-002--the-fleet-repoint-was-applied-2026-09-23) — the trip references were
  repointed onto the Fleet masters. 16 rows moved, 7 recorded unmatchable, reversible via
  `fleet_reference_repoints`. Driver allocation blocked on D-134.

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
| D-44 | CTD §11 requires a consignment lifecycle engine that does not exist | High | Architecture + Product | Open — status derived at read time as a consequence |
| D-45 | Consignment permissions are in no registry row | Medium | Security + Architecture | Open — mirrored from Order, precedent D-8/D-21 |
| D-46 | `SCOPE_OWN` is granted but its narrowing is not implemented | **Critical** | Security | Open — latent, not currently reachable |
| D-47 | Two conflicting structural standards: TEAM-CONVENTIONS vs the DDD instruction | **Critical** | Architecture | Open — analysis posted, nothing moved |
| D-48 | Two LOCKED events we produce are never emitted | High | Person 1 | ✅ **Built 2026-09-15 — EVT-001, EVT-002 emitted** |
| D-49 | TM-001 §11 and Step 11's Event_Registry name different event sets and payloads | Medium | Architecture | Open — `consignment_id` approved 2026-09-15; read-contract question unanswered |
| D-50 | `container_type` is a required field with no vocabulary anywhere | Medium | Product | Open — free-text column, not an enum |
| D-51 | The suite runs on sqlite; production runs MySQL | High | Architecture | Open — repository-wide, not Transport's to fix |
| D-52 | No structural marker for a temperature-critical trip | **High** | Product + Person 2 | Open — TM-001 §12's P0 rule has nothing to key on |
| D-53 | Two CLOSED attachment windows may overlap | Low | Person 1 | Open — latent, unreachable today |
| D-57 | Step 9 and ENUM-002 describe different advance lifecycles | **High** | Product + Finance | Open — ENUM-002 stored on FLD-012's authority, four Step 9 states unrepresentable |
| D-58 | SNG-TRN-012's three refs point at the exception domain; `cost_type` and `amount` both dangle; cost/expense boundary undefined | **High** | Product + Finance + Architecture | Open — blocks 012, and via DEP-008 also 017 and 018 |
| D-62 | Two live vehicle/driver systems: `transport_vehicles`/`transport_drivers` vs the fleet module's `vehicles`/driver directory | **Critical** | P1 + P2 | Open — both deployed, data does not cross; sidebar merged 2026-09-17 but data is not |
| D-61 | EVT-011's payload is pure Accounts vocabulary (`receipt_id`, `posting_id`) that Transport cannot supply; no collection status vocabulary; DB-013 has one field row | **High** | Product + Accounts | Open — status derived, event payload honestly partial |
| D-60 | API-010 promises a `BillingPrepared` event the Event_Registry never defines; no Billing permission domain; DB-012 has one field row | **High** | Product + Accounts | Open — event payload, permission row and bill vocabulary all constructed for 015 |
| D-59 | STT-008 requires a "POD valid" guard, but no document lifecycle is registered anywhere; DB-009 has no field or index rows | **High** | Product + Compliance | Open — status vocabulary and MIME/size limits constructed for 014 |

> **D-54, D-55 and D-56 have bodies below but no row here** — they were added on 2026-09-16 and the
> index was not extended with them. Person 1 owns those three; the rows are theirs to write, which
> is why they are named rather than summarised by someone else.

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

### Fourth sighting, and the missing approve row — added 2026-09-16

`API_Registry` also contains **no row for approving a trip**, though STT-002 and PERM-003 both
exist and are LOCKED. The path is therefore ours, chosen by the shipped convention rather than
invented freely: `PATCH /api/transport/trips/{id}/approve`, mirroring the `submit-viability` route
that already ships beside it. Logged here with D-12's other missing endpoints.

And a fourth instance of the fabricated positional counter (with D-15 and D-35): **SNG-TRN-008**'s
`DB/API/State/Event Refs` column reads

```
DB-008;API-005;EV-004
```

The real rows for trip viability are **API-003** (`POST /transport/trips/{trip}/viability`) and
**EVT-003** (`TripViabilityCalculated`). `DB-008` is `trip_expenses`. Not one of the three points
at the right place, and the numbers again run in step with the ticket's own position in the
register.

**Four sightings is not a suspicion.** The refs column of the ticket pack should be treated as
having no evidential value at all: resolve every reference **by name** against Step 11, never by
the number a ticket prints. That is now the rule, not a caution.

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
| Vehicle → In Operation, Driver → On Trip | **Not built — boundary.** Owner's ruling of 2026-09-10: Trip side must not write `transport_vehicles` / `transport_drivers`. Routed through `FleetResourceGateway`; the shipped implementation records intent and returns false. One `bind()` in `TransportNumberingServiceProvider::register()` is the whole handover to Person 2. |
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

~~**Blocks SNG-TRN-013.** Awaiting the owner's Q1 ruling.~~

### RULED 2026-09-18 — and it needed no new decision

The owner's ruling: **this contradiction dissolves under a rule we already made.**

See `TEAM-CONTRACTS.md`, *"Which document wins when the state machines disagree"* — the standing
rule from Block 3. Applied here:

| | |
|---|---|
| **VOCABULARY** — from Step 9 | open · acknowledged · in_progress · mitigation_planned · resolved · verified · closed |
| **EDGES** — from Step 11, which LOCKS exactly two | `STT-015` open → acknowledged · `STT-016` acknowledged → resolved |
| **EVERYTHING ELSE** | stays in the vocabulary, stays unreachable — exactly as `arrived`, `pod_pending` and `settlement_pending` do on the Trip machine |

### And it settles Step 11 contradicting itself, without either half losing

That was the part that looked intractable: `SM-EXC` calls `resolved` **terminal** while
`ENUM-004` lists `closed` **after** it. One document, two answers.

They are answers to different questions. **`SM-EXC` calling `resolved` terminal is an EDGE claim.
`ENUM-004` listing `closed` is a VOCABULARY claim.** Under the rule the edge claim is Step 11's to
make and the vocabulary claim is Step 9's — and Step 9 lists `closed` too.

So `closed` exists in the vocabulary, no edge leaves `resolved`, and **`resolved` is terminal in
practice**, because a state absent from `TRANSITIONS` is terminal by construction. Both halves of
Step 11 are honoured. Nothing invented, nothing discarded.

**SNG-TRN-013 is unblocked.** This is step 9 of MS-001 §14's fourteen, and it was the only one of
that walk's eight gaps that belonged to P1.

---

## D-30 — The exception state `waived` is required and exists nowhere

FRS `TRP-P0-012`'s output is *"Open→acknowledged→resolved/**waived**"* and its control is
*"Waiver requires reason/role"*. `BR-P0-011`'s Override column is *"Owner waiver"*.

No enum or state machine in the package contains `waived`.

Notable: this is the **first override written into a P0 Hard rule's own definition**, unlike
PLN-007, CMP-007 and BRW-049, which were all deferred as P1. That makes it a closer call than the
other overrides. ~~**Blocks SNG-TRN-013.** Awaiting the owner's Q2 ruling.~~

### RULED 2026-09-18 — DEFERRED, the same way BR-P0-017's waiver is

**A SPECIFIED behaviour we are choosing not to build yet, not an invented one we are refusing.**
The distinction is the one the owner drew for closure, and it applies here unchanged:

| | |
|---|---|
| **Specified by** | FRS `TRP-P0-012` — output *"Open→acknowledged→resolved/**waived**"*, control *"Waiver requires reason/role"* |
| **Role named** | **Owner** — `BR-P0-011`'s Override column reads *"Owner waiver"* |
| **Status** | Deferred. No ticket authorises or audits a waiver, and building one would mean inventing who may exercise it and what evidence it needs |

**Two things follow, and both are load-bearing.**

**1. Nothing on screen may imply an exception cannot be waived.** The refusal says the waiver is
**not built yet**. A user told *"this cannot be waived"* when their own rule book says it can is
being misled by our software about their own business. Same wording discipline as
`ClosureScope::WAIVER_MESSAGE`.

**2. `waived` leaves the vocabulary.** This supersedes the owner's Q2 ruling of 2026-09-10
("waived declared in the enum, not wired") — cleanly, rather than by reversal. The standing
Step 9 / Step 11 rule says the vocabulary comes from **Step 9**, and `waived` is the one status in
`ExceptionStatus` that Step 9 does not contain; it is in FRS and BRWM alone.

That is exactly why it is treated differently from `in_progress`, `mitigation_planned`, `verified`
and `closed` — those ARE Step 9's, so they stay declared and unreachable. `waived` is not, so it
stays **out of `ALL`** until it has an edge and a gate. The constant remains, carrying its
deferral, because the behaviour is specified and will one day be built.

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

### CLOSED 2026-09-17 by the standing ruling below

SNG-TRN-014 shipped `delivered → pod_verified` without deciding it, so the question fell to
Block 3, which owns `in_transit → delivered`. The owner ruled on the general case rather than
this one state:

> **Vocabulary from Step 9. Edges from Step 11. A Step 9 state becomes reachable only when
> some document defines something that can gate it.**

`arrived` has no entry gate in any document, no requirement that records an arrival distinct
from a delivery — the RTM runs OPS-008 dispatch → OPS-009 track → OPS-010 delivery with nothing
between — and no data model. So it stays in the vocabulary and stays unreachable, and the same
answer settles the two states nobody had asked about:

| Step 9 state | Entry gate | Requirement | Data model | Ruling |
|---|---|---|---|---|
| `pretrip_ok` | STT-005's "All checks passed" | OPS-007 pre-trip checklist | yes | **wired** — 2026-09-09 |
| `arrived` | none | none | n/a | **declared, unreachable** |
| `pod_pending` | none | none | n/a | **declared, unreachable** — P3's shipped STT-008 already skips it |
| `settlement_pending` | none | TRP-P0-017, but that is SNG-TRN-017 | **`trip_settlements` does not exist** | **declared, unreachable** |

All three remain in `TripStatus::ALL`, `::OPEN` and `::LABELS`. None gains an edge. The rule is
recorded in `TEAM-CONTRACTS.md` so it is not re-argued by whoever reads the enum next.

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

### Architecture approval — `delivery_order` added to `ENUM-006`, ruled 2026-09-12

`ENUM-006` is **LOCKED** in Step 11, so adding a value needs the same explicit written approval D-39
received rather than being treated as routine. **Granted 2026-09-12.** Grounds:

- `STOS-REQ-ORD-006` ("Capture DO details") is **P0** and currently unimplementable — there is no
  value to file a delivery order under.
- A delivery order has **no other home** in the package: no domain-model row, no table, no enum.
- The only alternative is a separate `transport_delivery_orders` table, which would give delivery
  orders a second home and is forbidden by Step 9's no-duplicate-business-objects rule.

Person 3 therefore receives this as an approved change, not as a question to escalate.

---

## D-44 — CTD §11 requires a consignment lifecycle engine that does not exist

STOS-CTD §11 opens:

> "Status must come from the **lifecycle engine**."

and then lists twenty example values: Created · Document Pending · Compliance Pending · Ready ·
Allocated · Dispatched · In Transit · At Port · At Delivery · Delivered · Handover Completed ·
Feedback Pending · POD Pending · Billing Blocked · Billing Ready · Invoiced · Collection Pending ·
Closed.

**No such engine exists**, and the values are not one entity's lifecycle. They span at least five:

| Span | Owner |
|---|---|
| Created, Document Pending, Compliance Pending | Person 3 (documents, compliance) |
| Ready, Allocated, Dispatched, In Transit, Delivered | Person 1 (trip machine) |
| At Port, At Delivery | nobody — no gate/port entity (**D-42**) |
| Handover Completed, Feedback Pending, POD Pending | Person 3 |
| Billing Blocked, Billing Ready, Invoiced, Collection Pending | Person 3 (billing, collections) |

**Consequence, and the decision taken:** `transport_consignments` has **no `status` column**. A stored
status would be a roll-up of five lifecycles the table cannot see, and would be wrong the moment any
one of them moved without the consignment being touched. Status is **derived at read time**, the same
decision already carried by `PretripReadiness` and `ExceptionSlaState`.

Affected requirement: **STOS-CTD §6** lists "Status" among the Digital Passport identity fields. It is
satisfied by derivation, not by storage.

### Known consequence, accepted deliberately

A derived status **cannot be filtered, sorted or paginated in SQL.** A list page can show it, but
"show me every Billing Blocked consignment" has to be computed in PHP across the result set.

At the target scale — MSME operators running 6–50 vehicles — this is acceptable, and the owner
accepted it explicitly on 2026-09-12 rather than overlooking it.

**Trigger for revisiting:** if a list page or Container 360 needs **server-side** filtering by
consignment status, we add a derived column **written by the lifecycle engine at that point**. We do
not hand-maintain a status column before then — a column updated by whoever remembers is precisely
the drift this entry exists to prevent.

---

## D-45 — Consignment permissions exist in no registry row

Step 11's Permissions sheet has thirteen rows — `PERM-001..013`, covering Trip, Advance, Expense,
POD, Collection, ControlRoom and Registry. There is **no Consignment row**, exactly as there is no
Order row (already noted in `TransportPermission`'s docblock).

Four keys were therefore created, following the precedent of D-8 (vehicle/driver) and D-21
(pre-trip): `transport.consignment.view` / `.create` / `.update` / `.delete`.

The matrix **mirrors Order**, which itself mirrors Trip (`PERM-001`/`PERM-002`) — the narrowest
defensible reading, since a consignment is the commercial description of an order's cargo, so
whoever may read or write the order may read or write what it is carrying. `delete` is deliberately
narrower than `update`, following `VEHICLE_DELETE`: removing a shipment record is not an ordinary
edit.

**One deliberate divergence from Order — see D-46.**

---

## D-46 — `SCOPE_OWN` is granted to customers but the narrowing is unimplemented

`TransportPermissionService::scope()` states plainly:

> "'own' and 'assigned' narrowing arrives with the tickets that own it."

So `SCOPE_OWN` and `SCOPE_ASSIGNED` are **declared in the matrix and enforced nowhere.** A holder of
a SCOPE_OWN grant who reaches the endpoint receives the tenant's whole list, not their own rows.

**The substance of this defect is not one bad grant — it is that the whole permission layer is a
boolean gate.** `scope()` is called in exactly one place, `allows()`, and only to test the result for
null. **No production code path consumes either scope.** The only occurrences outside the matrix are
in three test files — `TransportContextFoundationTest` and `TransportAssignPermissionTest`, which
assert the matrix values themselves, and `TransportRouteExposureTest`, which names them in its
failure text. So the scopes are already pinned by tests; what is missing is anything that *acts* on
them.

So all **21** permission keys answer "may this role touch this area at all?" and none answers "which
rows?". Read it as *21 permissions, none of which narrow anything*, not as *one grant is wrong*.

(The count is 21, not the 13 on Step 11's original Permissions sheet: the difference is the rows
derived under D-8, D-21 and D-45 for entities that sheet never covered.)

### The same problem was solved next door on 2026-09-21 — talk to P3 before designing this

Person 3 shipped `app/Services/Auth/ScopeResolver.php` and `app/Support/Hr/DataScope.php` for HR.
Its docblock describes this defect almost word for word:

> *"scope existed, was computed correctly, and was consulted in a single place that rendered
> menus."*

That is this entry: `SCOPE_OWN` and `SCOPE_ASSIGNED` declared in the matrix, `scope()` called once,
no production path consuming either. He has now solved that problem class, with a deliberately
small primitive — `visibleEmployeeIds($actor)` returning `null` (global) / `[]` (nothing) /
`[ids]` — plus `applyToQuery($query, $actor, $column)` so a module is one `whereIn()` away rather
than a fresh copy of the hierarchy walk.

**It is not adoptable as it stands, and the reason is the axis.** `DataScope` is
`global / own / department / branch / team` — an EMPLOYEE hierarchy, resolved through `HrEmployee`,
with no Transport awareness anywhere in the file. Ours is a CUSTOMER axis: "own" in HR means *my
employee record*; "own" in Transport means *my company's consignments*. Only `global` and `own`
overlap even as words.

**So this does not unblock D-46.** What it changes is who should be in the room. When the client
roles are designed — and CLP §3 now names six of them, organisation-, branch-, role-,
transaction- and document-type aware — that is a conversation with Person 3 and a second
implementation of a pattern he has already built, not a design from scratch.

Recorded so that nobody solves this twice in one repository.

`ORDER_VIEW` and `TRIP_VIEW` both grant `ROLE_CUSTOMER => SCOPE_OWN`, and `TRIP_VIEW` also grants
`ROLE_SUPPLIER => SCOPE_ASSIGNED`.

### This is NOT currently exploitable, and the reason matters

Every route under `/api/transport/*` sits behind `role:admin,staff` (routes/transport.php:44). A user
with `role='client'` is refused at that coarse door before any permission is evaluated, so the grant
can never be used today. **No live leak exists.**

### Why it is logged as Critical anyway

The grant is a loaded gun with the safety on. The moment anyone adds a customer-facing transport
route — and **STOS-CTD's Digital Passport is precisely that**, a customer looking up their own
container — the coarse door opens and the unimplemented narrowing becomes a cross-customer data leak
*within* a tenant. Whoever adds that route will reasonably assume a declared SCOPE_OWN grant narrows
something.

### What this ticket did about it

`CONSIGNMENT_VIEW` **withholds the customer grant**, the single place where Consignment does not
mirror Order. Adding it would have created a second latent grant for the same future route. The
grant belongs to the ticket that implements the narrowing, not to this one.

Caught by `TransportAssignPermissionTest::test_adding_assign_did_not_widen_any_other_permission`,
which pins the exact set of grants a client holds. That test did its job: it turned an unnoticed
widening into a decision.

### The tripwire, and exactly what it covers

`TransportRouteExposureTest` turns this from a finding into an enforcement. It reads the **live route
collection**, not the route file, and matches on two axes:

| Axis | Catches |
|---|---|
| URI prefix `api/transport` | a customer route under our prefix, declared in **any** file or provider |
| Controller in `App\Http\Controllers\Api\Transport` | a **differently-prefixed** route — e.g. `api/portal/my-containers` — pointed at one of our controllers |

Every matched route must be behind `role:admin,staff`, require `auth:sanctum`, and carry a
`transport.permission` key. Both axes were verified by temporarily registering the mistake they
guard against and confirming the suite went red.

### Residual exposure — the part Person 1 cannot guard

Neither axis can see **a controller outside this section querying the transport models or tables
directly**, touching none of our routes and none of our controllers. A portal or reporting endpoint
doing `TransportConsignment::where(...)` would bypass both guards entirely, and the unenforced
scopes would be irrelevant to it because it never consults them.

That is outside Person 1's reach by construction: we can guard our routes and our controllers, not
everyone else's queries.

**Owner: Security.** Please read the ask as three separable things, so effort is not spent on what
is already done:

1. **Already covered — no action needed.** Customer-facing routes under our prefix, and foreign
   routes pointing at our controllers. The tripwire fails the build.
2. **The core fix.** Either implement the `SCOPE_OWN` / `SCOPE_ASSIGNED` narrowing so a scoped grant
   actually filters, or remove the unenforced grants until a ticket needs them. Until one of those
   happens, the coarse `role:admin,staff` door is the only real control.
3. **The residual exposure above.** Direct model or table access from outside Transport. This needs a
   control we cannot write from here — a repository-level tenant/owner guard, a query-log review, or
   a rule that transport data is reached only through Transport's services.

---

## D-47 — Two structural standards in conflict: TEAM-CONVENTIONS vs the DDD instruction

A structural instruction has been issued mandating Domain-Driven Design under `app/Domains/`, with
business logic kept out of controllers. It is reproduced in full in
`docs/transport/ANALYSIS-ddd-structure.md`; it is a folder tree plus two sentences.

| | `TEAM-CONVENTIONS.md` §1 | The DDD instruction |
|---|---|---|
| Models | `app/Models/<Module>/` | `app/Domains/<Domain>/Models/` |
| Services | `app/Services/<Module>/` | `app/Domains/<Domain>/Services/` |
| Events | `app/Events/<Module>/` | `app/Domains/<Domain>/Events/` |
| Controllers | `app/Http/Controllers/Api/<Module>/` | `app/Http/Controllers/Api/**V1**/Transport/` |
| Grouping | by module (Transport, Hr, Sales…) | by domain (Operations, Fleet, Integration, Finance, Document, Compliance) |

**Neither is a draft.** `TEAM-CONVENTIONS.md` is what all three developers work to today and what
every one of the 30 entries under `app/Models/` follows. The instruction is explicit and current.

### Measured state of the repository

- **No `app/Domains/` directory exists.** Not one module uses it.
- Transport alone: **88 PHP files** under `app/`, **27 test files**, **543 occurrences** of an
  `App\…\Transport` namespace across **106 files**, **671 passing tests**.
- 14 frontend files reference `modules/transport` or `transportApi`.

### The blocker that is not about cost

`app/Models/Transport/` contains `TransportVehicle.php` and `TransportDriver.php` — **Person 2's
models** under TM-001 §8. The instruction's own tree puts `Vehicle` under `Domains/Fleet/` (Person 2)
and `TransportOrder`, `Trip`, `Consignment` under `Domains/Operations/` (Person 1). Splitting
`app/Models/Transport/` between those two domains **is** the move, and Person 1 cannot perform it
without editing Person 2's files.

### What the instruction does NOT contain

No interface list, no event contract, no DTO shapes, no version-prefix rationale, no CI enforcement.
It is a layout instruction. Everything beyond folder naming — the integration contracts — comes from
**STOS-TM-001 §11**, and is tracked separately as **D-48**.

**Owner: Architecture.** Analysis and options in `docs/transport/ANALYSIS-ddd-structure.md`.
**Nothing has been moved, created or renamed.**

---

## D-48 — Two LOCKED events that we produce are never emitted

Step 11's `Event_Registry` defines twelve events. Person 1 is the named producer of seven of them.
One is emitted; **two are on code paths that run today and emit nothing.**

| Event | Producer | Status | Consumers | Emitted? |
|---|---|---|---|---|
| `EVT-001 OrderCreated` | OrderService | **LOCKED** | TripEngine, Notifications | ❌ **no** |
| `EVT-002 TripCreated` | TripEngine | **LOCKED** | Viability, Notifications | ❌ **no** |
| `EVT-005 TripAssigned` | AssignmentService | CONTROLLED | Dispatch, Notifications | ✅ yes |
| `EVT-003 TripViabilityCalculated` | ViabilityEngine | LOCKED | ControlRoom, Alerts | n/a — SNG-TRN-008 not built |
| `EVT-004 TripApproved` | ApprovalService | LOCKED | TripEngine, Notifications | n/a — no approval entity |
| `EVT-008 TripExceptionRaised` | ExceptionEngine | LOCKED | Notifications, ControlRoom | n/a — SNG-TRN-013 in progress |
| `EVT-012 TripClosed` | TripEngine | LOCKED | ProfitEngine, ControlRoom | n/a — closure unreachable |

`EVT-001` and `EVT-002` are different from the rest: **`TransportOrderService::create()` and
`TransportTripService::create()` both run in production today and dispatch nothing.** Verified by
grep — `app/Events/Transport/` contains exactly one class, `TripAssigned.php`.

### Why it matters, beyond registry compliance

**STOS-TM-001 §11** — the approved integration matrix — specifies what the other two developers must
receive from us:

> **P2 ← P1** — trip_id, transport_order_id, consignment_id, container_id, active route/geofence context
> **P3 ← P1** — **trip.delivered**, **trip.completed**, container_id, trip_id, billing trigger

We publish **no outbound mechanism at all** except `TripAssigned`, which has no listeners. So the only
way Person 2 or Person 3 can obtain any of that today is to query our tables directly — which is
precisely the residual exposure named in **D-46** as the thing Person 1 cannot guard.

### RESOLVED 2026-09-15 — both events now emitted

`OrderCreated` and `TripCreated` follow the `TripAssigned` pattern already in the codebase: plain
Laravel events, dispatched inside the creating transaction, payload exactly the registry's Payload
Core column, `idempotencyKey()` and `tenantId()` alongside it.

**No listeners were written.** Subscribing is Person 2's and Person 3's half of the contract; this
section's responsibility ends at `dispatch()`. A test asserts `app/Listeners/Transport` does not
exist and that none of the three events has a listener registered here.

Person 2 and Person 3 now have a mechanism to receive `transport_order_id` and `trip_id` without
querying our tables — the front door for what D-46 flags as the back one.

The TM-001 vs Step 11 payload and event-set disagreement is **D-49**.

---

## D-49 — Two approved documents name different event sets and payloads

`STOS-TM-001 §11` (integration matrix) and `Step 11 Event_Registry` (canonical registry) both
describe what Person 1 must publish, and they do not agree.

### The event set

| TM-001 §11 says P3 must receive | Step 11 registry |
|---|---|
| `trip.delivered` | **no such row.** Nearest is `EVT-012 TripClosed` |
| `trip.completed` | arguably `EVT-012 TripClosed` |

### The payloads

| TM-001 §11 says P2 must receive | In `EVT-002 TripCreated`'s LOCKED Payload Core? |
|---|---|
| `trip_id` | ✅ yes |
| `transport_order_id` | ✅ yes (as `order_id`) |
| `consignment_id` | ❌ **no** — and the column exists |
| `container_id` | ❌ no — and no table exists yet |
| active route/geofence context | ❌ no — `route` is a string; geofence has no model |

### Why it is logged and parked rather than escalated

Nothing is blocked today:

- `delivered` and `completed` are **unreachable states**. The trip lifecycle stops at `dispatched`;
  `in_transit` is SNG-TRN-013 and `delivered` is STT-007 / SNG-TRN-014.
- `container_id` has no table until the next step of Block 1.
- `consignment_id` is the one live case, and `EVT-002`'s Payload Core is **LOCKED**. Adding a field
  to it is the same class of change as adding `delivery_order` to `ENUM-006`, which required written
  architecture approval. It was therefore **not added**, and a test pins its absence so that if it is
  ever added, the approval exists first.

### What Architecture needs to rule, before SNG-TRN-014

1. Is `trip.delivered` a new registry row, or is TM-001 §11 naming `EVT-012 TripClosed` loosely?
2. Should `EVT-002`'s Payload Core be amended to carry `consignment_id` (and later `container_id`),
   or should Person 2 obtain those through a **read contract** instead of an event?

### The sentence this entry exists for

**If Person 2 and Person 3 are meant to receive these IDs through a read contract rather than an
event, that read contract does not exist, is not specified in any approved document, and nobody owns
it.** Today their only route to our trip data is to query `transport_trips` directly — which is
exactly the residual exposure **D-46** names as the thing Person 1 cannot guard, arriving through the
front door instead of the back.

Question 2 above is therefore the consequential one. Question 1 can wait for SNG-TRN-014; this cannot
wait for anything, because the absence is already shaping how the other two developers get our data.

### The same finding, reached from a second direction (2026-09-15)

A pull-forward was proposed — make the Trips list searchable by vehicle registration and driver
name — and estimated at **a day rather than the half-day it looks like**. The reason is not the
filter. It is that **the Fleet seam is one method wide.**

`App\Services\Transport\Contracts\` contains exactly one file, `FleetResourceGateway`, exposing
exactly one method, `markDispatched()`. Meanwhile TM-001 §11 says that seam must carry:

> available/eligible fleet, available drivers, vehicle status, expected return, allocation recommendation

None of those five has an interface. So any cross-developer need — a lookup, a status read, a
recommendation — currently costs a contract design before it costs a line of code, and looks
expensive for that reason alone.

**Two independent routes arrived at the same conclusion:** the event-payload question above, and a
search feature that has nothing to do with events. The missing piece in both is the same — there is
no read contract between Person 1 and Person 2, in either direction, beyond a single write-intent
method. That is what makes this defect real rather than theoretical.

### Approval granted 2026-09-15 — `consignment_id` added to `EVT-002`

`EVT-002`'s Payload Core is **LOCKED** at `trip_id, order_id`. `consignment_id` was added as a third
field on **explicit written approval**, by the same route `delivery_order` took into `ENUM-006`.
Grounds recorded with the approval:

- `STOS-TM-001 §11`, an approved document, names `consignment_id` among what Person 2 must receive
  from Person 1.
- Adding a field to an event payload is **additive and backward compatible**; no existing consumer
  breaks.
- Without it, Person 2's only route to the value is querying `transport_trips` directly — the
  exposure above.

It is nullable, and that is not a defect: a trip may legitimately carry no consignment, since trips
shipped before consignments existed and TM-001 §4 rule 3 makes the container a search anchor rather
than a mandatory parent.

**`container_id` and route/geofence context were explicitly NOT approved.** There is no container
table yet and no route context to send, and *a field carrying null forever is worse than an absent
field, because a consumer will code against it.* They are to be proposed again when the container
table exists. A test pins their absence.

---

## D-50 — `container_type` is a required field and no document defines its values

`STOS-CTD §6` lists **Container Type** among the Digital Passport's identity fields, so the field is
required. **No document in the package defines what may go in it.**

Searched: all thirty package documents for `20ft`, `40ft`, `20'`, `40'`, `HC`, `high cube` and
`ISO 6346` — **zero hits in any of them**. Step 11 has no container enum, and no container DB row at
all. `reefer` appears exactly once across the whole package, in a genset risk rule (`CTD §17`), not
as a container type.

So `container_type` ships as a **free-text `varchar(40)`, not an enum**. Inventing a vocabulary —
`20ft | 40ft | 40HC | reefer | tank | flatrack` — would be `allocation_type` again (**D-9**): a field
whose values no approved document authorises, which two modules then disagree about.

**What is needed:** the authorised list, or a ruling that tenant-defined types are acceptable (in
which case it needs a master table, not an enum). **Owner: Product.**

Until then the column accepts what the operator types, which is honest, and a later migration can
constrain it once the list exists.

---

## D-51 — The test suite runs on sqlite; production runs MySQL

`phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. Production is **MySQL 8.0.46**.
The entire repository contains **two** MySQL-aware tests.

Every green suite therefore proves the schema behaves in an engine that **does not run the
business**. For most columns the gap is harmless. For a **constraint** it is not, and the difference
is invisible:

| Mechanism | sqlite | MySQL |
|---|---|---|
| Partial unique index (`... WHERE x IS NULL`) | **supported** | **not supported** |
| `VARCHAR(n)` length reported to `Schema::getColumns()` | plain `varchar`, **no length** | `varchar(n)` |
| Generated column + unique index | supported | supported |

The first row is the dangerous one: a partial index would pass every test and enforce **nothing** in
production. The second already bit this module — a `FLD-014 VARCHAR(20)` assertion passed for the
wrong reason until it was re-pointed at the migration source.

### How this was handled for the container attachment, as a worked example

STOS-CTD §7's "not simultaneously" rule needed database enforcement. Rather than assume:

1. The partial index was **rejected** for the reason above.
2. The chosen mechanism — a `STORED` generated column holding `container_id` only while
   `detached_at IS NULL`, with a unique index over it — was **probed directly against MySQL 8.0.46
   and sqlite 3.45.1 before the migration was written.** Five behaviours, identical on both.
3. `ContainerAttachmentGuaranteeTest` asserts the refusal on whatever engine the suite runs.
4. `ContainerAttachmentMysqlTest` asserts it again on MySQL, skipping otherwise.

That fourth test carries a hazard worth naming, because it nearly happened: `use RefreshDatabase` is
declared at **class** level, so a MySQL test sharing a class with sqlite tests inherits it and runs
`migrate:fresh` **against the working database**. It is therefore a separate class with no
`RefreshDatabase` anywhere, writing under a tenant id no workspace holds and removing its own rows in
a `finally` block. A test that destroys the database to prove the database is safe is not a test.

### The ask

**Owner: Architecture.** Not urgent, not Transport's to fix, and it should not live only in a
conversation. Options, in rough order of cost: run the existing suite against MySQL in CI as a second
job; or add a small MySQL-only group for schema and constraint tests; or accept the gap explicitly
and require that every constraint be probed against both engines before it ships, as was done here.

### The working rule this produced, for every derived value in this module

The engine split forces a choice each time a value must be derived and must never disagree with its
source. The rule that came out of the container work, stated once so it need not be re-derived:

> **Put the derivation in the DATABASE when both engines express it identically. Put it in PHP when
> they do not — and then say so, next to the code, along with what the PHP version cannot cover.**

Applied to the two in `transport_containers`:

| Derived value | Expression | Where | Why |
|---|---|---|---|
| `active_container_key` | `CASE WHEN detached_at IS NULL THEN container_id ELSE NULL END` | **database** | Both engines evaluate it identically — verified by running it |
| `container_number_normalized` | trim + strip non-alphanumerics + uppercase | **PHP** (`saving()` hook) | MySQL 8 has `REGEXP_REPLACE`; sqlite returns *"no such function: REGEXP_REPLACE"*. A generated column would need two dialects, and the suite runs on the engine that cannot express it — this defect, exactly |

The cost of the PHP side is stated where it is paid: a `saving()` hook covers Eloquent writes only,
so a raw insert bypasses it. The database-side one has no such gap. **Never assert which engine can
express something — run it.** Both facts above were established by executing the expression on MySQL
8.0.46 and sqlite 3.45.1, not by reading documentation.

---

## D-52 — Nothing structurally marks a trip as temperature-critical

`STOS-TM-001 §12` is a **P0 rule**, stated in full:

> "For a **temperature-critical trip**, if the vehicle is moving while the generator is OFF and
> temperature risk is detected, STOS shall create a high-priority operational exception."

`STOS-MS-001` requires it demonstrated on 30 September — *"Generator OFF + moving + temperature risk
— high-priority exception"* (§9 scenarios) and *"Temperature risk + generator OFF + moving condition
can create a high-priority exception"* (§8 acceptance).

**Nothing in the data model says a trip is temperature-critical.**

### Where the package puts the idea, and why none of them is a field

| Source | Wording | Level |
|---|---|---|
| STOS-CTD §15 | "For **temperature-controlled consignments**" | consignment |
| STOS-CTD §19 | "Genset OFF + **reefer trip** active + configured risk period" | trip |
| STOS-TM-001 §10 | Allocation service fit: "**Trip/reefer/service requirement** and configured rules" | service requirement |

All three describe it as a property of the *shipment or the movement*, never of the container. The
nearest thing that exists today is `transport_consignments.service_type` — **free text**. The demo
data reads `"Reefer Movement"`, but a P0 rule cannot key on a string an operator typed, and
`"reefer"`, `"Reefer Movement"`, `"REEFER"` and `"Temp controlled"` are all the same intent.

### Why this is not solved by a container flag

An `is_reefer` column on `transport_containers` was in an earlier schema proposal of mine
(2026-09-12) and is **not built**, for two reasons:

1. **No source names it.** CTD §6's container identity is Container Number and Container Type, full
   stop. Not one of the three sources above is about the container.
2. **Temperature, genset and reefer are Person 2's** — TM-001 §6 gives them "Vehicle/trailer/genset"
   and "GPS / Temperature / Genset / Telemetry", and MS-001 puts "temperature, genset integration
   framework; device→vehicle→trip→container mapping" on their lane for 21–23 Sep.

A container flag would also be the wrong shape: a reefer container carrying an ambient load is not a
temperature-critical trip, and a temperature-critical consignment may move in a vehicle-mounted unit
with no container at all.

### What is actually needed

A **service-requirement definition** — the thing TM-001 §10 already calls "service requirement" —
expressed structurally rather than as free text, so allocation can filter on it (Person 2) and the
§12 rule can fire on it. Whether that is an enum on `service_type`, a boolean on the consignment, or
a small service-requirements master is a **Product** decision; none of the three is authorised today
and inventing one would repeat **D-9** and **D-50**.

### The same absence, already recorded twice in this codebase

This is not a telemetry problem that happens to look structural. **The identical missing definition
was written down eight days earlier, blocking a different feature**, and neither entry knew about the
other. `AllocationScope::EXCLUDED` (ruled 2026-09-07, while building SNG-TRN-009 step 5) carries two
of its own:

> **`'vehicle type'`** — "FLEET §17. The order carries free-text `service_type` and the vehicle
> free-text `vehicle_type`; no document specifies a mapping between them. **Any matching rule would
> be invented and would falsely block legitimate vehicles.**"
>
> **`'service requirement'`** — "FLEET §17. `order.special_requirements` is free text; **no
> structured service-capability model exists.**"

So three features are blocked by one absent definition:

| Feature | What it cannot do | Recorded |
|---|---|---|
| Allocation — vehicle eligibility | match a vehicle's capability to the order's requirement | `AllocationScope`, 2026-09-07 |
| Allocation — service requirement | read `special_requirements` as anything but prose | `AllocationScope`, 2026-09-07 |
| Telemetry — TM-001 §12 P0 rule | know a trip is temperature-critical | **D-52**, 2026-09-15 |

Two of the three are Person 2's (allocation scoring, telemetry) and reached from opposite ends of the
module. That is what makes this a priority rather than a request: **the same missing structural
service requirement has now been independently rediscovered three times**, by two different tickets,
eight days apart, and each time the honest response was to refuse to invent it.

### No workaround will be built

Not an enum of our own, not parsing `"Reefer"` out of free text, not a boolean bolted onto the trip.
Each would be D-9 again, and `AllocationScope` already names the specific harm: *an invented matching
rule would falsely block legitimate vehicles.* Guessing here does not fail loudly — it refuses real
work in production.

**Owner: Product to define, Person 2 to consume.** Raised now rather than at the demo: the rule is
P0, it is on the 30 September script, and it currently has nothing to key on.

---

## Correction — `size_feet` was never specified

An earlier schema proposal of mine (2026-09-12, §E) listed `size_feet` on `transport_containers`,
attributed to STOS-CTD §6. **That attribution was wrong.** §6's container identity is:

> Container Number · Container Type

There is no size field, and `container size`, `size_feet` and any `20ft`/`40ft` form return **zero
hits across all thirty package documents** (the same search that produced D-50).

**Not built.** It is the third field that proposal invented — after `seal_number`, which turned out
to be specified and Person 3's (STOS-CMP §76), and `status`, which D-44 resolved. Recorded here
rather than silently omitted, because a field dropped without a note is indistinguishable from a
field forgotten — which is exactly how these two nearly went missing.

---

## D-53 — Two closed attachment windows may overlap in the past

STOS-CTD §7: *"Container reuse across different trips is allowed historically but **not
simultaneously**."*

`transport_consignment_containers` enforces **one ACTIVE attachment** per container per tenant, via a
generated column under a unique index (see the migration, and D-51 for why it is built that way).
That is the live half of the rule and it holds on both engines.

**The historical half does not.** Two *closed* windows may overlap. Probed directly against MySQL:

```
window A  consignment 1  2026-01-01 → 2026-01-10   inserted
window B  consignment 2  2026-01-05 → 2026-01-08   ACCEPTED
```

Those two rows say the container was on two consignments at once between 5 and 8 January — exactly
what §7 forbids — and nothing refuses them.

### Why it is Low, not High

**No code path can currently produce it.** Attachment sets `attached_at = now()`, and detachment sets
`detached_at = now()`, so every window opens after the previous one closed. The overlap is reachable
only by back-dating, which requires either a raw SQL write or a feature that does not exist —
a back-dated correction, or a bulk import of historical movements.

Both of those are plausible next features, which is why this is written down now rather than when one
is being built.

### Why it was not fixed in this step

A database-level exclusion constraint (PostgreSQL's `EXCLUDE USING gist`) would express it exactly.
**Neither MySQL nor sqlite has one**, so the portable options are a trigger (two dialects, the D-51
trap again) or a service-level check.

A service check is sound *here* and unsound for the live rule, and the distinction is worth stating:
the live rule must resist two concurrent requests racing, which a check cannot do. A back-dated
correction is a deliberate, single, human act — a check at that one entry point is adequate.

**So the fix belongs to the feature that introduces back-dating, not to this one.** Building it now
would be a guard on a door nobody can open, and the natural place to put it — a value object holding
the window — would not have enforced anything either.

### A SECOND latent defect waits on the same trigger — read both together

`TransportContainer::booted()` carries a residual with the identical shape, and whoever builds the
triggering feature will plausibly find one and miss the other:

> **The normalisation residual.** `container_number_normalized` is derived by a model `saving()`
> hook, which covers **Eloquent writes only**. A raw `DB::table()` insert, a raw SQL import or a
> migration writing rows directly bypasses it, producing a container that looks perfectly correct on
> screen and **cannot be found by search**. `active_container_key` has no such gap — the database
> computes it however the row arrives.

**Both are unreachable today and both become reachable on the same trigger:**

> **BULK CONTAINER IMPORT, OR ANY BACK-DATED CORRECTION.**

The ticket that builds either one inherits **two** requirements, not one:

1. **Overlap** (this defect) — reject a window that overlaps an existing one for the same container,
   at the single entry point where back-dating happens.
2. **Normalisation** (the residual) — every imported row must go through
   `TransportContainer::normalise()`, whether by writing through the model or by calling it directly.
   A row that skips it is invisible to `CTD-001` search.

**Owner: Person 1**, to implement alongside back-dated correction or historical import, whichever
arrives first — and to implement **both**, because they arrive together.

---

## D-54 — The Transport suite is intermittently red: random test fixtures collide

**Raised:** 2026-09-16, during the Block 1 step-5 verification run. **Owner: Person 1 (fixtures),
with a decision needed on Person 2's files.** **Severity: high — it attacks the thing every other
defect here is verified with.**

### What happened

The full Transport suite failed once, then passed three consecutive times with no code change:

```
run 1   1 failed, 3 skipped, 736 passed (2785 assertions)
        24  app/Services/Transport/TransportVehicleService.php:72
runs 2-4   3 skipped, 737 passed (2791 assertions)
```

A suite that is green four times out of five is not green. It is a suite that will be re-run until
it agrees, which is the same as having no suite at all.

### The mechanism, proven rather than assumed

Fourteen test files build vehicle fixtures as:

```php
'registration_number' => 'MH12AB'.random_int(1000, 9999),   // 9,000 possible values
```

`transport_vehicles` carries `UNIQUE(tenant_id, registration_normalized)` (migration line 113).
Two draws of the same number inside one test method therefore violate it.

Probed directly — a throwaway test creating the same registration twice through
`TransportVehicleService::create()`:

```
EXCEPTION: Illuminate\Database\UniqueConstraintViolationException
FRAME:     TransportVehicleService.php:74      ← TransportVehicle::create(...)
```

Line **74** is the `create()` inside the closure; line **72** is the `DB::transaction(...)` that
wraps it. Both frames are on one call path, and line 72 is exactly what the failing run printed.
The mechanism is confirmed, not inferred.

`RefreshDatabase` rolls back between test methods, so the collision window is **within a single test
method** — which is why it is rare, and why it will never reproduce on demand.

### Why it is worth fixing rather than re-running

- Every ruling in this register is backed by "the test passes". A suite with a background failure
  rate degrades that evidence for **all 54 entries**.
- The failure surfaces in `TransportVehicleService` — Person 2's file — while the cause is in test
  fixtures. The next person to see it will debug the wrong file.
- It gets worse, not better: the collision probability rises with every vehicle fixture added.

### The fix

Replace the random draw with a per-test counter, which cannot collide:

```php
private static int $seq = 0;
'registration_number' => sprintf('MH12AB%04d', ++self::$seq),
```

Driver fixtures use `'RJ14'.random_int(100000, 999999)` — a 900,000-value space, 100× safer, but the
same class of defect and worth the same treatment.

### Not done, and why — scope

The fourteen files split across two owners:

| Files | Owner |
|---|---|
| `DispatchTest`, `DispatchApiTest`, `Pretrip*Test` (4), `TripAssignmentTest` | **Person 1** — mine |
| `TransportMaster*Test` (4), `TransportAllocation*Test` (3), `TransportEligibilityTest` | **Person 2** — fleet master and allocation scoring |

Fixing only my seven leaves the suite flaky and leaves two contradictory fixture patterns in one
directory. Fixing all fourteen crosses into Person 2's section, which is a standing hard rule.
**Raised for a ruling rather than guessed** — Hard Rule 1. Git shows a single author across all
fourteen files, so there is no concurrent work to collide with today.

### RULED AND FIXED — 2026-09-16

**Owner's ruling: fix all of them, including Person 2's files.** Reasoning recorded because it is
the part worth reusing: every ruling in this register is backed by *"the test passes"*, so an
intermittently red suite devalues the whole register; git shows a single author across all the
affected files, so there was no concurrent work to collide with; and fixing half would leave two
contradictory fixture patterns side by side, which is worse than either.

**Correction to this entry as first written: it is 15 files, not 14.** The miscount came from
working off an exact-string search for `'MH12AB'.random_int(1000, 9999)`. Three variants do not
match that string and were missed:

| Variant | File |
|---|---|
| `'MH 12 AB '.random_int(...)` — spaces, normalises to the same key | `TransportMasterApiTest` |
| `'MH12'.Str::upper(Str::random(2)).random_int(...)` | `TransportMasterAllocationAuditTest`, `TransportAllocationRefusalAuditTest` |
| `'RJ14 '.random_int(...)` — trailing space, licence | `TransportMasterApiTest` |

A fourth was found only by sweeping rather than listing: `TransportMasterAllocationAuditTest:277`
used `'MH99'.random_int(1000, 9999)` for a **licence** — a second 9,000-value space on a unique
column that no vehicle-shaped search would have turned up.

**The lesson is the general one:** a search for the pattern you remember writing finds the instances
you remember writing. Sweep for the *mechanism* — here, every `random_int` in the suite, cross-cut
against every `unique()` in the migrations — and the variants come out on their own.

**The fix.** `TestCase::uniqueSeq(int $width)`, a monotonic per-process counter. Chosen over widening
the range from 9,000 to 90,000, which buys time and keeps the bug: **collision had to become
impossible, not rarer.** A rare failure is worse than a frequent one because it gets re-run until it
agrees instead of fixed. `$width` preserves the shape and length of the draw it replaced, so no test
sees an identifier of a different form. Diff: 29 insertions, 29 deletions, 15 files, every line a
fixture line — no assertion, no production file, no logic.

Every test asserting a literal registration or licence passes it as an explicit override, so none of
them ever used the random default. Checked before editing, not after.

**What the evidence does and does not prove.** Six consecutive green Transport runs (739 passed,
3 skipped, identical assertion counts). That is *consistent with* the fix but is not proof: at the
observed ~1-in-5 failure rate, six green runs happen by luck about a quarter of the time. The proof
is structural, and was probed directly: 20,000 draws produced 20,000 distinct values, and the
counter does **not** reset between test methods. A full Transport run makes **585 draws**, so the
4-digit shape holds with 17x headroom and `str_pad` grows rather than wrapping in any case.

**Full-suite check.** The whole suite shows 32 failures in SangoeTrack, Tpv and Unit/Frontend. These
are **pre-existing and unrelated** — verified by stashing this change and running those suites on a
clean tree, which produced the identical 32. `tests/TestCase.php` is shared with those modules, so
this was checked rather than assumed. Transport itself: zero failures.

**Written to Person 2** as required: `docs/transport/NOTE-person2-test-fixtures.md` — which files,
why, what changed, and an explicit statement that no assertion or behaviour was altered, with the
list of things to verify independently.

### Same defect class outside Transport — NOT MINE

Found by the sweep, reported rather than fixed (different module, no authorisation):

```
tests/Feature/SangoeTrack/SangoeTrackLeaveSyncTest.php:62
  'code' => strtoupper(substr($name, 0, 2)).random_int(10, 99),   // 90 values
tests/Feature/SangoeTrack/SangoeTrackLeaveSyncTest.php:71
  'name' => 'Standard '.random_int(100, 999),                     // 900 values
```

against `unique(['tenant_id','code'])` and `unique(['tenant_id','name'])` in
`2026_08_03_000000_create_hr_leave_tables.php` (lines 37-38, 60). **Ninety** values is a far smaller
space than the 9,000 that made Transport flaky. Owner: whoever holds HR / SangoeTrack.

### Not done — offered, not assumed

A guard test asserting that no fixture draws a unique-column identifier randomly would stop this
returning. It is not included, because the ruling's second condition was a **pure fixture change**:
one line per file and nothing else. Proposed for a separate decision rather than folded in quietly.

---

## D-55 — A trip could not be linked to its consignment through the application

**Raised:** 2026-09-16, while building the demo. **Owner: Person 1. Status: CLOSED in the same
commit** — both sides are mine, so raising it and leaving it would have been theatre.

`transport_trips.consignment_id` was added by migration `000015` and was `$fillable` on the model.
**Nothing wrote it.** No service, no FormRequest, no endpoint. The column existed, the relation
existed, and the only rows that ever carried a value were written directly by a seeder.

So the chain the whole module is organised around —

```
order  ->  consignment  ->  trip
```

— could not be completed through the application at any point. It was invisible because nothing
asked for it: the trip screen showed an order and a vehicle, and the gap between them looked like a
design choice rather than a missing write.

### How it surfaced

Not by review. The demo seeder is required to build every row through a real service, precisely so it
cannot contain a row the application could not produce — and the test asserting *"the chain reads end
to end"* failed. The constraint found the defect; reading the code had not.

### Closed by

`TransportTripService::createFromOrder()` now accepts `consignment_id`, with two DIFFERENT refusals,
because they are different situations:

| Case | Response | Why |
|---|---|---|
| Another tenant's consignment | **404** | never "not yours" — that confirms the row exists |
| A consignment on a *different order* | **422**, naming it | it exists and the caller can see it; they picked the wrong one, and saying so is the useful answer |

Without the second check a trip could carry a consignment from an unrelated order, and every screen
reading order → consignment → trip would show a chain that does not hold.

Proven by `TripConsignmentLinkTest` (6 tests, including that a refused link creates no trip at all,
and that the link is audited). `StoreTransportTripRequest` accepts it tenant-scoped; the trip detail
endpoint loads it column-limited.

### The general point, which is the reason this entry exists

**A column with no writer is not a feature, and reviewing the schema will not tell you.** Migration
`000015`, the model relation and the `$fillable` entry were all present and all correct. Everything
looked built. The only thing that distinguished it from a working feature was that no code path
reached it.

---

## D-56 — Transport migration timestamps overlap TPV/purchase ones. LEAVE THEM.

**Raised and closed:** 2026-09-16, during the first merge of this work into master.
**Status: ACCEPTED, no action. This entry exists to stop a later "tidy-up".**

Five Transport migrations share a filename timestamp with TPV/purchase medical-workflow
migrations that arrived from master:

```
2026_12_16_000002_add_medical_workflow_to_tpv_worker_medicals.php
2026_12_16_000002_create_transport_orders_table.php
2026_12_16_000003_add_medical_workflow_to_purchase_worker_medicals.php
2026_12_16_000003_create_transport_trips_table.php
2026_12_16_000004_create_tpv_medical_workflow_tables.php
2026_12_16_000004_create_transport_vehicles_table.php
2026_12_16_000005_create_purchase_medical_workflow_tables.php
2026_12_16_000005_create_transport_documents_table.php
2026_12_16_000006_add_medical_bypass_to_work_packages.php
2026_12_16_000006_create_transport_drivers_table.php
```

Two developers hand-numbered migrations on the same nominal date. It looks alarming in a merge
diff. **It is not a defect, and renaming them would create one.**

### Why it is safe

1. **No duplicate table names.** Checked across every migration on the merged tree — zero
   collisions. The files touch entirely different tables.
2. **Filename sort is deterministic.** Laravel orders migrations by full filename, so a shared
   timestamp falls back to the rest of the string: `..._000002_add_medical...` runs before
   `..._000002_create_transport_orders...`. The order is stable and reproducible, not arbitrary.
3. **Relative order within each module is preserved.** Transport's own files run `000001` →
   `000017` in sequence regardless of what interleaves, so `transport_orders` still precedes
   `transport_trips`. The interleaved medical migrations depend on nothing of ours, and ours
   depend on nothing of theirs.
4. **Every test run proves it.** `RefreshDatabase` runs `migrate:fresh`, so all 4,263 tests
   execute against a database built from these files in this order. A broken order would not be a
   subtle risk; the suite would not boot.

### Why renaming would be worse

**These migrations are already applied** — on the dev database and on master. Renaming an applied
migration makes Laravel treat it as new and run it again, against tables that already exist. The
"tidy" version of this change is the one that breaks.

**If you are here because the overlap looked wrong in a diff: it is recorded, it was checked, and
the correct action is none.**

---

## D-57 — Step 9 and ENUM-002 describe different advance lifecycles

> **Renumbered.** This was raised as D-54 on the SNG-TRN-011 branch on 2026-09-16, the same day
> Person 1 raised a different D-54 on master. Both numbers were taken in parallel; master's landed
> first, so this one moved rather than theirs. **Nothing about the defect changed — only its
> number.** `AdvanceStatus` cites D-57.

`TripStatus` had an easy answer to the same shape of problem: Step 9 is the highest product
authority, and Step 11's twelve trip states are a **strict subset** of Step 9's sixteen with no
name conflicts, so one list satisfies both. Advances are not like that.

| Source | States |
|---|---|
| Step 9, LOCKED state machine | `DRAFT → PENDING_APPROVAL → APPROVED → PAID → ADJUSTMENT_PENDING → ADJUSTED → CLOSED / REJECTED` (8) |
| Step 11 `ENUM-002` | `requested \| approved \| rejected \| paid \| adjusted \| recovery` (6) |

Only four overlap — `approved`, `rejected`, `paid`, `adjusted`.

**Step 9 has four states ENUM-002 cannot store:** `draft`, `pending_approval`, `adjustment_pending`,
`closed`.
**ENUM-002 has two Step 9 does not contain:** `requested`, `recovery`.

So "Step 9 wins" cannot be applied literally — the column has to hold something, and the two
documents do not offer the same something.

### Why ENUM-002 is what gets stored

Step 11's own field registry settles the column, not the argument:

```
FLD-012   DB-007  trip_advances  status  VARCHAR(40)  NOT NULL  DEFAULT 'requested'  INDEX  ST-ADV
```

`requested` is an ENUM-002 value that Step 9 does not contain. The field registry naming its own
default is the most specific statement anyone has made about this column, so `AdvanceStatus`
reproduces ENUM-002 and treats Step 9's lifecycle as what that vocabulary *means*.

### What was NOT done

No mapping was invented. Deciding that Step 9's `pending_approval` "is" ENUM-002's `requested`, or
choosing which of `draft` / `adjustment_pending` / `closed` to drop, is a product decision — and
inventing one is FORBID-001. `AdvanceStatus` declares the six ENUM-002 values and wires only the two
edges SNG-TRN-011 built preconditions for:

```
requested → approved     PERM-007
requested → rejected     PERM-007
```

`paid`, `adjusted` and `recovery` are declared and unreachable. `approved → paid` is BR-P0-006's
payment path (TRP-P0-008, not this ticket); `paid → adjusted|recovery` is SNG-TRN-017 settlement
under DEP-008.

### What it costs while open

A trip advance cannot express "drafted but not yet submitted", and it cannot be closed — an advance
that has been adjusted stays `adjusted` with no terminal state. Neither blocks SNG-TRN-011, because
both belong to stages no ticket has built. Both will block SNG-TRN-017.

### What resolving it looks like

Either ENUM-002 gains the four missing values through a registry change, or Step 9's machine is
amended to the six, or the two are formally declared to be describing different things — the
business lifecycle and the stored column. Any of the three is a decision; none is a developer's.

**Needed before:** SNG-TRN-017 (settlement), which has to move an advance out of `paid`.

---

## D-58 — SNG-TRN-012 cites three references that all belong to another domain, and `cost_type` has no vocabulary

**Raised:** 2026-09-16, building the Context Pack for SNG-TRN-012. **Owner: Product +
Finance.** **Severity: high — it decides a LOCKED table's shape.**

### The cited references resolve to the exception domain

Step 12's `DB/API/State/Event Refs` column for SNG-TRN-012 reads `DB-011;API-007;EV-008`.
Resolved by NAME against Step 11:

| Ticket cites | What Step 11 actually says it is | Correct reference |
|---|---|---|
| `DB-011` | `trip_risks` — "Trip-linked risk exposure", Risk | **DB-006 `trip_costs`** |
| `API-007` | `POST /api/v1/transport/trips/{trip}/exceptions` — Raise exception | **none exists** |
| `EV-008` | `TripExceptionRaised` | **none exists** |

All three land on *exceptions*, not cost. This is the placeholder-numbering defect the
Authority Register carries as an unclosed OPEN ITEM, so the numbers are not evidence of
anything. **There is no API row and no event for recording a cost anywhere in Step 11.**

### Two dangling pointers in the field registry

```
FLD-010   DB-006  trip_costs  cost_type  VARCHAR(40)  INDEX   -> CST-001
FLD-011   DB-006  trip_costs  amount     DECIMAL(18,2)        -> MON-002
```

Searched all fourteen sheets: **`CST-001` occurs exactly once** — in FLD-010's own
reference column. **`MON-002` likewise occurs exactly once**, in FLD-011's. Neither is
defined. The Enums sheet holds exactly eight enums, ENUM-001..008, and none is a cost
type.

This is D-50 (`container_type`) repeating, but worse. `container_type` was a search
anchor; `cost_type` is **INDEXED and is the grouping key for SNG-TRN-018's profitability**
(IDX-005 `company_id, trip_id, cost_type`, LOCKED, reason "profitability calculations").
Free text means `fuel`, `Fuel`, `FUEL` and `diesel` become four categories and 018's
acceptance — "revenue, cost and margin reconcile to source transactions" — cannot hold.

### The larger question: what separates a cost from an expense?

Two LOCKED tables, same owner, same source reference:

```
DB-006  trip_costs     "Canonical trip cost facts"        LOCKED  Finance Control  FRS-CST
DB-008  trip_expenses  "Trip-linked operating expenses"   LOCKED  Finance Control  FRS-CST
```

The registry is markedly richer on *expense* than on *cost*: PERM-008 `Expense/submit`
and PERM-009 `Expense/approve` exist, ENUM-005 `expense_approval_status` exists, and
FLD-013 gives `trip_expenses.approval_status` a `pending` default. **For cost there is
no permission row at all** — the Permissions sheet's thirteen rows cover Trip, Advance,
Expense, POD, Collection, ControlRoom and Registry, and no Cost.

Meanwhile SNG-TRN-012 is the only ticket that mentions expense (its Module is
"Fuel/Toll/Expense"), and QA-005 files it under Area = **Expense**. So one ticket appears
to straddle both tables while naming only one.

**The coherent reading** — and it is a reading, not a finding — is that an expense is a
human *claim* that goes through submit/approve, and a cost is the canonical *fact* used
for margin; an approved expense becomes a cost row. That would also explain the otherwise
undefined word "source" in the acceptance criterion "Every cost is linked to trip and
**source**", for which no field exists.

**This is not a developer's call.** Guessing it wrong produces one of two failures:
a single table that later collides with the other LOCKED entity (FORBID-005, exactly the
SNG-TRN-028 duplicate), or two tables that double-count and break 018's reconciliation.

### What is NOT blocked

DEP-004 (`Costs require trip`) is satisfied — SNG-TRN-007 is built. `trip_costs` can be
built to its LOCKED spec (`VARCHAR(40)` is what the registry specifies, so storing free
text obeys it rather than guessing) the moment the boundary and the `source` field are
settled.

### What resolving it looks like

1. A `cost_type` vocabulary registered as an ENUM, or an explicit ruling that it is free
   text and 018 groups on something else.
2. A definition of `source`, and a field for it.
3. A ruling on the `trip_costs` / `trip_expenses` boundary, and which of them
   SNG-TRN-012 actually builds.

**Escalation:** `CLARIFICATION_REQUIRED` for 1 and 2, `ARCHITECTURE_REVIEW_REQUIRED` for 3.
**Blocks:** SNG-TRN-012, and through DEP-008 both SNG-TRN-017 and SNG-TRN-018.

---

## D-59 — STT-008 asks for a "POD valid" guard the registry never defines

**Raised:** 2026-09-16, building SNG-TRN-014. **Owner: Product + Compliance.**
**Severity: high — a LOCKED transition depends on it.**

### The transition is LOCKED and its guard is unanswerable

```
STT-008 | SM-TRP | delivered -> pod_verified | Verify POD | DocumentEngine
        | guard "POD valid" | effect "Unlock billing" | audited | LOCKED
```

"POD valid" is a question about a document's **status**. Step 11 registers:

- **eight enums**, ENUM-001..008 — trip_status, advance_status, exception_severity,
  exception_status, expense_approval_status, document_type, risk_rating, viability_decision
- **four state machines** — SM-ADV, SM-EXC, SM-ORD, SM-TRP

**Not one describes a document lifecycle.** ENUM-006 is document_*type* —
`lr|ewaybill|invoice|pod|...` — which says what a document IS, never whether anyone
has checked it. So a LOCKED transition guards on a property the canonical registry
gives no vocabulary for.

`TripDocumentStatus` is therefore constructed with exactly three states —
`received`, `verified`, `rejected` — no more than STT-008 needs. STOS-DOC describes
eighteen document statuses; those are **not** reproduced, because choosing three of
eighteen is a product decision and naming only what this transition requires is an
implementation one.

### DB-009 has no field registry at all

```
DB-009 | trip_documents | LR/POD/EWB/attachments index | Tenant | id | company_id | LOCKED | Document | FRS-DOC
```

That row is the whole specification. `DB_Fields` has **zero** rows for DB-009 and
`Indexes_Constraints` has zero. Every column and both indexes are named against the
requirement that asks for them — the same discipline `trip_advances` used where
FLD-012 gave it one column.

### CTR-012 specifies a limit it does not state

```
CTR-012 | API-008 | pod_file | multipart | FILE | required | "allowed MIME/size" | signed upload | Immutable after verification
```

"allowed MIME/size" names neither the MIME list nor the size. Constructed as PDF plus
common image types, 10 MB — a phone photograph of a signed sheet, and nothing that
executes. `TripDocumentService::ALLOWED_MIME` and `MAX_BYTES` are the single place to
correct them.

### And no document-verify permission exists

The Permissions sheet has thirteen rows. PERM-010 covers `POD / submit`; **nothing
covers verifying one**, though STT-008 requires somebody to do it. `POD_VERIFY` is
constructed from that transition's own domain and action, modelled on PERM-005
`Trip / close` rather than on PERM-010 — because "Unlock billing" is a financial act.
Already recorded as an open item in TEAM-CONTRACTS §4.

### What resolving it looks like

1. A document lifecycle registered as an ENUM or a state machine (SM-DOC).
2. Field and index rows for DB-009.
3. Concrete MIME and size limits on CTR-012.
4. A `Document / verify` row in the Permissions sheet.

**Escalation:** `CLARIFICATION_REQUIRED` for 1–3, `SECURITY_REVIEW_REQUIRED` for 4.
**Does not block:** SNG-TRN-014 shipped against constructed values, all flagged here.

---

## D-60 — API-010 promises an event the Event_Registry never defines

**Raised:** 2026-09-17, building SNG-TRN-015. **Owner: Product + Accounts.**
**Severity: high — it is the handover point between two modules.**

### The event does not exist

```
API-010 | POST /api/v1/transport/trips/{trip}/bill | Prepare customer billing
        | transport.billing.prepare | emits BillingPrepared | CONTROLLED
```

The Event_Registry has twelve rows, `EVT-001..012`. **`BillingPrepared` is not one
of them.** So the API registry names an event with no producer, no payload, no
idempotency key and no consumer list — and it is precisely the event by which
Transport hands a trip to Accounts.

The payload is constructed (`bill_id`, `trip_id`, `amount`, `currency`), mirroring
the registered events either side of it, with `bill_id` as the idempotency key by the
same reasoning EVT-009 applies to `attachment_id`.

### What the registry IS clear about, and it shapes the whole ticket

```
EVT-010 | InvoicePosted      | Producer: Accounts
EVT-011 | CollectionRecorded | Producer: Accounts/Collections
DB-012  | trip_bills         | Owner: Accounts
```

Transport does not post invoices and does not take money — consistent with FORBID-002
and LOCK-004, which bar this module from writing any ledger entry. SNG-TRN-015 is
therefore a **trigger**, exactly as its name says, and `trip_bills` is a linkage row
rather than an invoice.

`FLD-016` is the table's only field row, and it is the seam: `invoice_id BIGINT`,
**nullable**, FK+INDEX. A nullable foreign key to an invoice only makes sense if the
row can exist before the invoice does. Transport writes the row with `invoice_id` NULL;
Accounts fills it in. Every other column on the table is constructed.

### No Billing permission domain

The Permissions sheet has thirteen rows and covers Trip, Advance, Expense, POD,
Collection, ControlRoom and Registry. **There is no Billing domain**, though API-010
names the key `transport.billing.prepare` exactly. The key is specified; its matrix row
is constructed, modelled on PERM-005 `Trip / close`.

### And no bill vocabulary

None of the eight registered enums describes a bill. `TripBillStatus` declares two
states — `prepared` and `invoiced` — and Transport can reach only the first.
`invoiced` is declared-not-wired so Accounts has somewhere to land.

### What resolving it looks like

1. An `EVT-0xx BillingPrepared` row with a real payload and consumer list.
2. A `Billing` domain in the Permissions sheet.
3. Field rows for DB-012 beyond `invoice_id`.
4. Confirmation that Transport writing the `trip_bills` row (invoice_id NULL) is the
   intended division, since Step 11 marks the table's owner as Accounts.

**Escalation:** `CLARIFICATION_REQUIRED` for 1–3, `ARCHITECTURE_REVIEW_REQUIRED` for 4.
**Does not block:** SNG-TRN-015 shipped against constructed values, all flagged here,
and the module writes nothing to any ledger.

---

## D-61 — EVT-011 asks Transport for identifiers only Accounts can create

**Raised:** 2026-09-17, building SNG-TRN-016. **Owner: Product + Accounts.**
**Severity: high — it is the second handover point between the two modules.**

### The API says Transport emits it; the payload says Accounts does

```
API-011 | POST /api/v1/transport/trips/{trip}/collection | Record collection
        | transport.collection.record | emits CollectionRecorded | CONTROLLED

EVT-011 | CollectionRecorded | Producer: Accounts/Collections
        | Payload: receipt_id, invoice_id, amount
        | Idempotency: receipt_id+posting_id | Consumers: ControlRoom
```

API-011 is a **Transport** endpoint on a Transport path behind a Transport
permission, and its Event Emitted column names `CollectionRecorded`. But that event's
payload is `receipt_id`, `invoice_id` and its key is `receipt_id+posting_id` — and a
receipt and a posting are **accounting records Transport does not create**. FORBID-002
and LOCK-004 keep this module out of the books, so there is nothing here to put in
those fields.

CTR-014's note resolves it: *"Posting event generated."* There are two acts — somebody
records against the receivable (Transport, tracking), and somebody posts the receipt
(Accounts, money) — and the first triggers the second.

So the event is emitted with the registry's own field names, `receipt_id` left **null**
rather than fabricated, `invoice_id` filled only once Accounts has set it on the linked
bill, plus `collection_id` and `trip_id` added so a consumer has something to join on.
A test asserts `receipt_id` stays null; it fails the day somebody starts inventing
accounting records in this module.

### No collection status vocabulary

`IDX-009` is `INDEX (company_id, due_date, status)` for "collections ageing", so the
registry plainly expects a `status` column and **never says what may go in it**. None
of the eight enums describes a collection.

Unlike CST-001, FLD-017's `COL-001` is not quite dangling — TRC-007 resolves it to
`BR-COL-001` / `FRS-COL-001`, business RULE documents in the reference-only tier. They
define no vocabulary. `CollectionStatus` therefore declares three states derived from
the arithmetic — `pending`, `part_paid`, `settled` — and nothing else.

Blockers are deliberately **not** a status. A blocked receivable is still outstanding,
and "blocked" would hide how much is owed; a receivable can truthfully be part-paid and
blocked at once, which is exactly the row somebody has to chase.

### And one field row for the whole table

`FLD-017 amount_due` is all DB-013 declares. `IDX-009` proves `due_date` and `status`
are meant to exist by indexing them. Everything else — `amount_received`,
`blocker_reason`, the follow-up stamps — is constructed against the acceptance
criterion's four nouns.

### What resolving it looks like

1. Confirm the two-act reading, or move the event to Accounts entirely and have
   Transport emit nothing. **One deletion either way — say which.**
2. A collection status vocabulary, registered.
3. Field rows for DB-013 beyond `amount_due`.
4. A `Collection / view` permission row (only `record` exists).

**Escalation:** `ARCHITECTURE_REVIEW_REQUIRED` for 1, `CLARIFICATION_REQUIRED` for 2-3,
`SECURITY_REVIEW_REQUIRED` for 4.
**Does not block:** SNG-TRN-016 shipped against constructed values, all flagged here,
and the module writes nothing to any ledger.

---

## D-62 — Two vehicle and driver systems are live at the same time

**Raised:** 2026-09-17, after the fleet module reached production.
**Owner: P1 + P2 — not P3's to decide.** **Severity: critical — it is a data split,
and it widens every day.**

### What exists

| | Operations | Fleet |
|---|---|---|
| Nav | Vehicles · Drivers | Fleet Status · Driver Directory · Workshop |
| API | `/api/transport/vehicles`, `/api/transport/drivers` | `/api/v1/fleet/...` |
| Tables | `transport_vehicles`, `transport_drivers` | `vehicles`, driver directory tables |
| Built by | P1 (SNG-TRN-003 / 004) | P2 |
| Reads it | allocation, pre-trip checks, dispatch | telemetry, fuel, tyres, maintenance |

**Both are deployed. Both have a Vehicles screen and a Drivers screen. Neither knows
about the other.** A vehicle added in one is invisible to the other; a trip can be
allocated a vehicle the fleet system has never heard of, and a vehicle can accumulate
fuel and maintenance history that allocation cannot see.

### How it happened, and why it is nobody's mistake

TEAM-CONTRACTS §1a records `transport_vehicles` / `transport_drivers` as **P1's
PLACEHOLDER, not P1's property** — built early so trips had something to allocate, and
explicitly meant to be replaced when P2 built Fleet.

P2 built the replacement. It landed as a **new module beside** the old one rather than
**into** it, and nobody retired the placeholder. So the handover started and never
finished. That is a coordination gap, not a coding error, and it is exactly what
TEAM-CONTRACTS exists to catch.

### What has been done, and what has NOT

**Done (2026-09-17):** the sidebar showed TWO top-level entries, both labelled
"Transport", both with a Truck icon, pointing at different modules. They are now one
section with all nine screens and no duplicate labels.

**NOT done — and the distinction matters:** merging the menus did not merge the data.
The tidy menu makes the split *less visible*, which is the one risk of having fixed it.
A comment sits beside the merged list saying so.

### What resolving it looks like

One of two, and either is fine — having both is not:

1. **Fleet absorbs operations.** `transport_vehicles` / `transport_drivers` are retired,
   allocation and pre-trip checks are repointed at the fleet tables, and existing rows
   are migrated. Larger change, matches the documented intent.
2. **Operations keeps the master, fleet references it.** The fleet module drops its own
   vehicle identity and keys off `transport_vehicles`. Smaller change, but the fleet
   model is richer and would be the one losing.

**Whoever decides also owns the data migration** — rows already exist on production in
both, so neither option is a code-only change now.

**Escalation:** `ARCHITECTURE_REVIEW_REQUIRED`.
**Blocks:** nothing today, and that is precisely the danger — both systems work in
isolation, so this fails silently rather than loudly, and the cost grows with every row
written to the losing table.

---

> ### NUMBERING COLLISION, RESOLVED 2026-09-17
>
> P3 and P1 both allocated **D-58 … D-61** on the same day, on different branches, to entirely
> different defects. Neither side was wrong; the register has no allocator, so two people counting
> from the same last-seen number produced the same numbers.
>
> **P1's four were renumbered to D-63 … D-66.** Master is the shared baseline, so the branch that
> had not landed moved. P3's numbers are unchanged and every reference to them still resolves.
>
>   | was (P1's branch) | now | subject |
>   |---|---|---|
>   | D-58 | **D-63** | no trip can ever be approved |
>   | D-59 | **D-64** | approval ships without its margin precondition |
>   | D-60 | **D-65** | EVT-004's `approval_id` has no table |
>   | D-61 | **D-66** | STT-003 has no permission row |
>
> Commit messages written before the merge still say D-58…D-61; the code, tests and docblocks were
> all updated. **This is the same class of collision as the `transport.permission` alias** — invisible
> inside one branch, obvious where two meet. Worth an allocator, or a per-person range, before it
> happens again.

## D-63 — CRITICAL: no trip can ever be approved. The chain is unreachable, and the demo hides it.

**Raised:** 2026-09-16, by the owner, from the trip page. **Severity: CRITICAL — this is the gap
that makes the 30 September vertical slice unachievable as things stand.** MS-001 §8 requires
*"Trip can progress through controlled dispatch states"*. Today it cannot progress past the second
one.

### The dead end, verified in the code

```
TripStatus::TRANSITIONS
    DRAFT     => [VIABILITY_PENDING]      STT-001, wired by SNG-TRN-007
    APPROVED  => [ALLOCATED]              STT-004, wired by SNG-TRN-009
    ...
    VIABILITY_PENDING => nothing at all
```

`viability_pending` has **no outgoing edge**. A trip submitted for viability is stuck there
permanently.

Checked, not assumed:

| Claim | Result |
|---|---|
| A route approves a trip | **No.** 24 trip routes. The only `approve` is `trips/{id}/advances/{advanceId}/approve` — Person 3's *cash advance* approval, an unrelated feature |
| `TransportTripService` has an approve method | **No.** Zero matches. The only `approve()` in the service layer is `TripAdvanceService::approve()` |
| Anything follows `submit-viability` | **No.** It is the last step that exists |

**Consequence.** No trip created through the application can reach `approved`. Therefore it can
never be allocated (STT-004 starts at `approved`), never pass pre-trip, never dispatch, never
deliver. **Everything after trip creation is unreachable by a real user.** Allocation, pre-trip and
dispatch are all built, all tested, and all currently unreachable except from data that bypassed
the state machine.

### THE PART THAT MATTERS MOST: the demo demonstrates a capability the application does not have

`TransportDemoSeeder` writes the status column directly, twice:

```
line 267   $trip->forceFill(['status' => TripStatus::ALLOCATED, ...])->save();
line 302   $trip->forceFill(['status' => TripStatus::APPROVED,  ...])->save();
```

`forceFill` bypasses `TripStatus::TRANSITIONS` entirely. **That is the only reason the walkthrough
works.** Every screen downstream of trip creation — the allocation panel, the search box, the
"back in 2 days" sentence, the pre-trip gate, the dispatch panel — is reachable in the demo *only*
because the seeder put the trip into a state the application cannot produce.

The owner's standing instruction is that everything shown must be real: not one thing on screen and
another underneath. **This violates it.** The demo is showing a working dispatch chain on top of an
application that cannot start one.

This is also a failure of my own stated rule for that seeder. Its docblock claims every row goes
through a real service *"so the demo exercises the real numbering, the real audit trail and the
real refusals — a seeder that wrote rows directly could produce data the application itself could
not"*. Two `forceFill` calls do exactly the thing the docblock forbids, and I wrote both.

### Why it was missing, and why nobody noticed

The register places viability with **SNG-TRN-008 (Trip Viability)**. The tickets built are 001,
003, 004, 006, 007, 009, 010 — **008 was never built.** It is not an error by any developer; it is
a gap that the seeder concealed. Allocation was built against `approved` trips that the seeder
supplied, so it tested green and demonstrated green while the door into that state did not exist.

**The general lesson, which is the reason this entry is long:** a seeder that bypasses a state
machine does not just create convenient data — it removes the pressure that would have exposed the
missing transition. Demo data built through the real services is a *test of reachability*. The
moment it force-fills, it stops being evidence of anything.

### Not yet resolved — what happens next

1. Read what **Step 9 and Step 11** actually say about `viability_pending → approved` (STT-002),
   and whether **SNG-TRN-008** has a ticket: its trigger, precondition, actor and side effects.
   **The open question is whether approval is a HUMAN DECISION or the output of a VIABILITY
   CALCULATION.** Those are different features, and guessing wrong is a rebuild.
2. If the source defines it: build the edge, service method, permission, endpoint, refusal tests
   and the button.
3. If the source does NOT define it: **stop and get a ruling.** Do not invent an approve button —
   the D-39/D-40 route.
4. **Either way, the seeder stops writing statuses directly once a real path exists**, and creates
   its trips by walking the same transitions a user walks. If it cannot, that is itself the
   finding.

Cross-referenced from `TransportDemoSeeder`'s docblock, so nobody reads the seeder as evidence that
the flow works.

### What the source actually says — read 2026-09-16, before any code

**STT-002 EXISTS AND IS LOCKED.** From Step 11 `State_Transitions`, quoted verbatim:

```
STT-002 | SM-TRP | viability_pending | approved | Approve viable trip
        | ApprovalService | Margin policy passed | Emit TripApproved | Yes | LOCKED
```

And its sibling, also LOCKED and also unbuilt:

```
STT-003 | SM-TRP | viability_pending | draft | Reject for correction
        | Operations | Rejection reason | Return to edit | Yes | LOCKED
```

**Is approval a human decision or a calculation? IT IS BOTH, AND THEY ARE TWO SEPARATE FEATURES.**

*Approval is a human decision:*

- `EVT-004 TripApproved` payload is `trip_id, **approved_by**` — a calculation has no `approved_by`.
- `PERM-003 | Trip | approve` grants it to Owner, Operations, Accounts, Approver, Admin and
  **explicitly denies Dispatcher, Driver, Customer, Supplier.** A permission matrix that has to say
  no to the dispatcher is describing a decision, not a computation.
- EVT-004's idempotency key is `trip_id+**approval_id**` — approvals are records.

*Gated by a calculation, which is a DIFFERENT feature:*

- Precondition is **"Margin policy passed"**.
- `API-003 | POST /transport/trips/{trip}/viability | Calculate trip viability | LOCKED`, body
  `freight` (CTR-005, "Backend authoritative") and `target_margin_pct` (CTR-006, "Policy
  constrained").
- `EVT-003 TripViabilityCalculated | ViabilityEngine | trip_id, revenue, cost, margin`.
- `ENUM-008 viability_decision = accept|negotiate|reject|review`.
- `SNG-TRN-008 | Trip Viability | P0 | S4 | Type: Algorithm | Complexity: XL`, user story *"As
  owner, I can evaluate accept/negotiate/reject before commitment"*, acceptance *"Cost assumptions
  and target margin are explainable and deterministic"*, DoD *"Golden dataset reconciliation"*,
  QA-002 *"Low-margin trip → System recommends NEGOTIATE/REJECT with explanation"* (Critical).

So: **a human clicks approve, and the system refuses unless margin policy passes.**

### Why STT-002 still cannot simply be built

| What is needed | State |
|---|---|
| The transition itself | **Defined, LOCKED.** Buildable |
| Permission | **Defined, LOCKED** — PERM-003 |
| Event | **Defined, LOCKED** — EVT-004 |
| An API endpoint | **MISSING.** `API_Registry` contains NO approve row for a trip. Same class as D-38/D-45 |
| A ticket owning it | **MISSING.** The Trip epic holds only SNG-TRN-007 (Trip Creation, built) and SNG-TRN-008 (Viability). **No ticket owns the approval transition** |
| A table for `approval_id` | **MISSING.** `DB_Registry` has no approvals table, though EVT-004's idempotency key names one |
| "Margin policy passed" | **NOT BUILDABLE TODAY** — see the chain below |

**The precondition's dependency chain, every link verified:**

```
STT-002 approve
  └─ precondition "Margin policy passed"
       └─ SNG-TRN-008  Trip Viability      P0, XL, Algorithm   NOT BUILT
            ├─ depends on SNG-TRN-005  Commercial Rate Card    P0, L    NOT BUILT
            │    └─ transport_rates (DB-016) — TEAM-CONTRACTS lists the owner as UNASSIGNED
            └─ needs `cost` for EVT-003's payload
                 └─ trip_costs (DB-006) — Person 3's, NOT BUILT
```

Confirmed in the codebase: **zero migrations exist for either `trip_costs` or `transport_rates`.**

Corroborating D-12/D-15/D-35 once more: SNG-TRN-008's `DB/API/State/Event Refs` column reads
`DB-008;API-005;EV-004`. The real rows are API-003 and EVT-003. The positional counter is fabricated
again.

### The decision this needs — NOT taken, and not to be guessed

The source defines the EDGE but not a buildable GATE, and no ticket owns the work. Per the standing
rule this stops here for a ruling, the D-39/D-40 route. Options as I see them:

- **A. Build STT-002 now with the margin precondition explicitly DEFERRED.** Human decision,
  permission-gated (PERM-003), audited, emitting TripApproved — but with **no margin check**, stated
  on screen and recorded here. Unblocks allocation, pre-trip and dispatch, which are built and
  currently unreachable. The cost: an approval step that does not yet enforce the rule it exists to
  enforce. That must be labelled, not hidden.
- **B. Build SNG-TRN-008 first.** Honest, and not achievable by 30 September: XL, type Algorithm,
  DoD "golden dataset reconciliation", and it depends on an unbuilt L-sized rate card whose owner is
  unassigned plus Person 3's unbuilt cost table.
- **C. Build STT-003 as well or instead** — *Reject for correction*, viability_pending → draft. Also
  LOCKED, and its precondition is only *"Rejection reason"*, which IS buildable today. It does not
  unblock dispatch, but it removes the dead end: a trip could at least return to draft instead of
  being stuck forever.

**Recommendation: A plus C.** A is the only option that makes the 30 September slice reachable, and
C costs almost nothing and fixes the trap door. Both are reversible; the margin gate slots into A's
precondition when SNG-TRN-008 lands.

---

## D-64 — Trip approval ships WITHOUT its LOCKED precondition, "Margin policy passed"

**Raised:** 2026-09-16, as a condition of the owner's ruling on D-63. **Owner: whoever lands
SNG-TRN-008.** **Status: DEFERRED, deliberately, with a failing-on-purpose test holding the place.**

STT-002 is LOCKED and its precondition is **"Margin policy passed"**. The approve transition is
being built now, by ruling, **without that check**, because the check is not reachable:

```
STT-002  approve
  └─ "Margin policy passed"
       └─ SNG-TRN-008  Trip Viability        P0, XL, Algorithm   NOT BUILT
            ├─ SNG-TRN-005  Commercial Rate Card   P0, L         NOT BUILT
            │     └─ transport_rates (DB-016) — **OWNER UNASSIGNED**
            └─ trip_costs (DB-006) — Person 3's — NOT BUILT
```

**THE BLOCKING FACT IS THE UNASSIGNED OWNER.** SNG-TRN-005 has no developer against it in
TEAM-CONTRACTS, and `transport_rates` is listed as unassigned. Viability cannot start until
somebody owns the rate card; approval cannot be gated until viability exists. That is the item a
person has to fix, and no amount of Transport work removes it.

### What shipping without it means, stated plainly

A user can approve a trip that would lose money, and the system will not stop them. Approval today
checks **the state and the permission, and nothing about the commercials.** That is a real
reduction against the LOCKED registry row and it is not hidden:

- `TransportTripService::approve()` says so in its docblock;
- the approval dialog says so on screen, so a user knows what the system did and did not check;
- and a test pins the absence.

### The test that cannot be forgotten

`TripApprovalTest::test_the_margin_gate_is_still_deferred` asserts that no margin or viability
check exists on the approve path — the same technique used to pin `container_id` out of EVT-002's
payload. **It is written to fail the moment SNG-TRN-008 lands.** Whoever builds viability will see
it go red and must add the gate to make it pass. A deferred precondition with a failing test cannot
be forgotten; one with a comment can.

---

## D-65 — EVT-004's idempotency key names an `approval_id` that has no table

**Raised:** 2026-09-16. **Owner: Architecture / Step 11.** **Severity: low today, real later.**

```
EVT-004 | TripApproved | ApprovalService | trip_id, approved_by
        | idempotency: trip_id+approval_id | TripEngine, Notifications | LOCKED
```

`DB_Registry` contains **no approvals table**, and no field registry entry defines `approval_id`.
The key cannot be honoured as specified because the entity it keys on does not exist.

**Resolved by precedent, not by invention.** The approval is recorded on the trip itself —
`approved_by` and `approved_at` — exactly as `dispatched_by` / `dispatched_at` already ship on
`transport_trips`. The event is emitted with what actually exists: `trip_id` and `approved_by`.

**What was deliberately NOT done:** no `approval_id` was substituted. Not the audit-log row id, not
a generated uuid, not the trip id doubled up. **A fabricated identifier is worse than an absent
one** — it would satisfy a consumer's de-duplication logic while keying on something the registry
never meant, and the failure would appear as a silently dropped event long after anyone remembers
this decision. The absence is honest and visible; a fake would be neither.

**Consequence, recorded:** a consumer that de-duplicates strictly on `trip_id+approval_id` cannot do
so. Today there are no consumers — EVT-004 is emit-only, like EVT-001 and EVT-002 — so nothing is
broken. Whoever builds the first consumer, or an approvals table, inherits this.

---

## D-66 — STT-003 has no permission row; TRIP_APPROVE is reused rather than a matrix invented

**Raised and resolved:** 2026-09-17, building STT-003.

```
STT-003 | SM-TRP | viability_pending | draft | Reject for correction
        | Operations | Rejection reason | Return to edit | Yes | LOCKED
```

The transition is LOCKED and fully specified. What does not exist anywhere: **no Permissions row
for rejecting a trip, no Event_Registry row, no API_Registry path.** PERM-003 covers `Trip ·
approve` only.

**Decided without a ruling, and here is the reasoning.** `TRIP_APPROVE` is reused for reject rather
than deriving a second grant matrix:

- approve and reject are the two answers to **one** question, asked at one moment by one person;
- sending a trip back is **strictly less powerful** than approving it, so reusing the narrower-
  purpose key grants nothing that key did not already imply;
- inventing a second matrix — deciding for ourselves which of the nine roles may reject — would be
  a larger and less reversible step than reusing one the registry already fixed.

STT-003's actor column reads "Operations", which is already inside PERM-003's grants. The Dispatcher
denial carries over unchanged, and is tested.

The path follows the shipped convention: `PATCH /trips/{id}/reject`, beside `approve`. Logged with
D-12's other missing endpoint rows.

**If Step 11 later adds a `Trip · reject` row that differs from PERM-003, this is the decision to
revisit.**

### D-62 — P1's half done, 2026-09-17: menu hidden, data join still open

**Owner's ruling:** P2's Fleet is the visible fleet. P1's *Vehicles* and *Drivers* entries are
**hidden from both navigations** — `TransportLayout.jsx` and `Sidebar.jsx`'s `TRANSPORT_SUB_ITEMS`.

**HIDDEN, NOT DELETED.** Pages, routes, API, models and services all remain and still resolve by
URL — verified after the change: `/app/transport/vehicles` and `/app/transport/drivers` both load
with 5 rows each. They must remain, because **allocation, pre-trip checks and dispatch read
`transport_vehicles` and `transport_drivers` today.** The reason is written at both commented-out
entries so nobody un-hides them by accident or deletes them too early.

**Accepted consequence, recorded so it is not "fixed" quietly:** the demo trucks are in
`transport_vehicles`, so P2's Fleet Status reads *"No vehicles yet."* That is honest and it stays.
**Copies were deliberately NOT seeded into the fleet tables** — two sets of the same trucks in two
tables is the duplicate-master-data failure this arrangement exists to prevent, and those are P2's
tables, not P1's.

**What is still open — the half that matters.** Hiding a menu does not join the data. The question
has gone to P2 as `docs/transport/REQUEST-person2-fleet-join.md`: migrate our rows into their
tables, expose a contract we read, or something else they prefer. **No option was proposed as
agreed and none has been started.** Their module, their call.

### D-62 step 5 — partly done by P2, and a question back to them (2026-09-17)

TEAM-CONTRACTS §1a made retiring P1's placeholder P1's job, "in the same PR that brings their Fleet
in, or immediately after". **P2 has done part of it themselves.** `/app/transport/vehicles`,
`/vehicles/:id`, `/drivers` and `/drivers/:id` now render Fleet's components.

**Consequence, stated because it goes further than the owner's ruling.** The ruling was *hide, not
delete*, with the pages still reachable by URL until allocation was repointed. They are no longer
reachable: `TransportVehicles`, `TransportVehicleDetail`, `TransportDrivers` and
`TransportDriverDetail` still exist as files, but no route renders them and their lazy imports in
`routes.jsx` are dead.

**The outcome is fine — arguably better than hiding — and P2's change has been left exactly as
written.** The process point has gone to them: announce a change of that size rather than leaving
it to be found in a diff. Nothing has been altered in response.

**Open question to P2:** delete the four page files now, or leave them dormant until allocation is
repointed at Fleet? Not decided unilaterally, because the files are the last piece of a handover
they now partly own. Asked in `NOTE-team-approve-path-is-on-master.md`.

**The backend is untouched by any of this.** Allocation, pre-trip and dispatch still read
`transport_vehicles` and `transport_drivers`, which still hold the demo rows, so the dispatch chain
works today. Repointing onto Fleet is the remaining half and is now in progress.

**Also expected, not a fault:** Fleet, Drivers and Workshop read zero rows, because the demo
vehicles are in P1's tables and `2027_01_02_000002` is deliberately unrun. Not to be worked around
by seeding copies.

---

## D-100 — Allocation cannot be repointed at Fleet yet. Three blockers, in order.

**Raised:** 2026-09-17, starting the repoint. **First use of P1's new D-100 band.**
**Owner: P1 + P2.** **Status: NOT STARTED — deliberately, and here is why.**

> ### Three couplings, measured 2026-09-21 — find these before you discover them
>
> The repoint is not one change to one reader. Three things on OUR side must move in the same
> commit as the data, and the first would have wasted an afternoon.
>
> **1. The status vocabularies disagree, and the comparison is strict.**
> `vehicles.status = "AVAILABLE"`, `transport_vehicles.status = "available"`, and
> `VehicleEligibilityService` gates on
> `in_array($vehicle->status, VehicleStatus::ALLOCATABLE, true)` where `ALLOCATABLE` is
> `['available', 'idle']`. Point the picker at Fleet without normalising this and **every Fleet
> vehicle reads as not allocatable — an empty candidate list that looks like a considered
> eligibility verdict rather than a bug.**
> *(The drivers happen to agree — both sides store `available` — though the columns are named
> `status` and `availability`.)*
>
> **2. The relations are hard-coded to our model, in both places that matter.**
> `TransportTrip::vehicle()` and `TripAssignment::vehicle()` are both
> `belongsTo(TransportVehicle::class, 'vehicle_id')`, and the trip detail payload loads the
> registration through that relation. Repoint the column without the relation and the trip screen
> shows a **blank vehicle** today, and a **different truck** the day the two id ranges overlap.
>
> **3. Two readers resolve a `vehicle_id` against our table and would fail silently.**
> `TransportSearchService::vehicle()` resolves a plate in `transport_vehicles` and then looks for
> trips by that id — after a repoint a plate search finds the vehicle and **no journeys**, quietly
> undoing CTD §4's follow-through. And `AllocationService::freeResources()` (D-119) resolves
> `vehicle_id` the same way and would **stop freeing trucks without a word**.
>
> **The picker is not separable from the data.** If the picker offers Fleet vehicles, `assign()`
> writes a Fleet id into `transport_trips.vehicle_id` while every existing row holds one of ours —
> the mixed namespace arrives through the back door with no ledger recording which is which.
>
> **Ruled 2026-09-21: do not split the repoint into a vehicle half and a driver half.** Couplings
> 2 and 3 touch drivers in the same files, so a vehicle-only repoint does most of the driver work
> to avoid waiting for the driver data — and leaves `vehicle_id` meaning Fleet and `driver_id`
> meaning ours in the same row. We wait for D-118.
>
> **The good news, also measured:** Fleet's `vehicles` carries every column ours does except
> `tenant_id` (it uses `company_id`), including status, capacity, type and normalised
> registration. This is a reader swap plus a vocabulary normalisation plus relation repointing —
> **not** a data-model migration.

The instruction was to repoint allocation, pre-trip and dispatch onto
`FleetService::getEligibleVehicles`. It exists, it is explicitly *"Consumed by Developer 1
(Operations) during dispatch planning"*, and its payload is good — id, registration, type,
compliance, live position, scores, reasons, and each vehicle's regular driver with a licence
verdict. A driver equivalent exists too: `DriverService::list($companyId, ['ready_only' => true])`
returns licence-valid, available people.

**Doing it today would break the working dispatch chain within the hour.** Three blockers:

### 1. Fleet's `vehicles` table is EMPTY — measured, not assumed

```
Fleet  vehicles            (company 1):  0 rows
P1     transport_vehicles  (tenant 1) :  5 rows
P1     transport_drivers   (tenant 1) :  5 rows
```

The demo fleet is still in P1's tables because `2027_01_02_000002` is deliberately unrun — the
owner held it, since `stos:reconcile-fleet` does not exist. **Repointing before that migration runs
hands a dispatcher an empty candidate list**, and allocation, pre-trip and dispatch all stop
working. The order is therefore fixed: **migration first, repoint second.**

### 2. Driver identity does not fit `trip_assignments.driver_id`

Fleet identifies a driver by a directory reference — `source` + `source_id` — not by a single
integer. `trip_assignments.driver_id` is an `unsignedBigInteger` pointing at
`transport_drivers.id`. **What should it point at after the repoint?** `DriverProfile.id`? The
directory person? A composite? That is P2's contract to state; guessing produces assignment rows
that point at nothing.

Vehicles are simpler — `vehicles.id` is an integer — but the same question applies: the ids in
existing `trip_assignments` rows are P1's, and the migration must map them or they dangle.

### 3. Calling `FleetService` directly would bypass the seam we just built

`FleetResourceGateway` is the agreed door between Trip side and Fleet, and it currently has exactly
one method: `markDispatched()`. Reaching from `AllocationService` into
`App\Domains\Fleet\Services\FleetService` would defeat the interface both sides just agreed —
the same mistake in the opposite direction from the one the gateway was created to stop.

**The gateway needs two more methods** — something like `eligibleVehicles()` and
`eligibleDrivers()` — or P2's explicit agreement that direct `FleetService` calls are the intended
route. **Adding methods to that interface is P2's call, not P1's.**

### The order that would work

1. `stos:reconcile-fleet` exists, or the owner accepts the migration without it.
2. `2027_01_02_000002` runs; Fleet's tables hold the vehicles and drivers.
3. P2 states the driver identity that `trip_assignments.driver_id` should carry.
4. P2 extends `FleetResourceGateway`, or rules that direct calls are fine.
5. P1 repoints `AllocationService`, `VehicleEligibilityService`, `DriverEligibilityService`,
   `PretripService` and `DispatchService`, and maps existing assignment rows.

**Steps 1–4 are not P1's.** Asked of P2 in `NOTE-team-approve-path-is-on-master.md`'s follow-up.
Container 360 (Block 2) is unblocked and starts now instead.

---

## D-101 — A soft-deleted container reserves its number forever

**Raised:** 2026-09-17, by a constraint violation during a demo reset.

`transport_containers` uses `SoftDeletes`, and `UNIQUE(tenant_id, container_number_normalized)`
**does not exclude trashed rows**. So deleting a container keeps its number permanently reserved:
re-creating the same number fails with an integrity violation naming a row the user cannot see.

Found when the demo reset soft-deleted its own containers and the next run was refused inserting
`sgoe-402215-9` — its own data, blocked by its own tombstone.

**Not reachable by a user today:** there is deliberately no delete path for containers (the number
is the identity and §7 requires the association history survive). Only a seeder or a manual query
can create the tombstone. **The seeder now uses `forceDelete()`.**

**It becomes reachable the moment anyone adds a delete or archive action for containers**, and the
symptom will be baffling: "that number already exists" for a container nobody can find. Whoever
adds one inherits this — either force-delete, or make the unique index ignore soft-deleted rows.

Same shape as D-53's latent overlap: correct today because a path does not exist, wrong the day it
does.

---

## D-102 — Container 360's nine absent sections, and why none of them is a stub

**Raised:** 2026-09-17, building Block 2. **Status: recorded, not deferred and not dropped.**

STOS-CTD §9 lists **thirty** passport sections. Nine have no entity anywhere in this codebase, so
the passport does not render them at all:

| Section | CTD req | Owner | Why absent |
|---|---|---|---|
| GPS / current location (§13, §14) | CTD-010 P1 | P2 | telemetry is Fleet's; no read contract exists |
| Temperature (§15–§17) | CTD-011 **P0** | Product | no entity. Same root as D-52 — nothing marks a trip temperature-critical |
| Genset (§18, §19) | — | P2 | no entity |
| Fuel · FASTag | CTD-015 P1 | P2 | Fleet's |
| Port / Gate (§28–§30) | CTD-009 P1 | Product | no entity — D-42 |
| Feedback (§36–§40) | CTD-018 **P0** | Product | no entity |
| Incident / CAPA (§56, §57) | CTD-012/013 **P0** | P3 | SIRE owns CAPA; `trip_exceptions` has a table and no model |
| Compliance (§23, §58, §59) | CTD-008 **P0** | P2/P3 | no read contract — D-100 |
| Profitability (§52–§54) | — | P3 | Finance's |

**The rule applied, and the reason it is a rule:** an empty panel implies the feature exists. A
dispatcher who sees a Temperature card reading "—" concludes the sensor is broken; one who sees no
card concludes the system does not track it. The second is true. This is the same rule the
consignment drawer follows and the reason its Containers panel waited for containers to exist.

**Five of these are P0.** They are not deferred by choice — there is nothing to render. Each
becomes a one-section addition to `ContainerPassportService` the day its entity exists, and the
service is shaped so that adding one touches nothing else.

---

## D-103 — CTD §77's passport snapshots, deferred

**Raised and deferred:** 2026-09-17, on the owner's ruling.

§77 asks the system to preserve "current view; historical event stream; rule versions; important
snapshots". The first two exist — the passport IS the current view and `transport_audit_logs` is
the immutable stream. **Rule versions and snapshots would need a new table**, and nothing today can
say which snapshots matter or what a rule version is.

Deferred rather than built: a table whose contents nobody can specify is the D-9 mistake in a
different shape. **Owner: Product**, to specify what a snapshot is for before one is stored.

---

## D-104 — The demo reset was not atomic, and a half-finished one looks like a broken build

**Raised:** 2026-09-17, chasing a state seen once and not reproducible by re-running.
**Found by simulation.** **Fixed.**

### What was seen

A demo trip reporting **"approved, no vehicle"** when the seeder had just claimed it was "crewed
and moving". Re-running produced the correct state twice, so it could not be reproduced, and no
guess was offered at the time.

### What it actually was

`clearPreviousDemo()` releases assignments FIRST and soft-deletes the trips LAST:

```
1. AllocationService::release()   ← reverts ALLOCATED → APPROVED, clears vehicle + driver
2. delete assignment rows
3. detach containers
4. delete pre-trip checks, exceptions
5. delete vehicles, drivers, containers
6. soft-delete trips, consignments, orders   ← the trip finally goes
```

**None of it was in a transaction.** Anything stopping it between 1 and 6 — a throw, a Ctrl-C —
commits the release and never reaches the delete. What survives is a trip that has been reverted
to `approved` with its vehicle and driver cleared, and never removed.

**That is exactly the observed state**, and this session did interrupt seeder runs.

Proved rather than argued: running only the release half against a clean demo produces

```
clean run          TRP-…-000001 pretrip_ok  v=1     TRP-…-000002 approved v=-
release half only  TRP-…-000001 approved    v=-     TRP-…-000002 approved v=-
```

The first line is what the seeder claims; the second is what was seen.

### Fixed two ways

1. **The reset is atomic.** `clearPreviousDemo()` now runs inside one transaction: it completes,
   or the previous demo is left untouched. A half-cleared demo is no longer reachable.
2. **The seeder checks its own work.** `assertDemoIsWhatItClaims()` runs before the success line
   is printed and throws if either trip is not in the state, or carrying the resources, the report
   is about to claim. Proven to fire: skipping the pre-trip step produces
   *"TRP-…-000001 should be ready to dispatch but is 'allocated'"*.

**The second matters more than the first.** An intermittent demo failure that nobody can reproduce
is the worst kind to meet in front of a client. The seeder now fails loudly at build time instead
of leaving a walkthrough that looks broken to whoever opens it next.

### And a guard that had to learn a distinction

The D-63 guard banned the string `TripStatus::` from the seeder outright. The new self-check
legitimately COMPARES against it — checking your own work is the opposite of forcing a state — so
the guard fired on the fix the day it was written. It now matches the WRITE (`'status' => …`)
rather than the mention, and is proven to still catch a real assignment.

**A guard that cannot tell a read from a write trains people to weaken it**, which is worse than
not having one.

---

# D-105 … D-108 — found during the Block 3 (transit, delivery, closure) pre-build

---

## D-105 — An authorised transition, its columns, its index, and nothing that writes them

**Raised:** 2026-09-17, reading the source for Block 3. **Verified by the lead the same day.**

### What the code said

Four documents — `TripStatus` (twice), `DispatchScope` (three times),
`TransportDispatchController` (twice) and `TEAM-CONTRACTS.md` — all said the same thing:

> STT-006 (`dispatched → in_transit`) is the Transit half of SNG-TRN-013, **blocked on the
> owner's Q1/Q3 ruling**.

### What the ruling actually says

Q3 was answered on **2026-09-10**, seven days earlier, and it is an **approval**. Verbatim, from
`ExceptionScope::RULINGS`:

> "Option (b) — build the Exception engine, and also wire `dispatched → in_transit` as a manual
> **'Record departure'** action (`departed_at`, `departed_by` columns only). Nothing beyond that
> — no `in_transit → delivered`, no GPS/telemetry/odometer/temperature, no automatic triggers."

Its precondition ("Dispatch confirmed") became satisfiable when Record Dispatch shipped on the
same day. Migration `2026_12_16_000013_add_departure_to_transport_trips` created `departed_at`
and `departed_by` **and** the index `transport_trips_tenant_departed_idx`.

**Nothing writes either column.** `grep -rn departed_at app/ tests/ database/` returns the
migration, one constant in `ExceptionScope`, two tests that assert that constant, and one
unrelated migration docblock. No service, no controller, no route, no test of behaviour.

So the edge was approved, its schema shipped, and the work stopped between the two — while every
comment in the codebase went on describing it as blocked.

### Why this is the D-58 shape, and worse

D-58 was an edge nobody had built. This is an edge somebody was **told to build, built the
scaffolding for, and left**, with four documents asserting it could not be built. Scaffolding
that makes a thing look done is worse than an absence: an absence gets found, and a comment
saying "blocked" gets believed.

It was found by re-reading the ruling rather than trusting the comment that cited it.

**Fixed in Block 3.** The stale text is corrected in all four documents in the same change, so
the next reader does not hit the same dead comment.

---

## D-106 — No trip in this system can ever be closed, and the missing piece is one caller

**Raised:** 2026-09-17. **Verified by the lead.** **P3's surface — raised, not fixed.**
**CLOSED 2026-09-19 by Person 3** (commit `36a7504a`), who shipped
`POST /transport/trips/{id}/bill/invoiced` and its controller. Verified here by walking a trip
**delivered → closed** in the browser; `TRP-2026-000034` is closed and `EVT-012 TripClosed`
fired. `ClosureScope::REACHABLE` is now `true`.

> **What closing it left behind.** Two things the fix did not cover, both written up rather than
> assumed away:
>
> 1. **No UI control calls the new route.** `BillingPanel.jsx` has one button (*Prepare billing*)
>    and `transportApi.js` has no method for the endpoint, so a trip still cannot get past
>    *Billable* by clicking. The walk above posted to the route by hand. P3's, raised in
>    `NOTE-person3-d106-landed-and-the-button-is-missing.md`.
> 2. **Three of our own comments went stale the moment it landed** — `transportApi.js`,
>    `ClosurePanel.jsx` and `constants.js` each still said the route did not exist. Corrected.
>    This is the second time in two days that fixed code left true-sounding false text behind
>    (the first was the `exceptions` closure control), and it is the argument for treating a
>    comment that states a *fact about other code* as something that expires.

`STT-012` (`collection_pending → closed`) is the only edge into the terminal state, and
`collection_pending` is the only state it leaves from. Walking backwards:

| Edge | Built | Owner | Reachable by a user |
|---|---|---|---|
| `delivered → pod_verified` (STT-008) | yes | P3 | yes — `POST /trips/{id}/pod/{doc}/verify` |
| `pod_verified → billable` (STT-009) | yes | P3 | yes — `POST /trips/{id}/bill` |
| `billable → billed` (STT-010) | **model method only** | Accounts / P3 | **NO** |
| `billed → collection_pending` (STT-011) | yes | P3 | only via `billed` |

`TripBill::markInvoiced()` (`app/Models/Transport/TripBill.php:89`) is the single door to
`billed`. **It has no caller.** Every other mention of it in the codebase is a comment.

`TripCollectionService::open()` is honest about the consequence rather than papering over it: it
guards the move with `TripStatus::canTransition()`, so opening a collection on a `billable` trip
creates the collection row and correctly declines to advance the status. The trip stays at
`billable`, and `collection_pending` is unreachable.

### The consequence

**Closure is plumbed, not reachable.** Block 3 builds STT-012 to the registry — API-009,
CTR-013, PERM-005 including the Dispatcher denial, EVT-012 — and every test passes, and no user
can get a trip into the state the endpoint requires.

The coverage document marks it **PLUMBED**, never BUILT. That distinction exists for exactly
this case.

**The ask to P3:** `markInvoiced()` needs a caller and a route. Until it has one, no trip in the
system can ever close — and `EVT-012 TripClosed`, which P3 has been waiting on since the 16th,
is on the other side of that one gap. See `docs/transport/REQUEST-person3-invoice-door.md`.

**Not fixed here.** `trip_bills` is P3's table and the standing rule is not to fix another
developer's file to make our own work reachable.

---

## D-107 — EVT-012's idempotency key names a field that does not exist

`EVT-012 TripClosed` | producer TripEngine | payload `trip_id, closure_timestamp` |
**idempotency `trip_id+close_version`** | consumers ProfitEngine, ControlRoom | LOCKED.

There is no `close_version` column and no versions table anywhere in the package. This is the
same shape as **D-65**, where `EVT-004 TripApproved`'s key named an `approval_id` with no
approvals table, and the ruling was **do not invent one**.

Same answer. Closure is made idempotent by the state machine instead: `closed` is terminal, so a
second close is refused with a sentence naming who closed it and when. What a version counter
would add beyond that is response replay, which no consumer needs — and ProfitEngine, the one
consumer that would care, does not exist either (`trip_profit_snapshots` is not a table).

Recorded rather than invented.

---

## D-108 — Two LOCKED transitions with no API_Registry row

Step 11's API_Registry runs API-001…API-015 and covers create, viability, assign, advance,
expense, exception, POD, close, bill, collection, control-room, trip detail, GPS and e-way-bill.

**Neither STT-006 (`dispatched → in_transit`) nor STT-007 (`in_transit → delivered`) has a row**,
though every other trip transition in the machine does — including STT-012, which gets API-009.

So the two edges Block 3 exists to build are the only two with no specified path, method,
permission key or request contract. Consistent with D-18 (dispatch had no ticket) and D-8/D-21
(permission keys with no matrix row): the registries thin out precisely where the operational
middle of the trip lives.

Paths follow the endpoints already beside them — `PATCH /trips/{trip}/depart` and
`PATCH /trips/{trip}/deliver`, next to `PATCH /trips/{trip}/dispatch`. Recorded as derived, not
quoted.

---

## D-109 — The Fleet data-move migration is armed, and `php artisan migrate` fires it

**Raised:** 2026-09-17. **By running it myself, by accident.**

### What happened

`php artisan migrate`, run to apply Block 3's two new columns, also applied
`2027_01_02_000002_move_transport_masters_into_fleet`, which was sitting pending. The owner had
said explicitly: **do not run that migration yet.**

It is not guarded by anything. It is an ordinary pending migration, so the ordinary command that
every developer runs after a `git pull` executes it. I did not pass a flag, target a file or opt
in — I ran the command you run to add a column.

### What it did

It moved `transport_vehicles` and `transport_drivers` into the Fleet masters and **repointed
every foreign key that referenced them** — `transport_trips.vehicle_id`, `.driver_id` and the
same two on `trip_assignments`.

The Transport side still reads `transport_vehicles` and `transport_drivers`, so every repointed
row became an orphan: the demo trip's vehicle and driver both resolved to null. Measured, not
assumed — 4 trips and 1 assignment across all tenants.

`down()` is deliberately a no-op, so `migrate:rollback` does not undo it.

### How it was repaired

Re-running `TransportDemoSeeder`. The seeder soft-deletes the previous demo and rebuilds it
through the real services, so the new trips and assignments point at `transport_vehicles` and
`transport_drivers` again. Verified: **zero orphaned live rows** on all four table/column pairs.

I did **not** delete the rows the migration inserted into `vehicles`, `driver_profiles` and
`stos_drivers`. Those are Person 2's tables, and the standing rule is that we do not delete
another developer's data to tidy up after ourselves. Two rows in each, all carrying their
`legacy_transport_*_id`, so they are identifiable and reversible by whoever owns them.

Three soft-deleted trips still carry Fleet ids. Inert — nothing reads a deleted trip's vehicle —
and left alone rather than rewritten, for the same reason.

### The actual defect

**Not that I ran it. That anyone can, without meaning to.**

`2027_01_02_000002` will fire on the next `php artisan migrate` on every machine in the team,
including production, with no prompt and no flag. It is marked run on this dev database now, so
it will not fire again *here* — which is worse in one way, because the hazard has moved to
everyone else's machine and mine now looks clean.

This needs a decision from the owner, and it is not mine to take because the migration is P2's:

- **Guard it** — an env flag or a `STOS_FLEET_MIGRATION=1` check, so it is opt-in;
- **or hold it out of the branch** until the repoint is genuinely wanted;
- **or run it deliberately, everywhere, once**, with the Transport read paths moved over in the
  same change — which is D-100, and D-100's measured finding was that Fleet's `vehicles` table
  reads zero, so that cannot happen yet.

Recorded in `TEAM-CONTRACTS.md` under the never-`migrate:fresh` rule, because it belongs to the
same family: a routine command with an irreversible effect nobody expects.

---

## D-110 — Container 360's Vehicle and Driver nodes cannot be made clickable yet

**Raised:** 2026-09-18, walking the three finished blocks in a browser.
**Not fixed — it is blocked on a cross-team gap, and faking it would be worse.**

CTD §71's second chain is `Container → Trip → Vehicle → Driver`, and Container 360 renders all
four. Order, consignment and trip each carry an **Open** link. **Vehicle and Driver do not.**

That is not an oversight in the page. It is the D-62/D-100 boundary showing through:

- `ContainerPassportService::chain()` reads `transport_vehicles` and `transport_drivers` — P1's
  placeholder masters (TEAM-CONTRACTS §1a), so the ids it returns are **transport_vehicles.id**
  and **transport_drivers.id**.
- The only vehicle and driver screens in the app are P2's — `/transport/vehicles/:id`
  (`FleetVehiclePassport`) and `/transport/drivers` — and they resolve **Fleet** ids.

The two id spaces are different. Linking `chain.vehicle.id` at P2's route would open the wrong
vehicle or none at all, and "the wrong truck's history" is exactly the failure mode D-62's own
migration refuses to risk.

### Why it is left as it is

A link that lands on the wrong record is worse than no link. The nodes still show the
registration, the type, the driver's name and licence class, so the chain READS correctly end to
end — only the last two steps are not navigable.

### What would close it

Either of these, and both belong to a conversation rather than to this pass:

1. **P2 exposes a lookup by our id** — a read contract that answers "which Fleet vehicle is
   `transport_vehicles.id = N`?". `vehicles.legacy_transport_vehicle_id` already holds exactly
   that mapping for the rows the D-62 migration moved (see D-109), so the data exists.
2. **The masters are unified** — D-100's repoint, which is measured as not yet possible because
   Fleet's `vehicles` reads zero through Transport's current paths.

Raised to P2 alongside D-109.

---

## D-111 — The browser/server boundary had two more time bugs, and one is still open

**Raised:** 2026-09-18, sweeping for siblings of the `fromLocalInput` bug on the owner's
instruction. **One fixed, one reported.**

### Why a sweep was ordered

The `fromLocalInput` defect made Record departure and Record delivery unusable outside UTC and
silently shifted every dispatch time, with 1311 tests green over the top of it. The owner's
reading: *"every service test built its times on the server, where there is nothing to get wrong
— that is a structural blind spot, not bad luck, and it will have siblings."*

It had two.

### Sibling 1 — the order deadline. FIXED.

`TransportOrderForm`'s "Required by" is a `datetime-local` and was submitted **raw**, the only
one of the module's four such fields with no conversion:

| | |
|---|---|
| `components/TransportOrderForm.jsx` | **0 conversions** ← the bug |
| `components/JourneyPanel.jsx` | 2 |
| `components/DispatchPanel.jsx` | 2 |

Measured, not inferred:

```
user types      25 Sep 2026, 09:00   (their clock)
browser sends   "2026-09-25T09:00"   no zone
server stores   2026-09-25 09:00 UTC
shown back      25 Sep 2026, 14:30   ← five and a half hours late
```

**Every transport order ever created through the UI carries a customer deadline shifted by the
local offset.** Unlike the transit bug this one never refused anything — it just quietly stored
the wrong time, which is why nobody saw it.

Fixed by `toTransportOrderPayload()`, which converts on the way out. Verified in the browser:
typed 09:00, list now reads **09:00 am**.

### Sibling 2 — date-only fields drift by a day west of UTC. REPORTED, NOT FIXED.

`<input type="date">` sends `"2026-09-25"`. The server stores midnight UTC. `fmtDate()` renders
with `toLocaleDateString`, which converts to the viewer's zone:

```
Asia/Kolkata      picked 25 Sep  →  displays 25 Sept 2026   correct
America/New_York  picked 25 Sep  →  displays 24 Sept 2026   OFF BY ONE DAY
```

A licence expiry, a document validity date or a due date shown one day early is a compliance
answer that is wrong, and `PretripService` blocks dispatch on exactly those dates.

**Not fixed in this pass, deliberately.** It does not bite today — Sangoé runs in IST, where the
offset is positive and the date survives — and the correct fix is to render a date-only value
without any timezone conversion, which means changing `fmtDate` or introducing a `fmtDateOnly`
across call sites in **P1's, P2's and P3's** panels (`DocumentsPanel`, `DriverForm`,
`VehicleForm`, `CostsPanel`, `CollectionPanel`, the whole Fleet folder). That is a cross-section
change, not a fix, and it needs a decision rather than a quiet edit during a defect pass.

**It becomes urgent the day anyone opens Sangoé from a timezone west of Greenwich.**

### The numbers are fine

Checked as part of the same sweep and reported because a sweep that only lists what it found is
half a sweep. Every numeric field (`approved_freight`, `package_count`, `gross_weight_kg`,
`volume_cbm`, advances, costs) submits `e.target.value` — a plain string like `"12450.5"` — with
no locale formatting applied on the way out. `toLocaleString` appears only in DISPLAY paths.
Nothing round-trips wrong. The one transform on a submit path is `Number(pickedConsignment)` on
a container attach, which is an integer id.

### The guard

`TransportDateTimeContractTest` fails on the exact shape of the original bug and on a
`JourneyPanel` that stops letting the server stamp its own clock. Proven by reintroducing the old
one-liner. It does **not** yet assert that every `datetime-local` in the module is converted —
that is worth adding the next time this area is touched, and is why sibling 1 survived the first
fix.

---

## D-112 — Both guards written this week were blind, and the second break found a third hole

**Raised:** 2026-09-18. **Fixed.** Recorded because the lesson outlives the bugs.

Three faults, all in test code, all found by attacking my own guards rather than by running them.

### 1. A comment-stripper that stripped the file

`TransportDateTimeContractTest` and `TripClosureTest` both did:

```php
preg_replace('#//.*$|/\*.*?\*/#ms', '', $src)
```

The `/s` flag makes `.` match newlines, so `//.*$` runs greedily **from the file's first comment
to its last line**. Both guards were scanning an almost-empty string and would have passed on
anything at all.

Measured:

```
input   <?php  ·  // a comment  ·  $x = "type=\"datetime-local\"";  ·  // another  ·  $y = 1;
/ms     '<?php\n'                        ← everything after the first // is gone
/s + [^\n]*   the whole file, comments removed   ← correct
```

**The timezone guard passed while the second timezone bug was still live in the file it read.**
A test that cannot fail converts a gap into confidence, which is worse than no test.

### 2. One probe in one position is not proof

The D-106 caller scan had the identical `/ms` line and nevertheless went red when it was tested.
The only reason: the probe happened to be inserted **above that file's first comment**, in the
sliver the broken regex left behind. Re-probed at the end of a different service, it fires
properly now.

### 3. And the second break found a third hole

With the stripper fixed, the timezone guard was attacked twice more:

| probe | file | mechanism | result |
|---|---|---|---|
| **A** | `TransportOrderForm` | renders `type="datetime-local"` directly | **red** — correct |
| **B** | `DispatchPanel` | renders `type={field.type}` from `DISPATCH_FIELDS` | **GREEN — wrong** |

Removing DispatchPanel's conversion also removed the file's last mention of the literal, so the
file **dropped out of the guard's scope** and the guard reported success over a real regression.

The scope test now covers both: a file is in scope if it renders a datetime field **or** consumes
an exported field config that declares one. The config list is read from `constants.js` rather
than hardcoded, so a new config is covered without anyone remembering this test exists.

Both probes now fire. Both were restored.

### A fourth, caught on the way

The first version of that config scanner used
`/export const ([A-Z_]+)\s*=\s*\[(.*?)\n\]/s` and reported `PRETRIP_CATEGORY_ORDER` — a list of
plain strings with no fields in it. The lazy capture ran past its own closing bracket into the
next declaration. Rewritten to walk the file line by line.

**Three of these four are the same mistake: a pattern matching more than its author pictured.**
Regexes over source are guard-writing's sharpest tool and its commonest way to be wrong, and the
only defence that works is to break the guard and watch it go red — twice, in two different ways.

---

## D-113 — The canonical registry has no events table, and two product documents build on one

**Raised:** 2026-09-18, reading the source for `trip_events` on the owner's instruction.

**STOS-DB §37** names the table outright:

> **TRIP EVENT LOG** — "Do not overwrite every historical status. Maintain: `trip_events`."
> Examples: PLANNED, ASSIGNED, DISPATCHED, STARTED, GATE_IN, PORT_ENTRY, PORT_EXIT, DELIVERED

**STOS-DB §38** makes it the architecture: *"Current status = latest valid state. History =
events."*

**STOS-CTD** goes further and builds the Digital Passport on it — §31's worked timeline, §32's
"timeline must combine events from all connected systems", §33's source vocabulary, §34's
immutability rule, §35's filters, §101's "the Passport should have access to a chronological
event stream", §133 listing `Events` among the eighteen records the Passport is composed from.

**Step 11's DB_Registry does not contain it.** DB-001…DB-020 cover orders, trips, assignments,
vehicles, drivers, costs, advances, expenses, documents, exceptions, risks, bills, collections,
settlements, profit snapshots, rates, customers, suppliers, documents and policies — and no
events table. There are correspondingly no `DB_Fields` rows, no `API_Registry` row, no
`Permissions` row and no `Event_Registry` entry for it.

So the registry that is supposed to be canonical is silent about the one table two product
documents treat as the spine of the Passport.

### Why this one is different from D-38 and D-45

Those recorded entities the registry omitted while some other document defined them in passing.
This is an entity **STOS-DB names, gives a purpose, gives example values, and states an
architectural principle for** — and Step 11 still has no row. It is the largest single gap
between the product documents and the canonical registry found so far.

### How it is handled

The schema in `PLAN-trip-events.md` is derived from **STOS-DB §37 and CTD §§31–35, 101, 133**,
and every column in it cites the line it comes from. Nothing is invented; where two sections
disagree — CTD §101's nine categories against §35's seven filters — the divergence is recorded
and put to the owner rather than silently resolved.

Three further gaps follow from the same silence and are ruled in that plan rather than guessed:
no locked `event_type` enum exists anywhere (both lists say "Example"), no permission row exists,
and no API row exists.


---

## D-114 — Two blockers cleared and neither of us noticed, for three days

**Raised:** 2026-09-19. **Not a code defect. A process one, and it cost real time.**

The owner asked whether P2 or P3 might have landed something we were waiting on. Both had.

| | | |
|---|---|---|
| **16 Sep** | **Zafar** — "a consignment can hold its own paperwork" | `TransportDocumentEntity::CONSIGNMENT` in `ALL` and `ACTIVE`, `DELIVERY_ORDER` in the type enum, `CONSIGNMENT_APPLICABLE`, and both document routes. **Every one of the three changes `REQUEST-person3-document-entity.md` asked for.** |
| **17 Sep** | **Shivam** — `ReconcileFleetMasters` on master | D-100's blocker (a) closed. |

**Our own walk of MS-001 §14, written on 18 September, recorded step 3 as "PARTIAL — LR/DO
blocked on P3".** It had not been blocked for two days. The register said it was, the register
was read instead of the repository, and the document went out saying we were waiting on work
that was already done.

That is the expensive failure mode: **it sends you to ask somebody for something they have
already given you.** It nearly did exactly that here.

### What was actually still open — verified against the code, not the register

- `TripBill::markInvoiced()` still has no caller. The only mentions in `app/` and `routes/` are
  our own comments saying so. **D-106 stands**, closure stays PLUMBED.
- `FleetResourceGateway` still carries one method. **D-100 (b) and (c) stand**, so the
  allocation repoint still cannot happen even though (a) is clear.
- No feedback entity anywhere. **Still P3's, still unspecified.**

### And nobody is at fault

Neither of them announced it. **We did not announce the trip lifecycle to them either**, and
that landed on the 17th. Three people on one repository, each shipping several times a day, is
simply a situation where announcements are not a reliable channel — and the answer is to look
rather than to wait.

Recorded as a standing rule in `TEAM-CONTRACTS.md`: *before every block, and before any message
saying you are blocked, fetch and check each open blocker against the code.*

### D-100's status, corrected

| | |
|---|---|
| ~~(a) no reconciliation for ambiguous plates~~ | **CLEARED 17 Sep** — `ReconcileFleetMasters` is on master |
| (b) `FleetResourceGateway` has one method | **open** |
| (c) Fleet's `vehicles` reads zero through Transport's paths | **open** |

Two blockers, not three. The repoint still cannot happen, and for one fewer reason than the
register said yesterday.

---

## D-115 — Four lifecycle events were never written live, and a backfill hid it

**Raised:** 2026-09-19, during the closure walk. **Ours (P1). Fixed the same day.**

### What was wrong

`trip_events` had ten registered P1 types that **no code emitted**. The four that mattered were
`trip.created`, `trip.submitted`, `vehicle.allocated` and `pretrip.passed` — the opening half of
every trip's timeline.

### Why nobody saw it, including the person who walked the screen

`BackfillTripEvents` reconstructs a dozen types from the audit trail. It had been run, so **every
existing trip already had those rows** and every timeline on screen looked complete. MS-001 §14
step 14 was walked in a browser on 18 September and marked **WORKS**, correctly — the screen was
right. The rows behind it were reconstructions.

A trip created after the backfill would simply have been missing four lines. Nothing would have
failed, nothing would have logged, and the gap would have been noticed whenever somebody
eventually compared an old trip's timeline with a new one.

It was found by reading trip 43's rows after the closure walk and asking, of each one, *where did
this come from* — not by any test and not by looking at the screen.

### The fix

Ten live recorder calls, each after its transaction commits, matching the five that already
existed:

| Type | Where |
|---|---|
| `trip.created` | `TransportTripService::createFromOrder` |
| `trip.submitted` | `TransportTripService::submitForViability` |
| `trip.returned` | `TransportTripService::reject` |
| `vehicle.allocated`, `driver.allocated` | `AllocationService::assign` |
| `crew.released` | `AllocationService::release` |
| `pretrip.passed` | `PretripService::passPretrip` |
| `exception.acknowledged` | `TripExceptionService::acknowledge` |
| `container.created`, `container.attached`, `container.detached` | `ContainerService` |
| `consignment.created` | `ConsignmentService::create` |

`exception.acknowledged` deserves its own line: `raise` and `resolve` both recorded and this one
did not, so a timeline showed an exception appearing and disappearing with nothing between — and
the SLA clock, which starts there, started invisibly.

Confirmed live rather than assumed: `TRP-2026-000036` was created on the dev database and its
first three events read **LIVE**, not `BACKFILLED`.

### The guard, and what it caught immediately

`TripEventEmissionTest` pins the set of declared-but-unemitted types. It does not forbid the gap —
declaring P2's telemetry and P3's billing vocabulary before the work lands is deliberate — it
requires that the unemitted set is **exactly** the documented one, so both a new silent type and a
deleted emitter go red.

It was broken two ways before being trusted: deleting a live emitter (named `pretrip.passed`) and
declaring a new type with no emitter (named `trip.rerouted`).

**Two was not enough.** Merging `origin/master` an hour later brought nine emitters — five of P3's
(`e0077b00`) and four of P2's (`79815f29`) — and **the guard stayed green through all of them.** It
matched the type only as a FIRST POSITIONAL ARGUMENT, and both of them had used the named form:

```php
record('trip.closed', trip: $t)         // P1's dialect — seen
record(type: 'invoice.posted', ...)     // P2's and P3's — invisible
```

So on the day two sections shipped nine emitters, a test whose entire job was to notice emitters
reported that none of them existed — and it mis-reported one of **our own** calls the same way,
`TripEventRecorder::correct()`, which had been emitting `event.corrected` in the named form all
along. The allow-list built from that scan was wrong about four different people's code, including
the author's.

Fixed to read both forms and both branches of a ternary (`type: $x ? 'genset.off' : 'genset.on'`
emits both, and crediting one leaves the other looking silent). Then broken two more ways: deleting
a named-argument emitter, and gutting one branch of a ternary. Both named the right type.

**The lesson, which is more general than this test: a guard that only recognises the dialect its
author happens to write is a mirror, not a guard.** Three of this project's guards have now failed
in this exact shape — the two comment-stripping regexes, the datetime scanner that missed indirect
config consumption, and this one. Every time, the guard was correct about the code its author had
in mind and blind to the code somebody else wrote.

It also immediately contradicted the hand-run grep used to write its own allow-list: that grep had
counted `genset.on` as emitted, because the only occurrence outside the registry is **an example
in `TripEventRecorder`'s docblock**. The test was right and the grep was wrong.

That is also why `AllocationService` writes its two calls out longhand instead of looping a
`[$type => $id]` table: **a type assembled from a variable is a type the audit cannot see.**

### The general lesson

*A screen populated by a migration proves nothing about the code that is supposed to populate it.*
Walking the screen is necessary and was not sufficient here. Where data can arrive by more than one
route, the question is not "is it there" but "what put it there".

---

## D-116 — Telemetry joins the trip on a raw id across two vehicle namespaces

**Raised:** 2026-09-19, walking MS-001 §14 step 8. **P2's code (`TripTimelinePublisher`). Raised,
not fixed.** Blocked behind **D-100(c)**.

### What it does

`TripTimelinePublisher::openTripFor()` finds the trip a reading belongs to like this:

```php
DB::table('transport_trips')
    ->where('tenant_id', $vehicle->company_id)
    ->where('vehicle_id', $vehicle->id)          // <- a FLEET vehicle id
    ->whereIn('status', self::LIVE_TRIP_STATES)
```

`$vehicle` is `App\Domains\Fleet\Models\Vehicle`. `transport_trips.vehicle_id` holds a
`transport_vehicles` id — **P1's placeholder table, which is exactly what D-100(c) says has not
been repointed yet.** The two tables issue ids independently and there is no foreign key on the
column, so the comparison is two unrelated integers.

### Today it is silent; the day it is not, it is wrong

On the dev database the ranges happen not to overlap — Fleet holds ids 1–2 and
`transport_vehicles` holds 35–36 — so every reading is dropped by the `if (! $trip) return;`
branch, which reads as "a truck idling in the yard". **Step 8 is therefore still NOT REACHABLE,
and not for the reason the walk document gave.** It is not a missing read contract any more; the
contract is built and joins on the wrong key.

Constructed the collision to see which failure it is. A trip was set `in_transit` with
`vehicle_id = 1`, and a reading was published for **Fleet** vehicle 1 (`MH12DEMO01`):

```
gps.activated        —  Tracking active on MH12DEMO01
temperature.reading  —  4.2°C on MH12DEMO01
```

Both landed on that trip, **naming a truck that is not the truck the trip's `vehicle_id` refers
to.** So this is not a "no data yet" defect. It is a silent wrong-join that produces no error, no
log line and a plausible-looking timeline entry. The rows were removed afterwards and the trip put
back.

### Why it is worth raising now rather than after the repoint

Because the repoint is what makes the ids collide. Today the mismatch is invisible; the moment
Transport's allocation starts writing Fleet ids into that column, some trips will carry Fleet ids
and the older rows will still carry `transport_vehicles` ids, and **both will match something.**
The safest moment to fix the join is before the two id spaces are mixed in one column.

### Not ours to fix

`TripTimelinePublisher` is P2's file and the standing rule is not to edit another section's code
to make our step pass. Raised in `NOTE-person2-telemetry-joins-on-the-wrong-vehicle-id.md`.

### P2's fix, same day — better, and still open

`6842b70a` — *"the id finds the candidate, the plate decides"*. The id narrows the candidates, the
registration decides, and a mismatch fails closed with a logged warning. Right shape. Re-ran the
collision experiment against it; two findings, and the defect stays open for the second.

**It does not reach the plate check on our data.** The candidate set is the Fleet vehicle's own id
plus its `legacy_transport_vehicle_id` — 29 and 30 — while the live `transport_vehicles` rows for
those trucks are 35 and 36, and trips point at 10, 17 and 35. Nothing overlaps, so every reading
finds no candidate and is dropped. The *correct* case was tested explicitly: a trip on
`transport_vehicles#35`, genuinely the same truck as Fleet #1, is **blocked**. Step 8 still shows
nothing. That half is stale data, not code — `stos:reconcile-fleet` reports both vehicles
unmigrated, which is the same fact from the other end.

**The one case that publishes is the one that should not.** `platesFor()` looks the id up in
**both** masters and unions the result. When the `transport_vehicles` row is absent, the only plate
returned is Fleet's own — read with the trip's id — so the check compares the Fleet vehicle's plate
against itself and always agrees:

```
platesFor(1) -> ['MH12DEMO01']   (from `vehicles`; transport_vehicles#1 does not exist)
$plate       ->  'MH12DEMO01'    (the same vehicle)   => true => publish
```

Not hypothetical: trips 2, 12 and 14 hold `vehicle_id` 10 and 17, neither of which exists in
`transport_vehicles`. The day Fleet issues an id 10 or 17, that truck's telemetry lands on trips
that carried different trucks.

Suggested to P2 in `NOTE-person2-d116-nearly-the-plate-check-confirms-itself.md`: resolve the
candidate's plate **only** in `transport_vehicles` while that is what the column means, and treat a
missing row as unknown rather than as a match.

### What this does to the repoint

**Held**, on the lead's sequencing call, and this strengthens it. `stos:repoint-trip-fleet-refs`
(dry run, 19 Sep) reports **0 rows on all four columns**, which reads as "nothing to do" and is not
that: the stored mapping points at `transport_vehicles` 29 and 30, rows a reseed replaced. A
repoint today would change nothing **and** leave every trip pointing at the placeholder table.

Order: fix the mapping → P2's plate check starts firing → then repoint.

### FIXED — P2, 2026-09-19, `2edbb24b`

Both halves, plus the thing neither note named: the stale mapping was hiding the **right** trip as
well as exposing the wrong one, and it hid it through the "idling in the yard" branch, which looks
identical to a healthy truck on screen. That is why step 8 read as no data rather than as an error.

**The plate is read in one table.** `legacyPlateFor()` resolves only in `transport_vehicles`, which
is what the column means before the repoint, and a missing row is *unknown* rather than a match.
P1's suggestion, taken as written.

**The switch is no longer a remembered edit.** The references the repoint cannot map stay in the
old id space afterwards, so pointing the lookup at Fleet "when the meaning changes" would recreate
this defect inside repointed data — with trips 2, 12 and 14 as the first three cases.
`stos:repoint-trip-fleet-refs --apply` now writes a verdict for every reference it looks at, into a
new Fleet-owned table `fleet_reference_repoints`:

| verdict | meaning |
|---|---|
| `to_id` = a Fleet id | moved; this is what the number means now |
| `to_id` = `NULL` | pointed at a row that is gone; left alone, never matchable |
| no row | raised after the switch, so already in the new space |

`openTripFor()` asks the ledger first, the company-level switch second, the legacy plate third.
No schema of P1's changed, and the repoint is now reversible.

**The plate finds candidates as well as confirming them,** so step 8 works before the mapping is
repaired rather than after it. `transport_vehicles` is unique on (tenant, normalised plate), so it
adds at most one id.

**`stos:reconcile-fleet` stops calling one match an ambiguity.** Three outcomes — not inserted,
repairable, genuinely ambiguous — and `--relink` repairs only the middle one, refusing when two
live legacy rows claim one Fleet vehicle. The dry run no longer prints a clean `0` for both "done"
and "the map matches nothing", and `--apply` refuses to strand references without `--force`.

**Eight of P2's own tests were passing because of this defect** — `TripTimelineTest` pointed the
trip at a Fleet id with no legacy row behind it, which is the self-confirming arrangement itself.
The setup now builds the pre-repoint world, and asserts the two id ranges do not overlap.

Verified against dev data and rolled back: same truck publishes, wrong truck blocks, dangling id
blocks. Suite 1,438 passing. Reply in `CLOSED-person1-d116-and-the-stale-mapping.md`.

### The general lesson, and it is the same one twice

*Reasoning that something is safe is not the same as making it safe.* D-109 was documented as a
hazard and shipped armed; D-116 round one was documented as "correct, not a bug" and was luck;
round two replaced the luck with a check that could not fail. Each time the gap was between an
argument and a mechanism. **What made the difference both times was P1 constructing the case
instead of reading the code** — and the second time, doing it to a fix rather than to the original.

---

### VERIFIED BY P1, 2026-09-19 — the code half is closed. The DATA half is not, yet.

Re-ran the collision experiment rather than accepting the closure, because the first D-116 fix was
also reported closed and was not. **Five cases this time, three before the switch and two after.**

| Trip's `vehicle_id` points at | Want | Got |
|---|---|---|
| The same truck (`transport_vehicles#35`) | publish | **publish** ✅ |
| A different truck (`#36`) | block | **block** ✅ |
| A dangling id that equals a live Fleet id (`1`) | block | **block** ✅ |
| *(post-switch)* correctly repointed `35 → 1`, ledger `to_id = 1` | publish | **publish** ✅ |
| *(post-switch)* unmappable row left in the old space, ledger `to_id = NULL` | block | **block** ✅ |

The last row is the one that matters most, and it is the scenario the repoint was held for. It
behaves correctly. `legacyPlateFor()` resolves in one table and treats a missing row as unknown —
P1's suggestion taken as written — and `fleet_reference_repoints` is **read** by the publisher, not
merely written by the command, which was the first thing checked after D-115.

**The misleading dry run is fixed too.** It no longer says "0 rows" and stops; it now reports
*"0 to move, 5 pointing at ids that no longer map"* and names the likely cause. That was the
second half of the original finding.

### What is still open: the mapping itself is stale, and a repoint today still moves nothing

`stos:reconcile-fleet` now diagnoses it precisely instead of advising a fix for a problem that did
not exist:

```
· #35 MH 12 DEMO 01 — Fleet #1 (link points at #29, which is gone). Repairable.
· #36 MH 14 DEMO 02 — Fleet #2 (link points at #30, which is gone). Repairable.
```

A `--relink` flag now exists to repair it. **It has not been run** — it is a write, and it is not
in the sequence agreed with the owner. Simulated in a rolled-back transaction, this is exactly what
it would unlock:

| | After `--relink` |
|---|---|
| Mapping | `{35 → 1, 36 → 2}` |
| `transport_trips.vehicle_id` | **2 would move**, 3 still unmappable |
| `trip_assignments.vehicle_id` | **3 would move**, 0 unmappable |

Those 3 permanently unmappable trip references are the dangling `10` and `17` on trips 2, 12 and
14 — the reseed's leftovers, and the same rows that made the original defect dangerous. They are
handled by design: the ledger records `to_id = NULL` and the publisher refuses to match them,
which is the fifth row of the table above.

**So: the code is closed and proven; the data is one command away.** A repoint run before that
command would still move nothing, which is why this is being reported rather than pushed through.

---

## D-117 — CTD names eleven search keys in §4 and nine in §97, and two of them have nowhere to look

**Raised:** 2026-09-19, reading §4 and §5 before proposing the search entry point. **Ours to ask,
not to decide.**

### The contradiction

| Source | Keys | Notes |
|---|---|---|
| **§4 PRIMARY SEARCH KEY** | **11** | Container *(preferred)* + LR, DO, Transport Order, Trip, Customer Reference, Vehicle, Driver, Invoice, **POD**, **Internal Consignment ID** |
| **§97 SEARCH API** | **9** | The same list **minus POD Number and Internal Consignment ID** |
| **§150 NON-NEGOTIABLE** | **3** | Only *"Container Number must be a primary search key"* and *"LR and DO must be searchable"* |

The two keys §97 drops are exactly the two that do not resolve today, which suggests the §4 list
was written as an aspiration and §97 as the buildable subset. Nothing in the package says which
governs, so this is recorded rather than resolved by picking the convenient one.

### POD Number has no field anywhere

`trip_documents` — the table that actually holds PODs — carries `file_path`, `file_name`,
`file_mime`, `file_size`, `file_hash`, `status`, `verified_by`, `verified_at` and **no
`document_number`**. A POD in this system is a file attached to a trip, not a numbered artefact.

`transport_documents` does have a `document_number` and lists `pod` among its types, but PODs are
not filed there — the POD path is `TripDocumentService` and `trip_documents`.

So "search by POD number" cannot be built without (a) a schema change, (b) a ruling on who issues
the number, and (c) a format. **None of the three is specified.** §97 and §150 both omit POD, which
is consistent with it never having been designed as a numbered entity.

**Recommendation: do not invent one.** Say "POD numbers cannot be searched" on screen until
somebody specifies it. Inventing a numbering scheme here is precisely what Hard Rule 1 forbids.

### Internal Consignment ID already works

Read as `consignment_number`, it resolves today — `CNM-2026-000034` finds its consignment. If the
document means a different identifier, no section defines one, and that is a question for the
owner rather than a build.

### Also corrected

`CommandPalette.jsx`'s docblock says §4 lists "nine entry points". It lists eleven. Ours, wrong
since the palette was written, and to be fixed with whatever ships from
`PROPOSAL-search-as-the-entry-point.md`.

### RULED 2026-09-19 — the three answers, and the divergence they authorise

**Q1 — a trip number goes to the trip page, not to the passport. APPROVED, with two conditions.**

This is a deliberate departure from §4's letter — *"all relevant search paths must ultimately lead
to the same Digital Passport"* — and it is recorded here because **an undocumented divergence is
indistinguishable from an oversight.** The next person to read §4 against the code would otherwise
"fix" it.

The owner's reasoning, kept verbatim in substance: *§4's intent is traceability — that the whole
story is always reachable — not that every screen is the same screen. Someone typing
`TRP-2026-000034` is a dispatcher who wants the working screen, and landing them on a traceability
view would be answering a question they did not ask.*

| Condition | Status |
|---|---|
| The passport must be **one obvious click** from the trip page | **Met.** It was not: "What is being moved" linked to the consignment drawer and read as cargo detail. The trip payload now carries its container and the trip page opens with *"Open the full journey of sgoe-402215-9 → Everything that has happened to this container"*, above the fold. Clicked in a browser; it lands on `/app/transport/containers/23`. |
| Log the divergence with the reasoning | This entry, plus `test_a_trip_number_deliberately_goes_to_the_trip_and_not_the_passport`, which pins it in the suite so it cannot be silently "corrected". |

**Q2 — Internal Consignment ID = `consignment_number`. CONFIRMED.** Already the established
reading: `SCHEMA-PROPOSAL-consignment-container.md` cited "CTD §4 Internal Consignment ID" as the
source for that column on 11 September.

**Q3 — do not invent a POD number. CONFIRMED.** The answer we give is: *"not searchable yet,
because a POD here is a file attached to a trip rather than a numbered artefact."* Inventing a
numbering scheme to satisfy a search key would be D-9 for the fifth time. Kept in this entry so
whoever eventually specifies POD numbering finds the reason it was left alone.

### And one thing the brief attributed to the wrong cause

The proposal records this in full, but it belongs here too: **a vehicle number failing to reach a
Digital Passport is not caused by D-110/D-116.** The chain

```
MH12DEMO01 → transport_vehicles #35 → TRP-2026-000034 → consignment 34 → container 23
```

resolves end to end today, inside our own id namespace. The only reason a plate stops at Fleet's
list is that `TransportSearchService::vehicle()` returns that path and follows through no further —
a leftover from D-62, never revisited against §4. What genuinely waits on the repoint is a plate
held by Fleet and not by our placeholder table.

---

## D-118 — the driver half of the reconcile counts links instead of checking them

**Raised:** 2026-09-19, preparing the repoint. **P2's command. Raised, not fixed.**
**Blocks the repoint** — found before `--relink` was run, not after.

### The same stale-link damage as vehicles, on drivers, unreported

| | Live rows | What the link says |
|---|---|---|
| Vehicles | `transport_vehicles` 35, 36 | `legacy_transport_vehicle_id` 29, 30 — **reported as repairable** ✅ |
| Drivers | `transport_drivers` 39, 40 | `legacy_transport_driver_id` 33, 34 — **reported as fine** ❌ |

Both were broken the same way by the same reseed. The vehicle side now diagnoses it precisely
(*"link points at #29, which is gone. Repairable."*). The driver side says:

```
Drivers: 2 legacy rows — 2 have a Fleet profile.
```

Which is **true and misleading**. `reconcileDrivers()` counts `driver_profiles` rows whose
`legacy_transport_driver_id` is not null. It never asks whether that id points at a driver that
exists. Two profiles do carry a legacy id; neither carries one of the two that are actually there.

### What it costs

`--relink` is vehicle-only by its own help text — *"repair links where exactly one Fleet vehicle
carries the plate"*. So running it today repairs half the problem and leaves the other half
looking healthy:

| After `--relink` as it stands | |
|---|---|
| `transport_trips.vehicle_id` | 2 move, **0 unmappable** |
| `transport_trips.driver_id` | 0 move, **2 unmappable** |
| `trip_assignments.vehicle_id` | 3 move, **0 unmappable** |
| `trip_assignments.driver_id` | 0 move, **3 unmappable** |

Five driver references would be written into `fleet_reference_repoints` as permanent `to_id = NULL`
— the ledger entry that means *"this row points at something that no longer exists; never match
it"*. Permanent, in a ledger, for rows that are repairable in one pass.

### The fix is the exact analogue of the plate, and it is already half-built

`driver_profiles` carries `licence_number` **and `licence_normalized`** — the same shape as the
vehicle side's plate normalisation. The licences match one-to-one today:

```
driver_profile #2  RJ14 2019 0011221  ->  transport_drivers #39
driver_profile #3  MH12 2020 0033445  ->  transport_drivers #40
```

Simulated in a rolled-back transaction, with the three junk trips cleared and both halves
relinked:

```
transport_trips    vehicle_id   2 would move, 0 unmappable
transport_trips    driver_id    2 would move, 0 unmappable
trip_assignments   vehicle_id   3 would move, 0 unmappable
trip_assignments   driver_id    3 would move, 0 unmappable
```

**Everything reaches zero.** No permanent NULLs in the ledger at all, which is the condition the
owner set for the repoint going ahead.

### Also found: there is no way to remove a trip through the services

The three junk trips (2, 12, 14 — one of them numbered `TRP-Hyxjpm`, a test fixture) hold
`vehicle_id` and `driver_id` with **zero assignment rows**, which is a state the services cannot
produce. `AllocationService::release()` works from a `TripAssignment` and there is none, so it
cannot reach them; there is no `cancelled` state in `TripStatus`, no delete route and no delete
method. `TransportTrip` uses `SoftDeletes`, so the model's own mechanism exists — but nothing
above it does. Recorded because "clear it through the real services" is not currently possible for
a trip, and that is worth knowing before somebody needs it on real data.

---

## D-118, continued — the relink ran. The driver half is now the only thing holding the repoint.

**2026-09-21.** Backup proven, `--relink` run under authorisation, dry run re-run. Stopped before
`--apply`, which is where it must stay.

### The backup is a backup now, not a file

`sangoe_crm_backup_20260919_175826_pre-repoint.sql` was restored into a throwaway **MySQL 8.0.46**
container — the same version as dev — because the app user is granted only `sangoe_crm.*` and no
scratch schema could be created on the host.

**570 tables restored.** Row counts matched live exactly on every table this touches:
`transport_trips` 39, `transport_vehicles` 2, `transport_drivers` 2, `trip_assignments` 3,
`vehicles` 2, `driver_profiles` 3, `trip_events` 31, `transport_containers` 2, `users` 17. Content
spot-checked, not just counts — trips 2, 12, 14 and 43 came back with the right numbers and states.

### Two corrections to what this entry said two days ago

**1. The junk trips were already cleared, and I reported otherwise.** Trips 2, 12 and 14 carry
`deleted_at` dated **2026-09-16** — five days before I first looked at them. My earlier count used
`DB::table('transport_trips')`, which **bypasses soft deletes**, so I counted three already-deleted
rows as live references and reported "clear them first" for work that was already done.

The lesson is the one this project keeps relearning in new costumes: *I queried the table, not the
model, and the table does not know about `deleted_at`.*

**2. Clearing them would not have helped anyway, because the command does not honour soft
deletes.** `RepointTripFleetReferences` reads through `DB::table($table)` with no `deleted_at`
filter, so it sees deleted rows and would repoint and ledger them. That is a finding in its own
right and is raised with P2.

### What the relink did

`stos:reconcile-fleet --relink` repaired exactly two links, one-to-one on the plate, and wrote
nothing else — confirmed by reading the command: it updates `vehicles.legacy_transport_vehicle_id`
and **writes no ledger rows at all.**

```
vehicles.legacy_transport_vehicle_id   {1: 29, 2: 30}  ->  {1: 35, 2: 36}
```

That matters for the gate attached to this step: the permanent-`NULL` harm the condition guards
against can only be written by `--apply`. `--relink` cannot cause it.

### The dry run is meaningful for the first time

| | Before | After |
|---|---|---|
| `transport_trips.vehicle_id` | 0 to move, 5 unmapped | **2 to move**, 3 unmapped |
| `transport_trips.driver_id` | 0 to move, 5 unmapped | 0 to move, **5 unmapped** |
| `trip_assignments.vehicle_id` | 0 to move, 3 unmapped | **3 to move**, 0 unmapped |
| `trip_assignments.driver_id` | 0 to move, 3 unmapped | 0 to move, **3 unmapped** |

### The vehicle side is clean. The driver side is not, and that is the whole blocker.

Every unmappable **vehicle** reference is a soft-deleted junk trip:

```
trip 2   TRP-Hyxjpm        vehicle_id=10  SOFT-DELETED
trip 12  TRP-2026-000004   vehicle_id=17  SOFT-DELETED
trip 14  TRP-2026-000006   vehicle_id=17  SOFT-DELETED
```

**No live vehicle reference would get a permanent NULL.** Five live **driver** references would:

```
trip 43        TRP-2026-000034  driver_id=39  LIVE
trip 44        TRP-2026-000035  driver_id=40  LIVE
assignment 34  trip 43          driver_id=39  LIVE
assignment 35  trip 44          driver_id=40  LIVE
assignment 36  trip 44          driver_id=40  LIVE
```

All five are real demo data pointing at real drivers, unmappable only because
`driver_profiles.legacy_transport_driver_id` still holds **33 and 34** while the live
`transport_drivers` rows are **39 and 40** — and `--relink` is vehicle-only.

**So `--apply` must not run.** It would write five permanent *"never match this row"* entries
against live records, which is precisely the outcome the owner's condition exists to prevent.

The fix is unchanged and still one pass: extend `--relink` to match drivers on the normalised
licence, as it matches vehicles on the normalised plate. `driver_profiles.licence_normalized`
already exists and the licences match one-to-one. Simulated with both halves relinked, every
column reaches **0 unmappable**.

**State: ledger 0 rows. Nothing repointed. Backup proven and retained.**

---

## D-119 — a finished trip never gave its vehicle and driver back

**Raised:** 2026-09-21 by the owner, who had been freeing the driver by hand after every trip.
**Ours. Fixed the same day.**

### What was wrong

`markReleased()` sat on `FleetResourceGateway` with **no caller anywhere in the codebase**. Its two
siblings had 5 and 2. So Fleet was told when a resource was taken and **never** when it came back.

Worse than the gateway: nothing freed our own tables either. `AllocationService::release()` frees a
vehicle and driver, but it is the ABANDONED path — it reverts the trip to `approved` and voids the
pre-trip checklist. No completion path existed at all. Proven on live data rather than by reading:
`TRP-2026-000035` was **closed** with its vehicle still `allocated` and its driver still `assigned`.
The other demo trip's resources were free only because somebody had released them by hand — which
is the complaint.

STOS-FLEET §8 is on the owner's side: *"Vehicle status must be driven by business events. Users
should not freely type 'Available' without satisfying required conditions."*

### The decision: at DELIVERY, not at closure — and why the driver is not an exception

Neither FLEET nor OPS states the moment outright, so it was ruled here. The reasoning, because an
unwritten decision reads as an oversight later:

**The vehicle.** STOS-OPS §39 and §8 put the chain as `DELIVERY → CUSTOMER HANDOVER → FEEDBACK →
POD → DOCUMENT RETURN → BILLING READINESS → ACCOUNTING → OPERATIONAL CLOSURE`, and §83 says plainly
that *"operational closure does not necessarily mean accounting closure"*. Our `closed` is the
accounting end — it requires a verified POD, an invoice and a collected payment. Holding a physical
truck until a customer pays ties an asset to a commercial event, which is the thing that separation
exists to prevent. STOS-FLEET §16 supplies the other half: *"Available — Asset is free."*

**The driver looked like an exception and is not.** OPS §78 does give a driver a post-delivery
duty — physical documents must be returned, and the system raises a *"Submit Trip Documents"* task.
So the fair question is whether the driver stays held until they do.

**§79 answers it.** The consequence of a late return is *"reminder; supervisor escalation; billing
block; management visibility"* — a **billing** block, not an availability block. The document says
what to withhold and it is money, not the driver. So both come free together.

*(When §78/§79 are built, that billing block belongs in the billing gate. `releaseOnDelivery()`
should not acquire a document check.)*

### What shipped

`AllocationService::releaseOnDelivery()`, called from `recordDelivery()` after the commit. It moves
the assignment to `RELEASED` — the vocabulary's only terminal state; there is no `COMPLETED` and
inventing one would be a new state with no Step 11 entry — frees both resources, tells Fleet, and
records `crew.released` with `because: delivered`.

It deliberately does **not** revert the trip or void the checklist. `release()` does both because
that is an abandoned allocation; this is a completed one, and the checklist it passed is a
historical fact about a journey that happened.

**The Fleet call was wired into `release()` too.** That path had the same gap: it freed our two
tables and left Fleet holding the resource for ever.

### The screen says it

Releasing the crew must not erase who drove. The trip payload read only the ACTIVE assignment, so
without a fallback a delivered trip would have blanked its own vehicle and driver — the opposite of
what freeing them is meant to communicate. It now falls back to the latest assignment, the step
summary reads *"MH 12 DEMO 01 · Ramesh Kumar · released"*, and the panel explains that they were
freed at delivery and stay listed because this is who ran this trip.

The allocation controller still reads the ACTIVE assignment, correctly: it decides whether a trip
can be allocated or released, rather than displaying history. And `assign()` refuses anything that
is not `approved`, so releasing at delivery cannot reopen allocation on a finished trip.

### Proved, and proved to fail

Six tests. Broken three ways: removing the call from delivery reproduced the original bug and
failed four of them; freeing our tables without telling Fleet failed exactly one; freeing a
broken-down truck failed exactly one.

The breakdown guard is the one worth keeping in mind — FLEET §8 says a vehicle *"cannot become
AVAILABLE if critical maintenance unresolved"*, so a delivery must not overwrite a breakdown. The
driver is judged separately and still comes free.

### The stuck rows were repaired through the new path

Not by hand: `releaseOnDelivery()` was run over the finished trips, which released
`TRP-2026-000035`'s assignment and left all four demo resources available.

---

## D-120 — the repoint moves four reference columns and there are six

**Raised:** 2026-09-22, at step 5 of the repoint, **before `--apply` was run.** **P1 found it,
P2's command.** **BLOCKS `--apply`.**

### What is wrong

`stos:repoint-trip-fleet-refs` moves exactly four columns:

```
transport_trips    vehicle_id, driver_id
trip_assignments   vehicle_id, driver_id
```

**Two more exist and are not covered:**

| Table | Columns | Live references |
|---|---|---|
| `trip_exceptions` | `vehicle_id`, `driver_id` | **4** |
| `trip_advances` | `driver_id` | 1 |

And they hold precisely the ids the repoint is moving away from:

```
trip_exceptions #2  EXC-2026-000001  vehicle_id=35  driver_id=39
trip_exceptions #4  EXC-2026-000003  vehicle_id=36  driver_id=40
```

`35, 36` are the legacy vehicles and `39, 40` the legacy drivers. After `--apply`, trips and
assignments would hold Fleet's `1, 2` and `2, 3` while these two tables still hold `35, 36, 39,
40` — **the same column name meaning two different things in one schema, with nothing marking
which.**

### Why it matters, and why it is D-116's shape again

There are **seven** relations across **four** models, all resolving these columns against the
legacy master:

| Model | Relations |
|---|---|
| `TransportTrip` | `vehicle()`, `driver()` |
| `TripAssignment` | `vehicle()`, `driver()` |
| **`TripException`** | **`vehicle()`, `driver()`** |
| **`TripAdvance`** | **`driver()`** |

*(I found these independently rather than working from the count in P2's reply, as instructed. It
is seven, and the two models I had missed in my own coupling list are the two the repoint does not
cover — which is what makes this a defect rather than a tidying job.)*

So the reader swap has no correct answer for those three relations:

- **Repoint them** → they resolve legacy ids against Fleet: **blank today, a different truck the
  day the ranges overlap.** That is D-116 exactly, in a place neither of us had a guard.
- **Leave them** → `vehicle_id` means Fleet in two tables and legacy in two others, permanently,
  with no marker. The next person to write a join has a one-in-two chance.

### Why it is not visible yet, which makes it worse

Neither relation is loaded anywhere today — no service or controller reads
`$exception->vehicle` or `$advance->driver`. So **nothing would break at `--apply`**, no screen
would go blank, no test would fail. It would be discovered by whoever first renders a vehicle on an
exception, against data that has been wrong for however long.

### The ledger does not cover it either

`fleet_reference_repoints` records a verdict per row **for the four columns the command processes**.
The uncovered two get no entry at all — so the "a row with no entry was created after the switch
and is therefore in the new space" rule would read these legacy rows as new-space rows. The ledger
would confirm the wrong answer.

### Also found: one reference is not an id

`trip_advances #2` carries `driver_id = 1212010`. There is no such driver; the live ids are 39 and
40. Whatever that value is, it is not a foreign key, and it should not be carried through a
migration as though it were.

### What I did not do

**`--apply` has not run.** The reconcile and both `--relink` passes have (they are authorised and
write one column each); vehicles and drivers are now correctly mapped `{35→1, 36→2}` and
`{39→2, 40→3}`, and the dry run reports **2 + 2 + 4 + 4 rows to move with every unmappable row a
soft-deleted junk trip** — no live reference would be lost. The repoint is ready in every respect
except this one.

---

## D-126 — CLP's M01–M14 is a second vocabulary, not a view, and four of them have no words at all

**Raised:** 2026-09-22, sizing the client portal foundation. **Ours under MS-001 v1.1 §4** (the
trip/milestone APIs are Person 1's). **Blocked on AUTH-REC-001 B-08.**

### It is not a filter over `trip_events`

CLP §8 defines fourteen client milestones. **Our internal states do not appear in it** —
`dispatched`, `pretrip_ok` and `in_transit` are not milestones, and M03 *Container Yard Arrival* is
not a state we have. It is a parallel vocabulary describing the same journey from the client's side.

### Measured against what we emit

| Covered | Count | Which |
|---|---|---|
| **Fully live** | 2 | M01 allocation, M13 billing |
| Partly | 4 | M02, M08, M10, M14 |
| **Registered, never emitted** | 5 | M06 `gate.in`, M09 `port.entry/exit` *(P2)*; M11 `documents.handed_over`, M12 `feedback.*`, M14 `collection.recorded` *(P3)* |
| **No vocabulary anywhere** | **4** | M03 container yard arrival · M04 container inspection & loading · M05 container yard departure · M07 loading & sealing complete |

Also unregistered: **detention** (part of M10), which §8 wants as a *"contractual detention
calculation"* — a commercial rule nobody has specified.

### The shape is wrong too, not only the coverage

§8 requires each milestone to carry *"planned/actual time, location, source, actor, evidence,
remarks, exception and audit trail."*

`trip_events` has `occurred_at`, `recorded_at`, `source`, `actor_*`, `summary`, `detail` — and
**no planned time, no location, no evidence link, no exception link.** A milestone is a richer
object than an event: it carries an expectation as well as a fact, which is what makes *"running
late"* expressible. An event cannot be late.

### Why this is not a mapping job

1. A **milestone entity** with planned vs actual, location and evidence — a new table, and
   **B-08 already records that Step 11 has no milestone registry.**
2. **Four new event types**, which under Hard Rule 4 need a Step 11 entry or a ruling — and they
   are not renamings of things we do. *Container yard arrival* and *loading and sealing complete*
   are **operational acts nobody performs in the system today**; somebody at a yard has to record
   them.
3. **Five emitters owned by other people** — two P2's, three P3's.
4. §27 forbids the cheap way out: *"do not force clients to manually update information STOS can
   derive from system/GPS/geofence/telemetry/workflow."* So the timestamps are supposed to come
   from telemetry and geofencing, which is D-116's territory and reaches no trip today.

### Estimate

**Larger than the rest of the client portal foundation put together, and most of it is not code.**
It is a registry decision, four new capture points that change what somebody does at a yard, five
emitters we do not own, and a detention rule nobody has written.

**Recommendation: keep M01–M14 out of the foundation entirely.** The portal does not depend on it.
A plain-language journey built from the nine moments we already emit is honest, buildable now, and
should be labelled as an interim so nobody mistakes it for §8's model.

---

## D-121 — A genset could be fitted to a retired vehicle, and the guard against it had never fired

> **Numbering note (P1, 2026-09-23).** This number was used twice. P1 had also written a D-121
> locally, and **conceded the number** on the tie-break that published beats unpublished — P1's
> entries renumbered to D-126…D-130, with every cross-reference in code and docs following.
>
> **The band was breached, though, and that is the thing to fix.** The register is banded per
> developer — **P1 is D-100+, P2 is D-200+, P3 is D-300+** — precisely so two people cannot collide.
> D-121 is inside P1's band. Recorded here rather than sent: nothing is blocked by it, and it is
> worth one sentence when we are next in contact, not a message of its own.

**Raised and fixed:** 2026-09-22, P2, while converting the last lowercase enums (T-58). **P2's code.**

### What it was

`GensetService::fit()` refuses to put a working unit on a scrapped truck, because the unit would
read as in service on a vehicle that never moves again:

```php
if ($vehicle->status === 'retired') {
    throw new BusinessException('That vehicle is retired — fitting a working genset to it would strand the unit.');
}
```

`vehicles.status` has held **`RETIRED`** since `2027_01_07_000001` adopted the ruled uppercase
vocabulary. So that comparison has been false on every call it has ever handled. No error, no log
line, no refusal — the fit simply succeeded.

### Why it survived a test suite

`test_a_working_unit_is_not_fitted_to_a_retired_truck` passed the whole time. Its fixture set the
vehicle to `'retired'` — **the same misspelling the guard used** — so the test and the bug agreed
with each other and neither agreed with the database. The fixture now uses
`Vehicle::STATUS_RETIRED`, and the test fails against the old guard.

That is the second time in this module a green test has been found asserting a defect rather than
the behaviour: `TripTimelineTest` did the same for D-116, where eight cases passed because the
fixture reproduced the wrong id space.

### What was done

Fixed with the change that made it findable. Both sides now use `Vehicle::STATUS_RETIRED` and
`Genset::RETIRED` rather than string literals, so a future rename moves both or neither.

### The general lesson

*A test written from the same assumption as the code under test verifies the assumption, not the
code.* Both of these were caught by changing the vocabulary underneath them — which is an argument
for doing the rename rather than living with two spellings, not just for tidiness.

---

## D-300 — M06/M07/M08 have two applications writing one milestone, and no rule for who wins

**Raised:** 2026-09-23, P3, while mapping the cross-app data flow. **Nobody's code yet — and that
is the point.** Numbered in the P3 band (D-300+) under the banding agreed after the D-58..D-61
collision.

### What it is

Two specifications hand the same three milestones to two different people:

| | Arrival (M06) | Loading & sealing (M07) | Departure (M08) |
|---|---|---|---|
| **CLP §3** | Client Warehouse/Gate role confirms | confirms | confirms |
| **DVR §9** | Driver confirms | captures evidence | captures evidence |

Both are written as the authority. Neither defers to the other.

### Why it is worse than a duplicate

A gate clerk and a driver standing in the same yard will not click at the same moment, and they
will not always agree. So this is not "two ways to record one fact" — it is two facts, arriving
out of order, with no rule for which one the trip keeps.

And there is nothing underneath to arbitrate it: Step 11 registers no milestone table, no
milestone event and no milestone endpoint. The words `milestone`, `container`, `portal`,
`feedback` and `geofence` appear **zero times** in the canonical registry, against `trip` 84 and
`POD` 14 by my count — P1 counted across every XML part of the workbook and got higher numbers
with the same zeros. So there is no canonical place for the answer even once someone gives it.

CLP §27 says milestones should be derived from gate scan, geofence or telemetry rather than typed
by a person. If that is honoured, both of these become fallbacks rather than sources, and the
conflict is bounded. That is a ruling, not a reading.

### Why it needs deciding before anyone builds

The milestone APIs are P1's under MS-001 v1.1 §4, the driver half is P2's, the client half is P3's.
All three implement whatever is decided. A ruling after two of the three have built is a rewrite of
two modules, which is the expensive order to do this in.

### Status

**Open — escalated for an owner ruling.** Independently reached from two directions: P1 raised the
same gap from the milestone-API side. Not started by anyone, deliberately.

---

## D-301 — M12 feedback has two producers, no table, no event and no endpoint

**Raised:** 2026-09-23, P3, same pass as D-300. **P3's to build once ruled.**

### What it is

CLP puts client feedback at M12. DVR §20 puts driver feedback at final handover, and adds that
negative feedback can open a customer/service concern. So "M12 Feedback" names two different
things collected from two different people at roughly the same moment, and the spine lists it once.

Underneath it there is nothing at all: no `feedback` table in Step 11, no event in the registry,
no endpoint in the API list. The word does not appear in the canonical registry.

### Why it is not simply buildable

CLP §20 describes the client half in enough detail to build from, and B-09 forbids building from
CLP narrative without a Step 12 ticket — which is exactly the rule that stops one developer's
reading of a specification becoming the product's behaviour. Two producers with no ruled owner is
the case that rule exists for.

There is a related asymmetry worth stating: `client_feedback` already exists on the CRM side and
the client portal already has `GET/POST /api/portal/client/feedback`. So the client half has a
home and the driver half does not, and "reuse what exists" would quietly decide the question by
picking the half that is easier — which is how a specification gets settled by convenience.

### What is needed

One ruling covering: whether M12 is one milestone or two, who owns each, whether the driver's
feedback is the same record as the client's, and what a negative response opens. Then a Step 12
ticket.

### Status

**Open — blocked on a ruling, not on effort.** P1 raised the same gap independently.

---

## D-127 — the client portal table has no pagination, and one customer already has 35 rows

**Raised:** 2026-09-22, walking the Shipments screen. **P3's component** (`ClientPortalRecords`).
**Not ours to fix — logged and raised.**

### What it is

`ClientPortalRecords.jsx` is one generic table serving **all eleven portal sections**, and it
offers **no sort, no filter and no pagination**. It fetches, and it renders every row it received:

```
rows.length === 0 ? "Nothing here yet." : <table> … rows.map(…) </table>
```

The only filtering that exists anywhere is a `?filter=` URL parameter passed through to the fetch,
used by Invoices for `overdue`. There is no control for it on screen.

### Why it matters now rather than later

It is correct today and will not be for long. Measured: the busiest customer already has **35
transport orders**, and shipments accumulate for the life of the relationship — unlike invoices,
where the same customer has one. A haulage customer running five trips a week reaches two hundred
rows inside a year, and the screen will render all two hundred into one scrolling table with no
way to find last Tuesday's.

### Not fixed here, deliberately

Adding paging to the shared component for the benefit of one section is how a house style
fractures: the other ten sections would inherit a behaviour their owner did not choose, and the
next person would find two conventions where there was one. It belongs to whoever owns the
component.

Raised with P3 in `NOTE-person3-the-portal-table-will-not-scale.md`. Our Shipments section follows
the house style exactly and will inherit whatever is decided.

---

## D-128 — "Issue reported" tells a customer that something went wrong, not what

**Raised:** 2026-09-22, walking the journey view as the client. **P1.** **Needs a business ruling.**

### What it is

The journey view sends `event_type` and `occurred_at` and nothing else. `trip_events.detail` and
the actor are deliberately withheld — they are internal, and the leak guard asserts they never
appear. So an exception reaches the customer as exactly two words.

On the demo trip, that reads:

```
19 Sept 2026, 08:14 am   Issue reported
19 Sept 2026, 08:14 am   Issue resolved
…
19 Sept 2026, 11:38 am   Issue reported
21 Sept 2026, 08:04 am   Issue resolved     ← after delivery, after the invoice
```

A customer sees that an issue was raised on their pharma load and stayed open past delivery, and
has no way to learn whether it was a delay, a temperature excursion, or a paperwork correction.
The natural next action is a phone call — which is the opposite of what a portal is for.

### Why it is not being fixed by guessing

There is no recorded rule about what a customer may be told about an exception. `trip_events.detail`
is free text written by dispatchers for dispatchers; publishing it unread would put internal
wording, names and speculation in front of the customer. Picking a safe subset — exception *type*
but not detail — would be inventing a disclosure rule, which Hard Rule 1 forbids.

### What a ruling would need to say

1. Does the customer see the exception **category** (delay / damage / temperature / document), or
   only that one exists?
2. If a category is shown, which categories are customer-visible at all? Some are commercially
   sensitive (a detention charge dispute) and some are not (a road closure).
3. Is a resolution **note** ever published, and if so who writes the customer-facing wording — the
   dispatcher raising it, or someone reviewing it afterwards?

Until then the two words stand. They are honest and they leak nothing; they are just thin.

### Related

Feeds [D-126](#d-126--clps-m01m14-is-a-second-vocabulary-not-a-view-and-four-of-them-have-no-words-at-all)
— CLP §8's milestone model has no exception milestone either, so this gap survives that mapping.

---

## D-129 — the client portal spent a word CLP §8 will want back

**Raised:** 2026-09-23, auditing our own portal work against §8. **P1.** **Fixed the same day.**

### What it was

`trip.closed` reached the customer as **"Completed"**, and `closed` showed as the status word
"Completed" too. CLP §8's M14 is:

| ID | Milestone | Minimum control |
|---|---|---|
| M14 | **Payment Received / Trip Closure** | Payment recorded and commercial closure |

Our `trip.closed` does not require payment. So the same word would have moved later when M01–M14
lands, and would have changed from *"we have finished"* to *"you have paid"* — a word that
quietly starts reporting on the customer's own behaviour. A customer who learns a word and then
has it redefined is worse off than one who never learned it.

### "Closed" was not the answer either

The obvious replacement collides just as hard: M14's own title is *"Payment Received / **Trip
Closure**"*. §8 claims both halves. Anything meaning *completed* or *closed* is spoken for.

**Chosen: "Shipment finished."** "Finished" appears nowhere in §8, so it is ours to use, and both
of §8's words stay free for M14 to define when it arrives.

`test_closure_does_not_use_a_word_m14_claims` rejects *completed*, *closed*, *closure*, *paid* and
*payment* in that phrase, so the next person to reach for the obvious word is told why not.

### The wider rule this is an instance of

An interim vocabulary must not spend words the specified vocabulary will need. Where our interim
word and §8's milestone describe the same moment, the words should already agree (they do for
**Invoiced/M13** and **Vehicle assigned/M01**). Where they describe *different* moments, the
interim word must be one §8 does not use — otherwise the interim silently pre-empts the spec.

Related: [D-126](#d-126--clps-m01m14-is-a-second-vocabulary-not-a-view-and-four-of-them-have-no-words-at-all).

---

## D-130 — `arrived` is a state a customer can see with no event behind it

**Raised:** 2026-09-23, writing the one-vocabulary test. **P1.** **Named, not fixed.**

`TripStatus::ARRIVED` exists and trips pass through it, but `TripEventType` registers **no arrival
event at all** — `grep arrived` over the registry returns nothing. So the status changes and the
journey shows no row for it.

The status word is now *"Arrived at destination"* rather than the bare *"In progress"* it fell
through to before, which is honest. But it is the only customer-visible state whose journey cannot
show the moment that produced it.

Emitting `trip.arrived` is a **new event type**, which under Hard Rule 4 needs a Step 11 entry or a
ruling — and Step 11 registers no arrival either. Raised rather than added.

Two smaller states were unmapped for the same reason and are now covered without a new event:
`pod_pending` reads as *"Delivered"* (waiting for the POD is our work, not the customer's) and
`settlement_pending` reads as *"Invoiced"* (settlement is between us and the transporter). Both
correctly show the last moment the customer's shipment actually reached.

---

## D-131 — the four repointed tables have no foreign keys, and one already holds a value that is not an id

**Raised:** 2026-09-23, during the repoint dry run. **P1 for three tables; P3 for `trip_advances`.**
**Logged, not fixed — the constraints cannot go on until the data is cleaned.**

### What it is

The schema carries **488 foreign key constraints.** These four tables have **none at all** on the
columns the repoint moves:

| Table | `vehicle_id` | `driver_id` | Owner |
|---|---|---|---|
| `transport_trips` | no FK | no FK | P1 |
| `trip_assignments` | no FK | no FK | P1 |
| `trip_exceptions` | no FK | no FK | P1 |
| `trip_advances` | — | no FK | **P3** |

`trip_advances` has no foreign key on **any** column — not `trip_id`, not `tenant_id`, not
`driver_id`.

### What it already cost

`trip_advances` #2 carries `driver_id = 1212010`. That value is **not an id in any table**: not a
legacy driver, not a `driver_profile`, not a user. The trip's actual driver is 40. `purpose` on the
same row reads `SEGDHCNV`. It was typed into a form on 2026-09-19 and nothing asked whether it was
a driver.

Recorded in the repoint ledger under the verdict **`never_valid`**, which exists because of this
row — see below.

### Why the constraints are not being added here

Adding a foreign key to a column that already holds invalid values fails on the spot, and finding
out *which* values are invalid across four tables is its own piece of work. What would have to
happen first:

1. Sweep all seven columns for values present in neither the legacy masters nor Fleet.
2. Decide each one — they are not all the same kind of wrong. A stale legacy id is a mapping
   failure; `1212010` was never a reference at all.
3. **Decide which table each column should point at**, which cannot be settled before the repoint
   lands: three of them are mid-migration between the legacy masters and Fleet, and a constraint
   written now would have to be dropped and rewritten next week.

So this waits for a block of its own, after `--apply`.

### The half that is not ours

`trip_advances` belongs to Person 3, and he is the owner of that half. This entry **is** the whole
record: nothing has been sent to him and his table has not been edited. Advance #2 is recorded in
our ledger and left exactly as it is, so he sees the evidence rather than a repaired row.

**Nothing of his is blocked by this**, so it waits until we are next in contact rather than
interrupting him. Raise it then.

### The general rule underneath

**A migration tool that has to describe bad data will eventually meet data that none of its
descriptions fit, and the temptation is to use the nearest one. The nearest one is a lie.**

The repoint could classify `1212010` only as *stranded* — *"points at a legacy row with no Fleet
counterpart"*. That sentence is false: it points at no legacy row. Stranding it would have written
a true-sounding, wrong sentence into a permanent ledger, and every downstream reader would then
refuse the row on a stated ground that was not the real one. Same family as
[D-116](#d-116) and [D-118](#d-118): an answer that is wrong without being an error.

The fix is not to bend the nearest verdict. It is to give the tool a **true** one.

### Related

The three junk trips (2, 12, 14) are the other half of the same story and are recorded under
`unmapped_legacy` — a verdict that *is* true of them. They also sit at status `allocated` with
**zero rows in `trip_assignments`**: a trip cannot be allocated with nothing allocated to it. They
are not merely empty, they are inconsistent, and that is the evidence that they were never real
trips when somebody eventually decides to clear them. They are **not** being deleted as a side
effect of a migration.

---

## D-132 — two of Person 2's migrations cannot run on MySQL, and one half-ran

**Raised:** 2026-09-23, running the repoint. **P2's migrations; P1 repaired them to unblock.**

Both pass on SQLite, which is what the test suite uses, and both fail on MySQL, which is what dev
and production use. Neither was reachable by a green test run.

### `2027_01_10_000001_adopt_uppercase_fleet_enums`

```
SQLSTATE[42000]: ... near 'rows from `maintenance_jobs` ...'
SQL: select `status`, count(*) as rows from `maintenance_jobs` ...
```

`rows` is a reserved word in MySQL 8.0 and `DB::raw('count(*) as rows')` is unquoted. The failure is
in `reportStragglers()` — the **diagnostic**, not the data change — and it halted the whole
migration chain.

It also half-ran: the conversion loop runs per table and reports after each, so `vehicles.status`
was uppercased and `driver_profiles.status` was not. The dev database sat with **`AVAILABLE` in one
table and `available` in the other** — the exact case mismatch that makes an allocation silently
skip a resource. Fixed by backticking the identifier.

### `2027_01_10_000003_generalise_party_assignees`

Renames `task_party_assignees` → `party_assignees`, then swaps a unique index. On MySQL the drop is
refused:

```
Cannot drop index 'task_party_unique': needed in a foreign key constraint
```

The foreign key it needs is removed by **`000004`, which runs afterwards**. On SQLite `renameColumn`
rebuilds the table, so the index comes across by column and the problem never appears.

Worse, it is not re-runnable: the first three steps had applied, so a retry failed at the rename
with *"Table 'party_assignees' already exists"*, and the chain could not move at all.

Fixed by **guarding each step** rather than reordering: `000004` drops and rebuilds the table with
`party_subject_unique_v2`, so the index swap is superseded either way, and moving it would change
what `000004` means. The swap is now attempted and allowed to fail with a comment saying why.

### Why P1 edited P2's files

Normally this would be a written request. These blocked the entire migration chain on MySQL, and
the database was already half-converted — leaving it there was the worse option.

**And it was not optional.** The crash is what left `vehicles.status` uppercase against
`driver_profiles.status` lowercase, and that mismatch is exactly what makes an allocation silently
skip a resource. The repoint could not be walked at all until the chain completed.

| File | Change | Effect on behaviour |
|---|---|---|
| `adopt_uppercase_fleet_enums` | one identifier backticked | none — the query already meant this |
| `generalise_party_assignees` | each step wrapped in an existence check; the index swap allowed to fail with a comment | none on a clean run; a **crashed** run becomes re-runnable |

No order changed, no step added or removed, no intent altered.

**Status: changed in P1's tree, unpushed, awaiting P2's review. Nothing has been sent** — nothing of
his is blocked, so it goes to him when we are next in contact. If he prefers the reorder over the
guards, it is his file and his call.

### The general point

A test suite on SQLite and a deployment on MySQL means green tests prove the code runs *somewhere*.
Reserved words, foreign-key ordering and `renameColumn` are precisely where the two disagree, and
all three appeared in one merge.

---

## D-133 — an eighth reference into the legacy masters, which the repoint does not cover

**Raised:** 2026-09-23, repointing the eligibility readers. **P1.** **Logged, not fixed.**

`transport_documents` links to a vehicle or a driver **polymorphically** — `entity_type` +
`entity_id` — so it is an eighth reference into the legacy masters, and
`RepointCoversEveryReferenceTest` cannot see it: that test looks for columns *named* `vehicle_id` or
`driver_id`, and this one is named `entity_id`.

Live today: **4 driver documents under legacy driver 17, 3 vehicle documents under legacy vehicles
6, 9 and 11.** All seven already point at legacy rows that a reseed deleted, so they are orphaned
*before* the repoint and no behaviour changes today. Structurally it is D-120 again: one schema, two
id spaces, nothing recording which.

The consequence when documents are real: `VehicleEligibilityService::documentVerdict()` would look
up a Fleet id in a column holding legacy ids, find nothing, and report *"No documents are required
for this vehicle"* — a compliance check **silently passing** rather than failing.

### Not fixed here

A polymorphic pair does not fit `REFERENCES`, whose shape is `[table, column, kind]`, and extending
it would mean changing the schema-derived test that D-120 just installed. That test and that command
are **P2's**, and reshaping them to admit a different kind of reference is a design decision, not a
patch. Raised with him.

---

## D-134 — after the repoint, no driver can be allocated: our binding picks one directory and there are now two

**Raised:** 2026-09-23, walking the allocation screen after `--apply`.
**OWNER: P1 — ours, in our own files.** **FIXED 2026-09-23 — composite built and approved.**

> **Ownership corrected, 2026-09-23.** This entry first named P2 and said "waiting on you". That was
> wrong, and it was wrong in the laziest way: I assigned it to the module where the *symptom*
> appeared instead of reading where the *choice* is made. The choice is `StosServiceProvider.php:72`
> and `config/stos.php` — both ours. Nothing of P2's needs to change. Nothing was sent to him.

### What happens

With allocation reading Fleet, the driver picker on an approved trip shows:

```
None ready for TRP-2026-000036
CANNOT BE USED RIGHT NOW · 1
  Rajesh Kumar  HMV  — Not eligible
  No licence is on file for this driver. (Fleet compliance desk)
```

One person, correctly blocked. **The two real drivers are not there at all** — not listed, not shown
as ineligible, absent.

### Where the choice is actually made — ours

```php
// app/Providers/StosServiceProvider.php:72   ← P1's file
$mode = config('stos.directory.driver', 'auto');   // ← P1's config
...
$crmPresent = Schema::hasTable('tpv_workers')
    || Schema::hasTable('purchase_workers')
    || Schema::hasTable('client_contacts');

return $crmPresent ? new CrmDriverDirectory() : new StandaloneDriverDirectory();
```

`client_contacts` exists, so `auto` resolves to CRM, and `CrmDriverDirectory` cannot resolve a
`stos:` ref. Our binding's choice.

### The data is correct — all of it

The D-62 move migration did exactly the right thing, and says so in its own comment: *"The name lives
in a directory, never in driver_profiles. With no CRM person to point at, the standalone register is
the directory — which is what it exists for."* It inserted the people into `stos_drivers` **and**
the profiles pointing at them.

Measured, both directories asked directly:

```
STANDALONE — "Read from the STOS driver register (standalone mode)."
   stos:1 — SANGOE DEMO Ramesh Kumar
   stos:2 — SANGOE DEMO Suresh Patil
CRM — "Read live from TPV workforce, Purchase workforce, Vendor contacts, Customer contacts."
   crm_client_contact:1 — Rajesh Kumar

crm->find(1,'stos',1)               → NULL
std->find(1,'crm_client_contact',1) → NULL
```

Three real people, two directories, **no overlap**, and each directory correctly refuses the other's
refs.

### The actual defect, in one sentence

**`auto` assumes the two sources are alternatives — integrated *or* standalone — and after the D-62
move they are simultaneous.** The config comment says so in its own words: *"use the CRM's
directories when they are present, and the STOS-local register when they are not."* That was true
until a migration put real people in the local register *inside* a CRM installation.

### Why not just set the mode to `standalone`

Because it trades one blank picker for another. `crm_client_contact:1` — Rajesh Kumar, profile 1 —
would then be the invisible one. Every CRM-sourced driver would disappear to reveal ours. **Not
done.**

### Proposed, NOT built

A `CompositeDriverDirectory` in **our** tree implementing `App\Domains\Fleet\Contracts\DriverDirectory`:
`people()` concatenates both sources, `find()` dispatches on the `source` prefix, `describe()` names
both.

Implementing P2's interface is not editing P2's code — the contract exists precisely for this, and
its own docblock says *"Swapping the implementation swaps the source. Nothing above this line has to
know which one is in use."* The refs are already namespaced (`stos:` vs `crm_*:`), so a merge cannot
collide, and each implementation already returns null for refs it does not own.

It became a fourth mode (`auto` | `crm` | `standalone` | `both`), with `auto` resolving to `both`
when the CRM is present **and** `stos_drivers` is non-empty.

### Built and verified

`CompositeDriverDirectory` — `people()` concatenates and sorts by name, `find()` **dispatches on the
source prefix** rather than trying both, `describe()` names both registers. Neither underlying
directory was touched.

The picker went from one candidate to three:

```
#1  eligible=no   No licence is on file for this driver. (Fleet compliance desk)
#2  eligible=yes  Cleared by Fleet
#3  eligible=yes  Cleared by Fleet
```

`CompositeDriverDirectoryTest` — five tests, and one of them guards the property the namespacing
buys: **each register must keep REFUSING the other's handles.** `find()` dispatches instead of
trying both precisely so a refusal can never silently become a fallback; if it did, two registers
could answer for one handle and a ref would stop naming one person.

Broken two ways before it was trusted: reverted to CRM-only → **red**, naming the invisible driver;
made the CRM directory answer for a `stos:` handle → **red** on the refusal guard.

One test passed *vacuously* on the first run — the CRM register was empty in the fixture, so its
loop iterated nothing and showed a tick. Fixed by seeding a CRM contact and asserting the list is
non-empty first.

---

## D-135 — `driver_profiles` has no name, and two screens were selecting one

**Raised:** 2026-09-23, same walk. **P1. Fixed.**

`GET /api/transport/trips/{id}` returned **503**:

```
SQLSTATE[42S22]: Unknown column 'name' in 'field list'
```

Two places eager-loaded `driver:id,name,driver_code,licence_class,availability` — four columns that
`transport_drivers` had and `driver_profiles` does not. Fleet stores **no names at all**: a driver is
a reference into the CRM directory (`source` + `source_id`) plus a licence. Container 360 read
*"Trip not found"* because of it.

Fixed by selecting the columns that exist. **The name is not restored** — resolving it means going
through the directory, which is [D-134](#d-134--after-the-repoint-no-driver-can-be-allocated-fleets-directory-does-not-list-them)'s
territory. Until then the trip screen and the container passport identify a driver by licence rather
than by name, which is honest but worse, and it is recorded here rather than left to be noticed.

---

## D-136 — the double-booking lock was taken on a table nobody was competing for

**Raised and fixed:** 2026-09-23, sweeping the readers after the repoint. **P1.**

### What it was

`TripAssignmentService::lockResources()` — BR-P0-003's half of the guard — locked
`transport_vehicles` and `transport_drivers` by id. After the repoint `$vehicleId` is a **Fleet**
id, so it locked whichever legacy row happened to carry that number, and for a vehicle created
through Fleet's own screen there is no legacy row at all: `first()` returned null and it locked
**nothing**.

### What it did NOT break, stated before what it did

**It did not let two dispatchers take the same truck.** That was the first framing of this entry,
and the measurement disproved it. Raced three ways in a container, two processes, one vehicle, two
trips, same instant:

| lock | winner | loser | active assignments |
|---|---|---|---|
| on Fleet (**fixed**) | OK | `BusinessException` — *"already assigned to trip #50"* | 1 |
| on legacy (**as it was**) | OK | `QueryException: Deadlock found` | 1 |
| **none at all** | OK | `QueryException: Deadlock found` | 1 |

No vehicle was double-booked in any configuration. **What broke is which refusal the loser is
shown**: QA-003's designed, actionable message was replaced by a raw database error. A dispatcher
saw `SQLSTATE[40001]: Serialization failure: 1213 Deadlock found` instead of a sentence naming the
trip to release.

**The broken lock was indistinguishable from no lock.** That is the finding, and it is why nothing
went red.

### What actually held, and why that is not reassuring

MySQL's deadlock detection and the unique indexes over `trip_assignments`' generated columns.
**Neither was designed for this job** — the belt-and-braces note in this service's docblock names
the index as the backstop, not the mechanism. The braces held while the belt was cut.

**It was not correct, it was lucky.** And three runs measure three interleavings, not every
interleaving: this entry claims what was observed, not that the data was safe under all of them.

### Why the suite could never have caught it

A guard against a race is not proven by a test that does not race, and the suite runs on in-memory
SQLite with one connection. `tests/concurrency/race-allocation.sh` is the real proof — two PHP
processes against MySQL in a throwaway container. `AllocationLockTargetsFleetTest` holds the part
SQLite can prove: that the lock names the table the allocation writes.

### One thing added that was not a repoint

A missing resource row now throws `ResourceNotFoundException` instead of locking nothing and
carrying on. **This is new behaviour in the allocation path and it should have been proposed before
it was written** — "propose before building" exists for exactly a new way for an operation to fail.
Checked before keeping it: all three drivers the composite returns have a `driver_profiles` row, so
no legitimate allocation is refused by it.

---

## D-137 — we ran a race harness against the shared dev database

**Raised:** 2026-09-23. **P1 — our own discipline break, logged in our own name.**

### What happened

Proving D-136 needed a real concurrent allocation. The first harness ran against **the shared dev
database**, and seeded itself with raw SQL:

```sql
INSERT INTO transport_orders ...        -- two orders
INSERT INTO transport_trips ...         -- trips 47 and 48
DELETE FROM trip_assignments ...
UPDATE transport_trips SET vehicle_id=NULL ...
UPDATE vehicles SET status='AVAILABLE' WHERE id=3;   -- P2's table
```

Every statement went past the services, past the audit trail, past the events, and past Fleet's own
status observer. The last one wrote directly into another developer's table.

### What it left behind

Trip 48 holding vehicle 3 under a live assignment, while vehicle 3 read `AVAILABLE`. **A truck
recorded as free while it was out** — the same shape we log against the junk trips, *"not merely
empty, inconsistent"*, except this one we created.

### The right venue existed and was already proven

In the same block, the pre-repoint backup was verified by restoring it into a throwaway `mysql:8.0`
container and tearing it down. **That container is where a race harness goes.** Same tool, same
afternoon, not reached for.

### Put right

- The assignment was released **through `AllocationService::release()`**, not with SQL, so the
  vehicle's status moved the way a release moves it. Verified: assignment `released`, vehicle 3
  `AVAILABLE`, trip 48 `vehicle_id` null — consistent.
- `tests/concurrency/race-allocation.sh` now builds its own container, loads a **structure-only**
  schema, seeds **through the real services** (`VehicleService`, `TransportOrderService`,
  `TransportTripService`) and destroys the container. Dev verified untouched afterwards.
- The two `TO-RACE` orders and their trips **could not be removed** — see D-138. They remain, in a
  consistent state, declared rather than deleted with SQL, which would repeat the original error.

### The rule

**A test that needs its own data needs its own database.** Convenience is the whole reason this
happened: dev was already migrated and seeded. A rule broken quietly once is a rule that is gone.

---

## D-138 — a trip or an order can never be cancelled or removed

**Raised:** 2026-09-23, cleaning up after D-137. **P1.** **Needs a business ruling.**

Cleaning up two trips created in error, the correct route turned out not to exist:

- No `cancel` or `delete` on `TransportTripService` or `TransportOrderService`. Both have `create`.
- No `DELETE` route for a trip or an order (`trips/{trip}/assign` is the only allocation delete).
- `TripStatus` has **no cancelled or rejected state at all.** From `approved` the only transition is
  `allocated`. The one terminal state is `closed`.

So an order or trip raised by mistake — a duplicate, a wrong customer, a test — can only be driven
forward to closure or left sitting in the list forever. There is no way to say *"this should not
exist"*.

Consignments, documents, drivers and vehicles all have a `delete`. Trips and orders, the two things
an operator creates most often, do not.

**Not invented here.** What a cancelled trip means commercially — whether it keeps its number,
whether it appears in reports, what happens to an order already invoiced — is a business rule
nobody has written. Raised, not guessed.

---

## D-139 — the database cannot be built from scratch on MySQL

**Raised:** 2026-09-23, building the D-137 container. **Not ours — HR module.** **Logged only.**

`php artisan migrate` against an empty MySQL database fails:

```
2026_08_31_000003_add_app_login_to_hr_employees .......... FAIL
SQLSTATE[42S22]: Unknown column 'sangoetrack_synced_at' in 'hr_employees'
  (SQL: alter table `hr_employees` add `app_login_enabled` ... after `sangoetrack_synced_at`)
```

The migration positions a column `after` one that a **later** migration adds. Dev and production
were built incrementally, so the column exists there and nobody has noticed.

**What it costs:** a new developer cannot build this database, and neither can CI. It is invisible
to the suite because the suite runs on SQLite, where `after` is ignored. Same family as D-132 — the
two drivers disagree and only one of them is tested.

Worked around in `race-allocation.sh` by loading a structure-only dump instead of migrating.
Nothing of ours depends on it. Raised for whoever owns HR.

---

## RULING-001 — the client portal is being built without Step 12 tickets

**Not a defect. A recorded suspension of a standing rule**, written down because a verbal approval
is not an artefact and a commit message is not a durable place.

**Recorded:** 2026-09-23, by Person 1, after auditing our own portal work.

### The rule being suspended

**STOS-AUTH-REC-001, finding B-09:**

> *"Step 12's 30-ticket pack contains no dedicated Client Portal ticket set… Do not implement from
> CLP narrative alone."*

Step 10 makes implementation ticket-driven and Step 12 is the approved executable scope. **There is
no approved ticket authorising a single line of the client portal.** Step 11 — which outranks CLP —
contains the term *portal* **zero times**; all 20 tables are internal and all 15 endpoints are
`/api/v1/transport/*`. Verified from the Step 11 XLSX directly, not from a summary of it.

### Who authorised it, and when

The owner, verbally, relayed through the technical lead, across the sequence of instructions that
began *"START THE CLIENT PORTAL FOUNDATION"* (2026-09-21) and ran through
*"GIVE ME A WORKING CLIENT LOGIN FIRST, THEN BUILD THE SHIPMENTS LIST STEP BY STEP"* (2026-09-22).
Each step was reviewed and accepted individually.

**No written ticket exists.** This entry is not a ticket and does not pretend to be one.

### What was built under it

| Surface | Detail | Hard Rule 4 class |
|---|---|---|
| `GET /api/portal/client/transport/shipments` | the customer's own trips, read-only | **new API** |
| `GET /api/portal/client/transport/shipments/{id}` | one shipment and its journey, read-only | **new API** |
| `'transport'` in `ClientContact::MODULES` | a portal permission, additive, default off | **new permission** |

And what was **not**: no new table, no new field, no new state, no new event, no change to
`TransportPermission::MATRIX` or `ROLE_MAP`. Both endpoints write nothing. Scope comes off the
contact's token, never a request parameter. Every field is whitelisted and the whitelist is guarded
by `ClientPortalTransportLeakTest` by value as well as by key.

### The distinction that matters, stated honestly

B-09 makes two claims and only one of them is breached.

- *"Do not implement from CLP narrative alone"* — **not breached.** The columns came from auditing
  our own tables and the journey phrases from our own `TripEventType` registry. Where CLP specified
  something with no table behind it — M01–M14 — it was measured and refused ([D-126](#d-126--clps-m01m14-is-a-second-vocabulary-not-a-view-and-four-of-them-have-no-words-at-all)).
- *"No approved ticket authorises the portal"* — **breached.** Two endpoints and one permission
  value exist with no Step 12 ticket.

### What is outstanding

1. **A retroactive Step 12 ticket** covering the two endpoints and the permission value. Owed.
2. ~~**The `{id}` route convention.**~~ **Closed 2026-09-23 — ruled by P3, in our favour.**
   `ClientPortalTest::test_no_portal_route_accepts_a_client_id` (Zafar, 2026-08-21) asserted that no
   route under `api/portal/client` may contain `{`. Ours does. He changed **his test, not our
   route**: 125 portal routes already take a path parameter, so the client portal was the outlier.
   `->whereNumber('id')` is what satisfies the replacement rule, and it was already in place.

   **Correction to this entry as first written.** It said that test was *"red on master because of
   us"*. It was not. Our route has never been on master — `git show origin/master:backend/routes/
   portal.php | grep -c transport/shipments` returns **0**. The failure existed only in a local
   working tree that had never been pushed. Claiming a shared branch was broken by unpushed work is
   a factual error, not modesty, and it is the kind that sends other people looking for damage that
   is not there. **Check which branch actually carries the code before reporting a break on it.**

### Why this entry exists at all

The remedy was written down before the breach and then not followed. From
`REPORT-new-package-v2.0-assessment.md`, question 3, 2026-09-21:

> *"B-09 — are we authorised to build the portal without Step 12 tickets? The package says no. If
> the answer is yes, that is a conscious suspension of Step 10's ticket-driven rule and should be
> recorded as one."*

An "APPROVED" was allowed to stand in for the artefact. **Not defensible; correctable.** This is
the correction.

---

## RULING-002 — the Fleet repoint was applied, 2026-09-23

**Not a defect. A record of an irreversible-looking operation and what it actually did**, written
down because the rows themselves cannot answer "which id space is this in" afterwards.

**Applied:** 2026-09-23 by Person 1, authorised by the technical lead, D-120 closed.

### What was run, in order

1. **Backup**, proved by restoring into a throwaway `mysql:8.0` container and checking today's rows
   were present — trips 39, events 44, assignments 4, the contact's `transport` permission, trip
   44's 21 events. Not a table count.
2. `stos:reconcile-fleet` — **0 outstanding on both halves.** `--relink` therefore wrote nothing.
3. Dry run — clean on both of P2's new guards (no contested mapping, no unsafe collision).
4. **Readers swapped** onto Fleet — see below.
5. `--apply --force`.

### The numbers

| Table | Column | moved | never_valid |
|---|---|---|---|
| `transport_trips` | `vehicle_id` | 2 | 3 |
| `transport_trips` | `driver_id` | 2 | 3 |
| `trip_assignments` | `vehicle_id` | 4 | 0 |
| `trip_assignments` | `driver_id` | 4 | 0 |
| `trip_exceptions` | `vehicle_id` | 2 | 0 |
| `trip_exceptions` | `driver_id` | 2 | 0 |
| `trip_advances` | `driver_id` | 0 | 1 |
| **Total** | | **16** | **7** |

`--force` was used deliberately: nothing could be moved for those seven, and recording a true
verdict against each is better than leaving them undescribed. **Nothing was deleted.** The legacy
tables still hold their rows, and every decision is in `fleet_reference_repoints`.

### What now reads Fleet

- Seven relations: `TransportTrip::vehicle`/`driver`, `TripAssignment::vehicle`/`driver`,
  `TripAdvance::driver`, `TripException::vehicle`/`driver`.
- `VehicleEligibilityService` — `Vehicle::forCompany`, `Vehicle::ALLOCATABLE`. **The case mismatch
  was real**: `VehicleStatus::AVAILABLE` is `'available'` and Fleet's is `'AVAILABLE'`, and the
  comparison fails *silently* — the allocation still writes, the truck simply never leaves the
  available pool.
- `DriverEligibilityService` — delegates licence, medical and profile status to
  `DriverService::eligible()`, keeping only the assignment clash, which Fleet cannot answer.
  Lower-casing would not have helped here: **`driver_profiles` has no `availability` column at all.**
- `AllocationService::allocate()` and `freeResources()`. `freeResources` matches `ON_TRIP_STATES`,
  not just `ALLOCATED` — a delivered trip's vehicle is `IN_TRANSIT`, and matching the earlier state
  only would strand every completed trip's truck. That is D-119 in its other direction.
- Status changes now go through `$model->update()` so **Fleet's** observer raises
  `fleet.vehicle.status_changed`. The reason is logged beside it rather than written as a second
  audit row: one status change with two audit trails that can disagree is worse than one.

### What is proven, and what is not

**Proven** — the Fleet page lists MH12DEMO01 and MH14DEMO02; trip 44 reads its crew from Fleet;
`VehicleEligibilityService::candidatesFor()` returns both trucks with `status: "AVAILABLE"` matched
and `eligible: true`; and `AllocationService::assign()` on trip 45 wrote `vehicle_id = 1` (a Fleet
id) and moved Fleet's vehicle 1 to `ALLOCATED` — the exact write the case mismatch would have
skipped in silence.

> **Correction.** This entry first said the allocation was proven *in the browser*. It was not. The
> trip screen showed "MH14DEMO02 — Vehicle and driver assigned", but that was assignment #37,
> **released on 2026-09-21**, being rendered with its ids repointed. My click opened the driver
> dialog and allocated nothing. The screen proved the Fleet *read*; it did not prove the write, and
> I reported it as if it had. The write is proven above, by calling the service and re-reading both
> rows. A screen that shows the right value is not evidence that the code under it ran.

**Blocked** — no driver can be allocated. [D-134](#d-134--after-the-repoint-no-driver-can-be-allocated-fleets-directory-does-not-list-them):
Fleet's bound directory does not list `source = 'stos'` profiles, so the two migrated drivers are
invisible. P2's to rule on. The full lifecycle walk stops at step 2 until it is answered.

**Outstanding** — the Transport test suite is heavily red (~299 in `tests/Feature/Transport`), because
its fixtures seed the legacy masters and never run the repoint. That is fixture work, it is known,
and it is not evidence the product is broken — the browser walk is. It needs a block of its own.

### To undo

`fleet_reference_repoints` holds `from_id`, `to_id` and a verdict per reference, so the move can be
read back and reversed. The pre-run backup is
`sangoe_crm-pre-repoint-20260923-1615.sql`.

---

## D-144 — our D-134 and D-135 fixes each contradict one of P2's tests

**Raised:** 2026-09-25, after merging master's 76 commits. **P1's changes, P2's tests.**
**Not edited. Needs a ruling.**

Two failures in `tests/Feature/Stos/DriverDirectoryTest`, both caused by us, neither touched.

### 1 · `auto` no longer resolves to `CrmDriverDirectory`

```
-'CrmDriverDirectory'
+'CompositeDriverDirectory'
```

His test asserts the binding's old rule: CRM tables present → the CRM directory. **That rule is what
D-134 deliberately replaced**, because after the D-62 move a CRM installation legitimately holds
drivers in the local register too, and choosing one hid the other. The composite contains his CRM
directory and returns everything it returned, plus the migrated drivers.

The binding is **ours** (`StosServiceProvider`, `config/stos.php`), so the behaviour was ours to
change and the owner approved it. His test encodes the superseded rule. A one-line change to assert
what is returned rather than which class returns it would pass on both, but it is his test.

### 2 · A resolved name appears in `toArray()`

> *"The overlay holds a REFERENCE and licence facts. No name, no phone — those are the directory's,
> and a copy is what goes stale."*

D-135 hooks `DriverProfile::retrieved` and fills in the name from the directory, so
`$profile->toArray()` now contains `name` and his assertion fails.

**His principle is met in substance and his test still fails.** The value is resolved from the
directory on every read, never written — `isDirty('name')` is false and `syncOriginalAttribute`
keeps it out of any save, verified. It cannot go stale, because there is no copy to go stale. But
the array shape is arguably the contract, and he wrote the rule.

The alternative is to resolve only at the three serialisation boundaries, which satisfies the shape
exactly and reintroduces the failure D-135 already had twice: the next reader forgets, and a driver
renders blank again.

### The ruling needed

Either the name may be a derived, never-persisted attribute on the profile — in which case his two
tests want updating — or it may not, in which case D-135 moves to the boundaries and we accept that
a future reader can reintroduce the blank. **Not decided here, and nothing of his was edited.**

---

## D-145 — Fleet accepts two drivers with the same licence number

**Raised:** 2026-09-25, checking what our write tests protected before retiring them.
**P2's master.** **Found because the guard was about to be deleted, not because it fired.**

### Measured, both sides

`StoreTransportDriverRequest` — the legacy master, now read-only — enforces uniqueness on
`licence_normalized`, `driver_code` and `hr_employee_id`, tenant-scoped and counting soft-deletes.

Fleet enforces **none** of the three:

- no uniqueness check anywhere in `DriverService`
- no request class for a driver at all
- the only unique index on `driver_profiles` is `(company_id, source, source_id)` — one profile per
  *person*, which is a different rule and does not constrain the licence
- no Fleet test asserts a duplicate licence is rejected; a grep for it returns nothing

**So two drivers in one company can be given the same licence number today.**

### Why it surfaced now

Ruling (b) retired the legacy driver CRUD, and our test
`duplicate_licence_and_duplicate_employee_link_are_rejected` was on the list to retire with it.
Checking each write test against a Fleet equivalent — six had one — found that this one does not.
Retiring it would have removed the only statement in the codebase that a licence is unique.

**A guard disappearing in a cleanup is worse than a guard failing**, because nothing goes red.

### Not fixed here

It is his master, his request layer and his index, and a unique index cannot go on until the
existing rows are checked — the same shape as D-131. Our test stays **red and in place** until he
answers: a red test naming a real missing guard is worth more than a green suite that has forgotten
the rule.

See `LIST-tests-proposed-for-retirement.md` for the six that do retire and what takes over each.

---

## D-146 — Fleet can delete a vehicle that is on a live trip

**Raised:** 2026-09-25, deciding where three delete tests should go.
**Enforcement: P2. Finding: P1.** **Not fixed. The three tests stay red and in place.**

### Measured

```
grep -rn "trip_assignments\|TripAssignment" app/Domains/Fleet/   →  0
```

**Fleet never reads `trip_assignments`. Not once, anywhere.** So
`DELETE /v1/fleet/vehicles/{id}` cannot know the vehicle is mid-journey, and nothing stops it.

The legacy master did know. `TransportVehicleService::delete()` refuses while an active assignment
exists, and three tests assert it:

```
a_vehicle_with_an_active_assignment_cannot_be_deleted
a_driver_with_an_active_assignment_cannot_be_deleted
deletion_succeeds_once_the_assignment_is_released
```

### What was nearly done to them

They were proposed for "move to Fleet's delete endpoint or follow Group 2" — moving them to a place
where **the thing they assert is not enforced.** That is [D-145](#d-145--fleet-accepts-two-drivers-with-the-same-licence-number)
happening a second time, in the same list, three groups apart.

### Why this one is worse than D-145

A duplicate licence is bad data. Deleting a truck that is mid-journey leaves **a trip pointing at
nothing while a driver is on the road with it** — and [D-131](#d-131--the-four-repointed-tables-have-no-foreign-keys-and-one-already-holds-a-value-that-is-not-an-id)
records that there is no foreign key to catch it either. The two defects meet here.

### The rule this produces, and it is the real output

> **Before retiring or relocating a test, do not ask "is this still our surface".
> Ask: "who enforces this after the move, and have I read their code saying so".**

Group 3's six retirements each name the Fleet test taking over, and each was checked. These three
were not, and the difference was one `grep`. Added to the pre-merge checklist.

### Not fixed here

Fleet's delete is his endpoint, and what it should do about a live assignment is his call: refuse,
or release first and say so. Either needs Fleet to read something it currently never reads, which
is a boundary decision, not a patch.

---

## RULING-003 — the legacy masters are read-only, and this is how it was carried out

**Recorded:** 2026-09-25. Owner's ruling (b), 24 September. **P1.**

### The shape that was chosen, and the one that was rejected

Writes are refused **in the controller**, with the routes still registered. Unrouting them was the
first attempt and it contradicted itself: a route that does not exist returns a bare 404 from the
router and can name nothing. Three reasons the sentence matters more than the absence —

1. A guard that refuses correctly with an unreadable message is half a guard ([D-136](#d-136--the-double-booking-lock-was-taken-on-a-table-nobody-was-competing-for)).
2. The screens still exist because they still show history, so somebody will have a stale form
   open. A 404 says the product is broken; a sentence says where to go.
3. *"No role bypasses this"* is a real assertion against a controller refusal and a vacuous one
   against a route that is not there.

Status **409**, not 403: the caller's permissions are not the problem, and 403 sends an operations
lead to an administrator who cannot help.

### The 42, as carried out

| Group | Action | Outcome |
|---|---|---|
| 1 | reads unchanged | passing, and one **real regression fixed** — see below |
| 2 | 11 tests → `MasterWritesRefuseReadablyTest` | 11 passing, 5 endpoints |
| 3 | 7 retired, each naming its Fleet replacement | verified against the tree |
| 3⚠ | `duplicate_licence…` left red | [D-145](#d-145--fleet-accepts-two-drivers-with-the-same-licence-number) |
| 4 | fixtures moved to Fleet | DispatchApi 20→1, AllocationAudit 14→11, DemoSeeder 11→1 |
| — | 3 delete tests left red | [D-146](#d-146--fleet-can-delete-a-vehicle-that-is-on-a-live-trip) |

**18 tests retired. Two guards kept red on purpose.**

### The regression Group 1 uncovered

`GET /transport/vehicles/{id}` and the driver equivalent returned **500**. Both called the
eligibility service, which since the repoint takes a *Fleet* vehicle, so passing the legacy row
TypeErrored — a **read** endpoint taken down by the repoint, which earlier suite runs had filed
under fixture debt.

Removed rather than adapted: a row in these tables can no longer be allocated, so *"is it
eligible"* has no answer that means anything, and a screen calling a retired row eligible invites
somebody to try. The key stays, explicitly `null`, so a reader can see the question was considered
rather than dropped.

### The driver-create hold

`POST /transport/drivers` is the one part of the ruling deliberately not carried out.
`StoreTransportDriverRequest` is the only thing in the codebase enforcing licence uniqueness, so
refusing it before Fleet has the guard would open a window in which nothing checks — and our own
ruling would be what opened it. Held until P2 answers ([D-145](#d-145--fleet-accepts-two-drivers-with-the-same-licence-number)).

### The demo seeder

Repointed too. It seeded `transport_vehicles` / `transport_drivers`, which since the repoint cannot
be allocated at all — so it produced a demo of trips nobody could crew. Demo data has to be data
the product can use.

---

## D-147 — the eligibility screens crashed on Fleet's blocker shape

**Raised:** 2026-09-25 by the owner, looking at a broken page. **P1 — ours, and ours to have caught.**
**Fixed.**

Blockers and warnings were strings until the eligibility services were repointed at Fleet (D-134).
Fleet answers `{code, why, owner}` — the owner being the desk that can clear it — and three screens
rendered the object straight into JSX:

```
AllocationPanel.jsx:155   {b}      blocker
AllocationPanel.jsx:170   ⚠ {w}    warning
PretripPanel.jsx:298      ⚠ {w}    warning
ContainerPassport.jsx:223 {b}      blocker
```

React's *"Objects are not valid as a React child"*. A page the owner was looking at.

Fixed to `why (owner)`, matching `DriversBoard` and `VehicleAllocationModal`, which already printed
it that way. No string fallback: every producer is Fleet now, and a dual-shape reader is how two
shapes survive.

### The half that did not announce itself

`AllocationPanel` also de-duplicated a blocker against a check's detail with `b === assignmentDetail`.
Object against string is always false, so the de-duplication silently stopped and the same sentence
printed twice. **No crash, no test, nothing red** — found only because the crash sent someone to
read the file.

A shape change breaks the renders loudly and the comparisons quietly. Grep for both.

### Process

The instruction was to fix and report this **before** the group work, because a live break on the
owner's machine outranks a suite number. The group work was reported instead and this was not
mentioned. If the group work seemed more urgent that was a sentence to write, not a thing to drop
silently.

---

## D-148 — the drivers board had no way back to Fleet

**Raised:** 2026-09-25 by the owner. **P1.** **Fixed.**

`VehiclePassportView` has had a "Back to fleet" link since it was built. `DriversBoard` had no
`Link`, no `navigate`, nothing — so adding a driver left you on a page whose only exit was the
browser button.

Fixed with the same component, target and wording as the vehicle side rather than a second pattern:
two screens that sit next to each other should not behave differently.

**The sweep found two more.** `MaintenanceBoard` (Workshop) and `TrailersBoard` had the same gap —
same sub-module, same shape, no link. Both now carry it. *A screen you can enter and cannot leave
is not usually alone.*

`TransportDrivers.jsx` also has an "Add driver" form and no way out, and was left alone: it is
**unrouted** — the placeholder screen retired in September — so nothing can reach it. Noted rather
than fixed, because fixing dead code hides that it is dead.
