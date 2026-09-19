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

> ⚠️ **Known gap, deliberately not filled in.** The ruling describes **13 stages**; nine were
> supplied. Stages 10–13 are **not recorded here and must not be guessed** — inventing them is
> exactly the failure mode this document exists to stop. Ask the owner before building anything
> that would sit after stage 9.

---

## 2. Master data — one owner each

**The single-owner rule:** every master entity has exactly one owner. Everyone else references
it. Nobody keeps a second copy, and nobody writes to someone else's master.

| Master | Sole owner | Holds | Notes |
|---|---|---|---|
| **Vehicle master** (`vehicles`) | **`STOS-FLEET` — Dev 2** | Registration, chassis, engine, specification, asset status, job cards, physical readiness | Trailers, gensets and tyres follow the same rule |
| **Driver identity / HR** | **Sangoe HR / CRM** (platform core) | Name, phone, address, employment contract | Transport never stores personhood |
| **Driver operational profile** (`driver_profiles`) | **`STOS-FLEET` — Dev 2**, via the `DriverDirectory` adapter | Licence number, class, expiry; duty availability | **Zero demographic columns.** A reference into the CRM directory, never a copy |
| **Vehicle & driver document FILES** | **`STOS-DOC` — Dev 3** | File storage, versioning, OCR, audit history | Fleet holds metadata *references* only |
| **Document validity RULES** | **`STOS-CMP` — Dev 3** | What makes a document valid | Fleet holds the expiry dates it gates dispatch on |
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

## 8. Open questions for the owner

- **Stages 10–13** of the lifecycle — not supplied (§1).
- **Genset / tyre / job-card status vocabularies** — the ruling covered the *vehicle* state
  machine. These are still lowercase and Fleet-internal. Align them or leave them?
- **Blocker codes** (`on_another_trip`, `broken_down`) are lowercase while flags
  (`DRIVER_LICENSE_EXPIRED`, `SERVICE_OVERDUE`) are uppercase. Both cross to Dev 1's board.
  Worth one ruling.
- **Person 3's defect band** (§6).
