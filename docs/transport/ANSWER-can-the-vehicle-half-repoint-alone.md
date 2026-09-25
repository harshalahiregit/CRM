# Can the vehicle half of the repoint go alone?

**Person 1 · 21 September 2026 · measured, nothing changed.**

**Short answer: no — and the reason is not the ledger or D-118. It is three couplings on our side
that have to move in the same commit as the data, and two of them touch drivers in the same files.
The "vehicle half" saves almost nothing and leaves a state I would not choose deliberately.**

---

## The owner's complaint is real and is a different thing from `--apply`

*"A vehicle Shivam adds in Fleet never appears on a trip."* True:
`VehicleEligibilityService:148` and `DriverEligibilityService:161` read `TransportVehicle::` and
`TransportDriver::` — our placeholder tables.

It is worth separating two jobs that get called "the repoint":

| | |
|---|---|
| **The reader** | which vehicles the picker *offers* |
| **The data** | rewriting existing `vehicle_id` values from our ids to Fleet's (`--apply`) |

They sound separable. **They are not**, and that is the crux: if the picker offers Fleet vehicles,
`assign()` writes a Fleet id into `transport_trips.vehicle_id` while every existing row holds one of
ours. The mixed namespace arrives through the back door, with no ledger recording which is which.
So the picker and the stored ids move together or not at all.

---

## The three couplings, measured

### 1. The status vocabularies disagree, and the check is strict

```
vehicles.status            = "AVAILABLE"     (Fleet)
transport_vehicles.status  = "available"     (ours)
```

`VehicleEligibilityService` gates on
`in_array($vehicle->status, VehicleStatus::ALLOCATABLE, true)` where `ALLOCATABLE` is
`['available', 'idle']` — **a strict comparison**. Point the picker at Fleet today and every Fleet
vehicle reads as *not allocatable*: the list comes back **empty**, and it looks like a considered
eligibility verdict rather than a bug. That is the exact failure shape this project keeps hitting.

*(The drivers happen to agree — `driver_profiles.status` and `transport_drivers.availability` are
both `"available"` — though the column names differ.)*

### 2. The relations point at our model, in both places that matter

```php
TransportTrip::vehicle()    -> belongsTo(TransportVehicle::class, 'vehicle_id')
TripAssignment::vehicle()   -> belongsTo(TransportVehicle::class, 'vehicle_id')
```

Repoint the column without repointing the relation and the trip screen shows a **blank vehicle**
today — and a **different truck** the day the two id ranges overlap. The trip detail payload loads
`vehicle:id,registration_number,...` through exactly this relation.

### 3. The CTD §4 search follow-through breaks silently

`TransportSearchService::vehicle()` resolves a plate in `transport_vehicles`, gets our id, then
looks for trips with that `vehicle_id`. After a vehicle-only apply, a plate search would find the
vehicle and **no journeys** — quietly undoing the "every path leads to the passport" work from last
week.

*(And one of mine: `AllocationService::freeResources()`, shipped today, resolves `vehicle_id`
through `TransportVehicle::find()`. After a repoint it would find nothing and skip freeing the
truck — reintroducing D-119 without a word.)*

---

## The good news, which is real

**Fleet's `vehicles` table carries every column ours does**, except `tenant_id` — Fleet uses
`company_id`. Registration, normalised registration, fleet number, type, capacity, status,
ownership, chassis, engine, GPS device: all present, plus service-interval fields we do not have.

So this is **not a data-model migration**. The eligibility engine's inputs all exist on the other
side. The work is a reader swap, a vocabulary normalisation and a relation repoint — bounded, and
mostly ours.

---

## Why splitting makes it worse rather than faster

A vehicle-only repoint is *expressible* — two columns, two masters, each with a correct relation,
and the ledger records per column. But it leaves `transport_trips.vehicle_id` meaning **Fleet** and
`transport_trips.driver_id` meaning **ours**, in the same row, with nothing but the ledger to say
which is which. Every future reader of either column has to know, and the two are handled together
in the same methods — `assertResourcesFree()`, `freeResources()`, the assignment payload, the
allocation panel.

And the saving is small: couplings 2 and 3 have to be fixed for vehicles anyway, and both touch
drivers in the same files. We would do 80% of the driver work to avoid waiting for the driver data.

**So: we wait for Shivam.** D-118 is one pass on his side — match drivers on the normalised licence
as vehicles match on the plate — and both halves then go in one commit with one ledger.

---

## What I would do meanwhile, if you want it (not started)

All three couplings can be fixed **without repointing a single row**, and each is independently
testable:

1. Normalise the status comparison so the two vocabularies agree.
2. Make the relations and the search resolve through one seam rather than a hard-coded model.
3. A test that fails if any code path resolves a `vehicle_id` against the wrong master.

That turns the eventual `--apply` from a risky step into a small one, and none of it changes
behaviour today. **I have not started any of it** — the instruction was to answer whether it
splits, and it does not.

---

## What is not blocked

Nothing here blocks the owner's actual complaint being *acknowledged* on screen. If Fleet vehicles
should be visible before the repoint lands, the honest interim is to say so where a dispatcher
looks, rather than to half-repoint. That is a one-line decision for you, not a piece of work I
would start unasked.
