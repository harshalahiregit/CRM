# STOS — Process Flow, Ownership & Boundaries

**Status:** AUTHORITATIVE · **Ruled by:** the product owner · **Recorded:** 2026-09-19
**Applies to:** all three developers. Supersedes any assumption made inside a single branch.

---

## 0. Read this first

> **Sangoé Transport OS is ONE system, not three modules that merge.**
>
> This was the misunderstanding that caused the problem this file exists to prevent. Work had
> been done on the assumption that Operations, Fleet and Finance were separate products joined
> by contracts, and that each could hold its own copy of a master. That is wrong. There is one
> Transport OS; each developer owns *parts* of it, and every part must connect to the others.

**The three failures this file exists to stop:**

1. **Duplicate master data** — the same truck, driver or document existing in two tables.
2. **A cracked process flow** — a user completing a step in one module that the next module
   cannot see.
3. **Duplicate entry points** — two screens that both say "Add vehicle", so nobody knows which
   one is real.

**Before building ANYTHING, check §2 and §3.** If the thing you are about to build already has
an owner, you are not building it — you are calling it.

---

## 1. The lifecycle — who owns the screen, who only reads

At every stage **exactly one module owns the screen and the write actions.** Every other module
reads or consumes. Owning a stage does not mean owning the data it reads.

| # | Stage | Screen & write owner | Reads / consumes | What actually happens |
|---|---|---|---|---|
| 1 | **Order intake & booking** | `STOS-COMM` — Commercial (Dev 1) | CRM/Customer, Sales | Creates `transport_orders`: lane, customer, freight, temperature requirement |
| 2 | **Trip creation & viability** | `STOS-OPS` — Operations (Dev 1) | `STOS-FIN` rate cards, Customer | Order → `transport_trips`. `TripEngine`: `draft` → `approved` |
| 3 | **Resource allocation** | `STOS-OPS` (Dev 1) *executes*<br>`STOS-FLEET` (Dev 2) *recommends* | `STOS-CMP`, HR | `FleetService::getEligibleVehicles()` scores candidates; Dev 1 assigns. `approved` → `allocated` |
| 4 | **Pre-dispatch & compliance gate** | `STOS-OPS` (Dev 1) *dispatches*<br>`STOS-CMP` (Dev 3) *owns the gate rule* | `STOS-DOC`, `STOS-FLEET` | Pre-trip checklist + document expiries evaluated. Clean → `allocated` → `dispatched` |
| 5 | **In-transit execution & telemetry** | `STOS-INT` / `STOS-FLEET` (Dev 2) *hardware*<br>Driver mobile (Dev 1/2) *field actions* | `STOS-OPS`, `STOS-QC` | GPS, temperature, genset pings. Fuel (`STOS-COST`) and FASTag. Excursion events |
| 6 | **Delivery & POD capture** | Driver mobile / `STOS-DOC` (Dev 3) | `STOS-OPS`, Customer | Delivery confirmation + POD photo. Consignment → `DELIVERED` |
| 7 | **Billing readiness** | `STOS-DOC` + `STOS-FIN` (Dev 3) | `STOS-OPS`, `STOS-FLEET` | `BillingReadinessEngine` verifies POD, gate slips, recoverable diesel → `BILLING_READY` |
| 8 | **Customer invoicing** | `STOS-FIN` (Dev 3) | `STOS-OPS`, Sangoe Accounts | `billable` → `billed`. Posting event to `PostingService` |
| 9 | **Collections & settlement** | `STOS-FIN` / Accounts (Dev 3) | `STOS-REP` control tower, Operations | Receivables; settles driver advances and vendor payables |
| 10 | **Billing readiness engine** | `STOS-DOC` (Dev 3) | `STOS-OPS`, `STOS-FIN` | Delivery + valid POD + docs complete + rate available + recoverable expenses = `BILLING_READY`. A miss creates a `billing_blockers` row naming the item, owner, SLA and the exact **revenue blocked** |
| 11 | **Financial control & invoicing** | `STOS-FIN` (Dev 3) | `STOS-OPS`, Sangoe Accounts | Consumes `BILLING_READY`. Applies rate cards (freight, detention, toll recovery), drafts the invoice, posts to Accounts |
| 12 | **Receivables & collection** | `STOS-FIN` (Dev 3) | `STOS-REP` | Submission, acceptance, aging 30/60/90, DSO, collection tasks for overdue invoices |
| 13 | **Trip profitability & margin** | `STOS-FIN` / `STOS-REP` (Dev 3) | Control tower, `STOS-FLEET` | `margin = revenue − (fuel + urea + FASTag + driver + maintenance allocation)`. Negative-margin trips trigger management review |

