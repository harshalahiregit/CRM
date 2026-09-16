# Transport OS — Team Contracts

Three of us, two weeks. This file exists so nobody builds the same thing twice.
It was written after that had already happened once: SNG-TRN-028 got built on two
branches in parallel because I looked for transport code in my own working tree,
found none, and did not run `git log --all`. Both registered the middleware alias
`transport.permission` with different arities, which would have thrown
`ArgumentCountError` on every gated route the moment they met in master.

**Before starting anything: `git log --all --oneline -- 'backend/**/Transport/*'`.**

**Update this by PR, in the same change as the work.** A WhatsApp message is not a
record.

| | Owner | Code lives in |
|---|---|---|
| P1 | Raza — Commercial, Orders, Consignment, Container, Operations, Dispatch, Trip | `backend/app/**/Transport/` |
| P2 | Shivam — Fleet, Vehicles, Drivers, Assets, Telemetry | — |
| P3 | Zafar — Documents, Billing Readiness, Finance, Compliance, Quality/CAPA, Intelligence | `backend/app/**/Transport/` |

Structure follows what P1 already built: `app/Models/Transport`,
`app/Services/Transport`, `app/Http/Controllers/Api/Transport`,
`app/Support/Transport`. Not a package. One structure, not two.

## Defect numbering

**P1's `docs/transport/registry-defects.md` is the list.** It runs to D-54 and
predates anything here. New findings get a D-number there, not a new scheme in
this file. Where the two overlapped, the D-number wins.

---

## 1. Who owns what

Owner = the only person who writes migrations, models or endpoints for it.
Everyone else reads through a service or an event. Table ownership is the Owner
column of Step 11's DB_Registry, read from the XLSX.

| ID | Table | Step 11 owner | Dev |
|---|---|---|---|
| DB-001 | `transport_orders` | Transport Product | P1 |
| DB-002 | `transport_trips` | Transport Product | P1 |
| DB-003 | `trip_assignments` | Transport Product | P1 |
| DB-017 | `transport_customers` | CRM/Transport | P1 |
| — | `transport_consignments`, `transport_containers`, `consignment_containers` | ruled in, see D-39/D-40 | P1 |
| DB-004 | `vehicles` | Fleet | P2 |
| DB-005 | `drivers` | Fleet | P2 |
| DB-006 | `trip_costs` | Finance Control | P3 |
| DB-007 | `trip_advances` | Finance Control | P3 |
| DB-008 | `trip_expenses` | Finance Control | P3 |
| DB-009 | `trip_documents` | Document | P3 |
| DB-019 | `transport_documents` | Document | P3 |
| DB-010 | `trip_exceptions` | **Control** | P1 — see §2 |
| DB-011 | `trip_risks` | Risk | P3 |
| DB-013 | `trip_collections` | Collections | P3 |
| DB-014 | `trip_settlements` | Finance Control | P3 |
| DB-015 | `trip_profit_snapshots` | Intelligence | P3 |
| DB-020 | `transport_policies` | Governance | P3 |
| DB-012 | `trip_bills` | **Accounts** | read only, all of us |
| DB-016 | `transport_rates` | Commercial | unassigned |
| DB-018 | `transport_suppliers` | Supplier | unassigned |

Endpoints and events follow the same rule — Step 11's API_Registry and
Event_Registry, resolved **by name**, never by the number a ticket prints.

---

### 1a. Vehicles and Drivers are P1's PLACEHOLDER, not P1's property

`transport_vehicles` and `transport_drivers` are **DB-004 and DB-005 — Fleet, owned by P2** under
TM-001 §8. The table above is unchanged and remains correct.

What is live on master today is a **temporary P1 implementation**, held deliberately and with an
agreed end. It is recorded here so it is not mistaken for ownership by anyone reading the code.

**Why it exists.** Trip and Transport Order cannot be demonstrated without vehicles and drivers.
A trip with nothing to allocate proves nothing — no allocation panel, no eligibility refusal, no
dispatch gate, no "back in 2 days" sentence. Until P2 ships Fleet, this is what keeps the demo
chain walkable end to end.

