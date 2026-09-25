# Does the code match the flow? — a check against the running system

**By:** Shivam (Person 2 — Fleet, Asset & Telemetry) · **2026-09-23**
**Checked against:** the flow map in `STOS-REAL-FLOW-AND-DEV2-WORKLIST.md`, and the code as it
stands on `master` at `678434d3`.

Read §1 if you read nothing else. **Short answer: the process is right, three wires are not
connected, and one of them is the thing the owner asked about.**

---

## 1 · The headline — Fleet's allocation is not what a dispatcher sees

The owner asked why vehicles and drivers on a trip do not come from Fleet. It is still true, and
the reason is narrower and more fixable than "the repoint has not run".

**Two allocation surfaces exist and only one of them is reached from a trip.**

| Screen | Calls | Backed by | Sees Fleet? |
|---|---|---|---|
| Trip → Allocate (the real one) | `GET /transport/trips/{trip}/candidates` | `VehicleEligibilityService` / `DriverEligibilityService` → **`transport_vehicles` / `transport_drivers`** | ❌ no |
| Fleet → Overview | `GET /v1/fleet/vehicles/eligible` | `VehicleAllocationService` → **`vehicles`** | ✅ yes |

So everything Fleet has built for allocation — the score, the blockers that name the desk that can
clear them, the capacity match added this week, the driver licence and medical verdicts — **is not
in front of the person allocating a trip.** They are looking at the placeholder masters.

This is D-100's reader swap and it is Person 1's change. Nothing is missing on Fleet's side: both
services are built, tested and answer in the shape his panel already renders.

```php
FleetService::getEligibleVehicles($companyId, $vehicleType, $context)  // + score, blockers, flags
DriverService::eligible($companyId, $filters)                          // eligible[] + excluded[]
```

**Until that swap, a vehicle added in Fleet cannot appear on a trip, and no amount of Fleet work
changes that.** It is one screen's data source, not a redesign.

---

## 2 · The flow map has 11 trip states. The code has 16.

The map is a simplification, and using it as the build target would miss real states. Actual
`TripStatus::ALL`:

```
draft · viability_pending · approved · allocated · PRETRIP_OK · dispatched · in_transit
· ARRIVED · delivered · POD_PENDING · pod_verified · billable · billed
· collection_pending · SETTLEMENT_PENDING · closed
```

The five in capitals are in the code and **not** in the flow map. Two of them matter to us:

- **`pretrip_ok`** sits between `allocated` and `dispatched`. It is the Driver App's "Vehicle &
  Pre-Trip Readiness" screen, and it is a real gate — `assertDispatchable()` re-derives BRW-046 at
  release. A map that goes straight from allocated to dispatched hides the step our readiness data
  feeds.
- **`arrived`** sits before `delivered`. Distinct from it, and telemetry is what would evidence it.

**Nothing to fix in the code — the code is the richer one.** The correction belongs in the map, and
I have not edited it into Zafar's document because it is his.

---

## 3 · `markDeparted()` is built on both sides and called by neither

The agreed design (Q2 ruling, recorded in `DELETE-LIST-person1-vehicle-driver-crud.md`) was: the
departure event moves **both** objects — one act, no second mechanism.

- `FleetResourceGateway::markDeparted()` — **declared** ✅
- `TransportFleetResourceGateway::markDeparted()` — **implemented**, sets `IN_TRANSIT` ✅
- `PendingFleetResourceGateway::markDeparted()` — **implemented** ✅
- `DispatchService::recordDeparture()` — moves the trip to `in_transit`, audits it, records
  `trip.departed`… and **does not call the gateway** ❌

So a departed truck stays `ALLOCATED` for the whole journey. The vehicle state `IN_TRANSIT` is
never written by anything except a direct call nobody makes.

**What this does and does not break.** Double-allocation is still prevented, because
`Vehicle::ON_TRIP_STATES` covers both `ALLOCATED` and `IN_TRANSIT`. What is lost is the
distinction the state exists for: an allocated truck can still be swapped, a departed one is a
recovery problem, and today nothing can tell them apart.

One line in `recordDeparture()`, inside the transaction it already has. Person 1's file.

---

## 4 · Two doors to the same master are still open

The owner's rule — one entry point per master, no duplication — is not met yet:

```
POST /api/transport/vehicles     → transport_vehicles   (retiring)
POST /api/v1/fleet/vehicles      → vehicles             (the master)

POST /api/transport/drivers      → transport_drivers    (retiring)
PUT  /api/v1/fleet/drivers/{source}/{person}            (the master)
```

Both pairs are live. **The four old screens are NOT routed in the frontend** — they are imported by
`routes.jsx` and never rendered, which the build tolerates silently — so this is an API-level
duplication today, not two buttons a user can press. That limits the damage and does not remove it:
anything scripted against the old endpoint writes to the wrong table.

**This is Phase 1 of the delete list, and it was blocked on T-43 driver documents, which landed on
22 September.** Nothing blocks the deletion now.

---

## 5 · What does match, and is worth stating plainly

| Flow claim | Reality |
|---|---|
| Telemetry reaches the shared timeline | ✅ `gps.activated`, `genset.on/off`, `temperature.reading`, `temperature.excursion` in `trip_events` |
| GPS is not a screen — device → ingest | ✅ `POST /v1/telemetry/ingest`, token-authenticated per device |
| The timeline is the story, the trail is `telemetry_records` | ✅ publishes on change only |
| `markDispatched` → vehicle `ALLOCATED` | ✅ called by `DispatchService` |
| `markReleased` → vehicle `AVAILABLE` | ✅ called by `AllocationService` |
| Twelve canonical events EVT-001…012 | ✅ real event classes, numbered and documented |
| Fleet never writes a trip | ✅ nothing in `app/Domains/Fleet` writes `transport_trips` except the timeline publisher, through P1's recorder |
| One vocabulary for states | ✅ as of T-58/T-42 every Fleet enum is UPPERCASE |

**The process is sound.** Every hop the map describes exists in the code, the events are real and
numbered, and no section is writing into another's tables. The gaps above are wires, not design.

---

## 6 · So are we going the right way?

Yes, with one correction to how we have been talking about it.

**We have been building Fleet's answers correctly and nobody is asking them the question yet.**
Eligibility, capacity, licence, medical, blockers, score — all built, all tested, all reachable at
`/v1/fleet/*`, and the trip screen calls a different endpoint. That is not wasted work; it is work
whose last inch belongs to someone else, and the right response is to say so clearly rather than to
build more of it.

**The three wires, in the order that unblocks the most:**

1. **The reader swap** — the trip candidate list onto `FleetService::getEligibleVehicles()` and
   `DriverService::eligible()`. This is what the owner actually saw. *(P1)*
2. **`recordDeparture()` → `markDeparted()`** — one line, makes `IN_TRANSIT` mean something. *(P1)*
3. **Delete list Phase 1** — closes the second door to both masters. Unblocked since 22 September.
   *(P1)*

**On our side the honest next steps are the ones that do not depend on those:** the genset serial
column (T-05), `generator_status` alignment (T-06), the fuel hop (T-17/T-19), and trailers (T-54),
which is the one CLP §5 genuinely needs and cannot be faked with a `vehicle_type` value.

I am not blocked, and I would rather not add a fourth Fleet feature nobody can reach before the
first wire is connected.