> **Fleet's obligation to stage 13.** Four of the five cost terms are Fleet's and are already
> published to `trip_costs` under C-06: fuel, urea, FASTag tolls and maintenance. **"Maintenance
> allocation" is the open one** — Fleet publishes a workshop cost only when the job card names a
> `trip_id`. Routine servicing is fleet overhead and is deliberately *not* spread across trips,
> because apportioning it is a costing policy for Finance to set, not for Fleet to invent. If
> stage 13 expects an apportioned share, Dev 3 must specify the rule.

---

## 2. Master data — one owner each

**The single-owner rule:** every master entity has exactly one owner. Everyone else references
it. Nobody keeps a second copy, and nobody writes to someone else's master.

| Master | Sole owner | Holds | Notes |
|---|---|---|---|
| **Vehicle master** (`vehicles`) | **`STOS-FLEET` — Dev 2** | Registration, chassis, engine, specification, asset status, job cards, physical readiness | Trailers, gensets and tyres follow the same rule |
| **Driver identity / HR** | **Sangoe HR / CRM** (platform core) | Name, phone, address, employment contract | Transport never stores personhood |
| **Driver operational profile** (`driver_profiles`) | **`STOS-FLEET` — Dev 2**, via the `DriverDirectory` adapter | Licence number, class, expiry; duty availability | **Zero demographic columns.** A reference into the CRM directory, never a copy |
| **Vehicle & driver document FILES** | **`STOS-DOC` — Dev 3** | File storage, versioning, the `UPLOADED → UNDER_VERIFICATION → VERIFIED` workflow | The **authoritative master** for evidence and expiry |
| **Document validity RULES & verified expiry** | **`STOS-CMP` — Dev 3** | Whether a document meets statutory requirements; holds the verified expiry | Fleet never decides validity |
| **Expiry dates on `vehicles`** | **`STOS-FLEET` — Dev 2**, as a *projection* | A read-optimised **cache** for fast allocation queries | **Not a master.** See §4a |
| **Trip lifecycle state machine** | **`STOS-OPS` / `TripEngine` — Dev 1** | `draft → allocated → dispatched → in_transit → delivered → closed` | Fleet never moves a trip |
| **Vehicle asset state machine** | **`STOS-FLEET` — Dev 2** | See §4 | Operations never writes vehicle status directly — it calls the gateway |

---

## 3. API ownership — and what is retired

### Retired: Dev 1's vehicle and driver CRUD

**`/api/transport/vehicles` and `/api/transport/drivers` are RETIRED.** They are **not** kept as
proxies.

They are still live on master as of this writing, which means **a vehicle created through them
lands in `transport_vehicles` — a table Fleet never reads.** Such a vehicle is invisible to
telemetry, fuel, compliance, maintenance and allocation, and nothing warns anybody. This is the
single most dangerous thing currently open in Transport.

### The owned surfaces

| Surface | Owner | Endpoints |
|---|---|---|
| Orders & trips | **Dev 1** | `/api/v1/transport/orders`, `/api/v1/transport/trips`, `/api/v1/transport/trips/{trip}/assign` |
| Vehicle master CRUD | **Dev 2** | `/api/v1/fleet/vehicles` |
| Driver overlay | **Dev 2** | `/api/v1/fleet/drivers` |
| Fleet assets | **Dev 2** | `/api/v1/fleet/gensets`, tyres, workshop, device tokens |
| Telemetry ingest | **Dev 2** | `/api/v1/telemetry/ingest`, `/ingest/batch` |
| Documents, POD, billing, collections | **Dev 3** | `STOS-DOC` / `STOS-FIN` surfaces |

### What Fleet must absorb before the retirement can complete

Dev 1's endpoints are **not a subset** of Fleet's. Retiring them without these first would lose
working features:

- [ ] **Vehicle asset status transitions** — `PATCH /api/v1/fleet/vehicles/{id}/status`
- [ ] **Vehicle documents** — upload + renew, writing into Dev 3's shared `transport_documents`
      store (`entity_type = 'vehicle'`), **not** a new Fleet table
- [ ] **Driver documents** — same, `entity_type = 'driver'` (tracked as T-43)
- [ ] **Status counts** for the grid

Until those exist, **do not delete Dev 1's controllers.** Sequence is in
`D-62-RESOLUTION-PLAN.md`.