**When it ends.** When P2's Fleet module merges. P1's version is then **removed, not merged with
and not reconciled.** P2 builds their own; they do not extend ours, and they do not need our
permission or a migration plan to replace it. Agreed in advance, so it is not a negotiation at
merge time.

**Who removes it.** P1 — in the same PR that brings P2's Fleet in, or immediately after.

**The rule that goes with it:** no further investment. No new features, no refactors, no design
polish on those four screens. They are fixed only if they break the demo. Hours spent on code
scheduled for deletion are hours taken from Container 360.

#### What comes out, exactly

| | Files |
|---|---|
| Migrations | `2026_12_16_000004_create_transport_vehicles_table.php`, `2026_12_16_000006_create_transport_drivers_table.php` |
| Models | `TransportVehicle.php`, `TransportDriver.php` |
| Services | `TransportVehicleService.php`, `TransportDriverService.php` |
| Controllers | `TransportVehicleController.php`, `TransportDriverController.php` |
| FormRequests | `Store`/`Update`/`Transition` × `TransportVehicleRequest`, `TransportDriverRequest` (6) |
| Enums | `VehicleStatus`, `VehicleOwnership`, `DriverStatus`, `DriverAvailability`, `DriverComplianceStatus` |
| Routes | the `/vehicles` and `/drivers` groups in `routes/transport.php`, and their `TransportPermission` keys |
| Pages | `TransportVehicles.jsx`, `TransportVehicleDetail.jsx`, `TransportDrivers.jsx`, `TransportDriverDetail.jsx` |
| Components | `VehicleForm.jsx`, `DriverForm.jsx` |
| Nav | the Vehicles and Drivers entries in `TransportLayout.jsx` and `Sidebar.jsx`, and their routes in `app/routes.jsx` |

#### What does NOT come out — the seam

These reference a vehicle or a driver and are **P1's or P3's**, so they are rewired to P2's Fleet
rather than deleted. Anyone doing the removal must read this row before starting:

| File | Why it stays |
|---|---|
| `frontend/.../components/MasterFormFields.jsx` | **shared** — also imported by `PretripPanel`, `AllocationPanel`, `DispatchPanel`, `DocumentsPanel`. Deleting it with the forms breaks four panels that have nothing to do with Fleet |
| `TripAssignment.php` | P1. `vehicle_id` / `driver_id` are P1 columns and stay |
| `transport_trips.vehicle_id` / `.driver_id` | P1 columns. Nullable, no FK, by the team convention for a shared entity that does not exist yet |
| `AllocationService`, `VehicleEligibilityService`, `DriverEligibilityService` | allocation scoring is TM-001 §9 — **P2's domain**, currently built by P1. Handled as its own handover, not as part of this one |
| `ResourceCommitmentService` | P1. Reads `trip_assignments` + `transport_trips` only — no Fleet table — so it survives the swap untouched |
| `TransportDocumentService` | P3 |

---

## 2. Settled

| Ticket | Owner | State |
|---|---|---|
| 001, 003, 004, 006, 007, 009, 010 | P1 | built — `3fb9337d` |
| 013 Transit & Exceptions | **P1** | `2a9883c3`, vocabulary + schema. Step 11 gives DB-010 to *Control*, and "Execution" is OPS. It is his. |
| 028 Permission Matrix | **P1** | built — `App\Http\Middleware\EnsureTransportPermission`, `transport.permission:<key>` |
| 027 Immutable Audit | **P1** | `TransportAuditLog` + `RecordsTransportAudit` exist |
| Consignment / Container | P1 | **RULED 12 Sep** — D-39 canonical entities, D-40 container master + association, D-41 LR/DO stay documents |

CAPA has no SNG-TRN ticket at all. Quality is P3, so the corrective-action half
of the exception lifecycle is mine — but it needs a ticket before it can be
built. Goes in P1's `REQUEST-step12-missing-tickets.md`.

---

## 3. What we owe each other