---

## 4. The vehicle asset state machine

**Fleet is the sole authority.** Ruled 2026-09-19, closing **T-02** and the vehicle half of
**T-51**, both of which had been blocked on a team answer since M2.

```
AVAILABLE · ALLOCATED · IN_TRANSIT · UNDER_MAINTENANCE
COMPLIANCE_BLOCKED · IDLE · BREAKDOWN · RETIRED
```

**Uppercase, because these strings cross a module boundary.** Dev 1's board and Dev 3's billing
switch on them, and two spellings of one state is how a condition gets tested for and silently
never matches.

**The naming standard, ruled 2026-09-19 (spec 12.S11) — two casings, on purpose:**

| Kind | Casing | Examples |
|---|---|---|
| Database enums & state-machine states | **UPPERCASE** | `AVAILABLE`, `IN_TRANSIT`, `BILLING_READY`, `REEFER`, `VERIFIED` |
| API blocker codes & machine reasons | **lowercase snake_case** | `safety_job_open`, `pod_missing`, `driver_license_expired`, `service_overdue` |

They are different things. A state is what an entity *is*; a machine reason is what a response
*says about* it. Fleet's advisory flags (`service_overdue`) and blocker codes
(`driver_license_expired`) are the second kind and are lowercase.

### 4a. Expiry dates are a projection, not a master

**Ruled 2026-09-19.** The uploaded certificate in `STOS-DOC`, validated by `STOS-CMP`, is the
authoritative source for a document and its expiry. Fleet's five date columns
(`registration_expiry`, `insurance_expiry`, `fitness_expiry`, `permit_expiry`, `puc_expiry`) are
an **operational cache**, kept for fast indexing during allocation queries.

```
STOS-DOC        upload → UNDER_VERIFICATION → VERIFIED
                                  ↓
STOS-CMP        decides statutory validity, holds the verified expiry
                                  ↓
STOS-FLEET      projects the date onto `vehicles`, clearing the dispatch block
```

**Verifying a renewal must clear the gate with no manual date re-entry.** And **editing a date in
Fleet without a verified document behind it is prohibited** by the single-evidence-trail rule.

> ⚠️ **Sequencing risk, open.** Fleet's date fields are currently hand-editable on the vehicle
> form, and that is how every compliance date in the system got there. Removing that input before
> the `STOS-DOC` → `STOS-CMP` → Fleet projection actually runs would leave **no way to record a
> compliance date at all**, and every vehicle would fail the gate. The prohibition therefore
> lands *after* the projection is wired, not before. Tracked as **T-53**.

### Who applies each state — none of them are free-typed

| State | Applied by | Never by |
|---|---|---|
| `AVAILABLE` | Workshop release; trip closure; a person | — |
| `ALLOCATED` | Dispatch, via `FleetResourceGateway` | The vehicle form |
| `IN_TRANSIT` | Dispatch, on departure | The vehicle form |
| `UNDER_MAINTENANCE` | Opening a job card | A person |
| `BREAKDOWN` | Opening a job card **that names a `trip_id`** | A person |
| `COMPLIANCE_BLOCKED` | The `stos:refresh-compliance` sweep | A person |
| `IDLE` | A person | — |
| `RETIRED` | Retiring the vehicle | — |

A person may hand-set only `AVAILABLE`, `IDLE` and `RETIRED`. The rest are consequences of
something happening elsewhere, and letting them be typed would put a truck back on the road
without the check that took it off.

### Two changes that are not just renames

1. **`in_operation` split into `ALLOCATED` and `IN_TRANSIT`.** Fleet had one value for "out on a
   trip". A planner needs the difference: an allocated truck can still be swapped, a departed one
   is a recovery problem. Existing rows became `ALLOCATED` — the weaker, recoverable claim.
2. **`COMPLIANCE_BLOCKED` became a state.** It is still **derived** from the five statutory
   expiry dates — that remains the one truth. The sweep applies and clears it, and only to a
   vehicle that is otherwise free: yanking a truck mid-trip because a PUC lapsed strands a load
   rather than preventing a journey that has already started.

### What is deliberately NOT a state

- **Service due** — a truck past its interval is still roadworthy. `ServiceScheduleEvaluator`
  derives it and it warns. Putting it in `status` would drop the truck out of allocation, so a
  missed oil change would silently take a working truck off the road.
- **Driver licence expiry** — belongs to the *driver*, not the truck. Blocks the driver via
  `DriverService::eligible()`. The vehicle stays fully eligible.