| # | From | To | What | Status |
|---|---|---|---|---|
| C-01 | P3 | P1 | an object with blocking reasons, not a boolean | **already exists — see below** |
| C-02 | P3 | P1 | `TransportDocumentEntity::CONSIGNMENT` in `ALL` and `ACTIVE` | **done** — `cdb3d0ad`, branch `zafar/transport-p3` |
| C-03 | P3 | P1 | `TransportDocumentService::entityTypeFor()` — a `TransportConsignment` arm | **done** — same commit |
| C-04 | P3 | — | `delivery_order` into ENUM-006 (approval in `REQUEST-person3-document-entity.md`) | **done** — same commit |
| C-05 | **P2** | P1 | `FleetResourceGateway::markDispatched()` — see below, this was mis-routed to P3 | not started |
| C-06 | P2 | P3 | fuel, urea, tyre, maintenance, FASTag costs → `trip_costs` | not started |
| C-07 | P1 | P3 | EVT-012 `TripClosed` | needed for 018, **not** for 011/012 |

### C-01 — do not build a second one

The object-with-reasons shape P1 asked for is already in the codebase and
already wired:

```php
DriverEligibilityService::evaluate($driver, $trip, $tenantId)   // and Vehicle…
// → { subject, eligible, checks: [{key,label,required,passed,detail}], blockers, warnings }
```

`EligibilityVerdict::make()` builds it, `compliance_status` is one of the checks,
and each check carries its own `required` flag read from policy — so CMP §20's
"blocking must be configurable" is a settings change, not a deploy. Compliance is
also already **re-derived at dispatch**, not read from the stored pre-trip row:
`DispatchService::assertDispatchable()` → `PretripService::revalidate()`, which
reports `lapsed` separately from `blockers` so "it never passed" and "it passed
and has since expired" are distinguishable.

Nothing to add. A new `ComplianceGate` would have been the second duplicate in
two days.

### C-05 — the stub is P2's, not P3's

`PendingFleetResourceGateway` is what dispatch currently runs on, and its own
TODO reads `TODO(Person 2 / Fleet)`. It is about **writing** vehicle and driver
status when a trip departs (BRW-050: vehicle → In Operation, driver → On Trip),
and those tables are DB-004 and DB-005 — Fleet. Shivam implements
`markDispatched()`. It is not a compliance interface and not P3's.

Also flagged there: `AllocationService` (SNG-TRN-009, already merged) writes both
tables directly, predating the split. Known, not fixed, and should move behind
the same gateway when P2 supplies it.

### P1's branch already conflicts with master

`origin/feat/p1-consignment-container` vs `origin/master` conflicts in three
files — `frontend/src/components/layout/Sidebar.jsx`,
`frontend/src/components/layout/sidebarSection.js`,
`backend/bootstrap/providers.php` — because SIRE, the vendor screens, the medical
portal and the HR rail regrouping have all landed in master's navigation since
that branch forked. His to resolve, and better resolved before the branch grows.

**Correction on C-06.** DEP-003 and DEP-004 require only that a trip *exists*
(SNG-TRN-007, built). 011 Advances and 012 Costs are therefore **not blocked** —
an earlier version of this file said they were, and that was wrong. Closure
matters at 018 Profitability, which is after 015 and 017.

### C-01, the shape

Returning true/false forces the caller to invent the reason, and the reason is
what the screen has to show. BRWM §70 asks a blocked action to say what is
blocked, why, and who can resolve it.

```php
interface ComplianceGate
{
    public function statusFor(int $tenantId, int $vehicleId, int $driverId): ComplianceStatus;
}

final class ComplianceStatus
{
    public bool $compliant;
    /** @var ComplianceBlock[] each: subject, requirement, state, expired_on, owner */
    public array $blocks;
    public bool $overridable;   // false where policy forbids it outright
}
```

So dispatch can render "Vehicle fitness expired 14 Aug — Fleet" without knowing
anything about how compliance works.

---

## 4. Open, and who is waiting

Cross-referenced to `registry-defects.md` where a D-number already exists.