Both follow the same rule: **a condition is not a state.** A condition warns; a state excludes.

---

## 5. Where a user adds things

| Thing | Where | Why |
|---|---|---|
| **Vehicle, trailer, genset, tyre** | **Fleet only** | A vehicle is an operational asset needing immediate links to GPS, genset, compliance expiry and maintenance schedule at onboarding |
| **Customer, HR employee, user** | Their own core module (CRM, HR, Admin) | Shared enterprise entities, not transport assets |
| **Order, trip** | Operations | Dev 1 owns the commercial and operational lifecycle |
| **POD, invoice, collection** | Documents / Finance | Dev 3 |

**There is no generic "Masters area" for transport assets.** Fleet is the single entry point.

> **Implementation note:** the ruling cites a path of the form `/fleet/vehicles/create`. This
> codebase is React Router + JSX under `/app/transport/fleet`, not the Inertia/TSX layout that
> path implies. The *rule* is what binds — one entry point, inside Fleet — not the literal path.
> Do not create a TSX page to match the string.

---

## 6. Defect numbering

Per-person bands, after two developers collided on D-58…D-61 on the same day.

| Range | Owner |
|---|---|
| D-100+ | Person 1 |
| D-200+ | Person 2 |
| Person 3 | to pick a band |

---

## 7. Standing rules for whoever builds next

1. **Check §2 and §3 before writing a line.** If it has an owner, call it — do not rebuild it.
2. **Never create a second entry point** for something a user can already do. If the existing one
   is in the wrong module, move it; do not add a rival.
3. **Never write to another module's table.** If a migration must touch one — as the D-62 data
   move touched `transport_trips` — **say so in the group before it lands**, not in the diff.
4. **A condition is not a state.** Conditions warn, states exclude. Getting this wrong blocks the
   wrong object — it has already happened twice here (driver licence, service due).
5. **Do not invent what the spec does not say.** Stages 10–13 are the live example: they are
   missing, and the correct action is to ask, not to fill the gap plausibly.
6. **Cross-boundary vocabularies are UPPERCASE** and defined once, in the owning module.

---

## 8a. Trailers are their own asset

**Ruled 2026-09-19.** A trailer is **not** a row in `vehicles`. Fleet manages the tractor unit
and the trailer as separate entities, each with its own compliance profile, tyre set and
maintenance record, coupled dynamically through `vehicle_trailer_assignments` — a tractor and a
trailer couple for a trip or an operational period, and the historical association is preserved.

Not built yet. Tracked as **T-54**.

---

## 8b. Standalone operation is retained

The `DriverDirectory` adapter seam (`CrmDriverDirectory` / `StandaloneDriverDirectory`) stays.
Integrated operation inside Sangoe Business OS is the production target, but the seam earns its
keep twice over: tests run without seeding the whole platform CRM schema, and Fleet degrades
rather than returning 500s when the workforce tables are unavailable or in a lightweight field
deployment.

---

## 8c. The dispatch and departure edges

The trip engine (`SM-TRP`, Dev 1) runs `ALLOCATED → DISPATCHED → IN_TRANSIT` as two guarded
steps, and the vehicle follows:

| Trip edge | Fired by | Vehicle becomes |
|---|---|---|
| `ALLOCATED → DISPATCHED` | Operations, once the pre-trip checklist passes | `ALLOCATED` — committed, not yet moving |
| `DISPATCHED → IN_TRANSIT` (`STT-006`) | Driver app **START TRIP**, or telemetry confirming geofence exit | `IN_TRANSIT` |

**Telemetry validates; it does not transition.** A truck that moves without a formal dispatch
raises a **Route / Movement Anomaly exception** rather than being silently promoted into a valid
state — otherwise a stolen or misused vehicle would quietly look like a legitimate trip.

Fleet's side: `markDispatched()` allocates, `markDeparted()` departs. The anomaly detector is not
built yet — tracked as **T-55**.

---

## 9. Open questions for the owner

Everything asked on 2026-09-19 has been answered and folded in above. What remains:

- **Maintenance allocation for stage 13** — does trip margin expect an apportioned share of
  routine servicing, and if so what is the rule? Fleet publishes only workshop costs that name a
  `trip_id`; spreading overhead across trips is a costing policy, not Fleet's to invent (§1).
- **When does the `STOS-DOC` → `STOS-CMP` → Fleet projection go live?** Until it does, the
  prohibition on hand-editing expiry dates cannot be enforced without leaving no way to record
  one at all (§4a, T-53).