| Problem | Blocks | Escalation |
|---|---|---|
| **Step 11 still has zero occurrences of container or consignment** across all 14 sheets, though D-39/D-40 ruled the entities in. The ruling was an architecture approval; the canonical registry has not been written back. | traceability (DOD-014) | registry CHG |
| **No mapping from a CRM account to Step 11's nine roles** (CEO/Owner, Operations, Dispatcher, Accounts, Approver, Driver, Customer, Supplier, Admin). `users.role` is an account type, `users.internal_role` is a `staff_roles` slug, neither is that list. **This decides who may approve advances and expenses.** | every gated action | `CLARIFICATION_REQUIRED` |
| **PERM-013 marks Admin `Y*`**, footnote not in the package | registry modification | `CLARIFICATION_REQUIRED` |
| **PERM has 13 rows and no dispatch, pre-trip, override, waiver or document-verify row.** Deny-by-default means refused, not unspecified. | 009, 010, waivers | `SECURITY_REVIEW_REQUIRED` |
| **A grant is not a boolean.** PERM-001 gives Driver `Own` and Supplier `Assigned`. A gate answering true, with the caller then querying every row, hands one driver the whole tenant. | any list endpoint | design fix in P1's gate |
| **My domain's states are unregistered** — STOS-DOC 18 document statuses, STOS-FIN 14 invoice, STOS-QC 14 + 11 CAPA, STOS-CMP 10 + 6. Step 11 registers none. CLA-005 is "blocker if missing: YES". | 013 CAPA, 014, 015, 016 | `CLARIFICATION_REQUIRED` |
| **Step 12 cites IDs that do not exist** — `FRS-P0-*` (real: `TRP-P0-*`), `BR-001…029` (real: `BR-P0-001…020`). Its Traceability sheet is placeholder text. The Authority Register carries this as an unclosed OPEN ITEM: *"replace placeholder/legacy IDs with canonical Step 11/12 IDs before handing the package to developers."* | DOD-014 | `CLARIFICATION_REQUIRED` |

### Confirmed against the XLSX, not the PDF

P1 asked whether two of his findings were PDF-truncation artifacts. Both stand —
checked across all fourteen sheets of Step 11:

- **D-50, `container_type` vocabulary** — zero hits for `container_type`, `20ft`,
  `40ft`, `ISO 6346`, "high cube". Zero hits for "ISO 6346" anywhere in the
  extracted package.
- **D-52, temperature-critical marker** — zero hits for reefer, temperature or
  genset in Step 11.
- **ENUM-006 verbatim**: `lr|ewaybill|invoice|pod|driver_doc|vehicle_doc|insurance|permit|fitness|other`. Ten values, `delivery_order` absent. Confirms C-04.

The PDF warning was about registry *tables* column-wrapping and truncating enum
value lists — it does not manufacture content that is absent from the XLSX. Where
the XLSX says nothing, nothing is there.

---

## 5. Rules

1. **Step 9 > Step 10 > Step 11 > Step 12 > Step 13 > everything else.** The
   STOS-* suite and Folder_06 are reference. STOS-AIC §3 gives a different
   hierarchy — ignore it; `00_READ_ME_FIRST` governs.
2. **Read the XLSX in `Folder_00_READ_FIRST`, not the PDFs.**
3. **Resolve registry references by name, never by the number a ticket prints.**
   SNG-TRN-011 cites `DB-010`, which is `trip_exceptions`; advances are `DB-007`.
4. **No new table, field, API, state, event or permission without a Step 11 entry
   or a recorded ruling.** If you need one, it goes in `registry-defects.md`.
5. **Transport never writes ledger lines.** Emit the accounting event.
6. **Money is `DECIMAL(18,2)` with bcmath.**
7. **Every branch, PR and commit names its SNG-TRN ticket.**
8. **Don't hand Claude the whole package** — STOS-AIC §103. Per ticket: Step 9 +
   Step 10 + the Step 11 XLSX + the one ticket + the one module spec.
9. **Audit against the package, not yourself.** Step 12's Acceptance_Criteria and
   Step 13's DOD-001…015 are the checklist.
