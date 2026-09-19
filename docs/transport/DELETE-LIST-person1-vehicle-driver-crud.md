# Mohammad — the delete list, in two phases

**From:** Shivam (Person 2 — Fleet) · **2026-09-19**
**Answers:** your Q8 confirmation · **Closes:** D-100 blocker (b), and sets up (c)

---

## First — (b) is closed, and I owe you an apology for its shape

You checked `FleetResourceGateway` in the code and found one method. You were right, and the
reason is my fault: my implementation carried three, but I never put the other two **on the
interface**, so from your side the seam genuinely had one door.

Fixed. `FleetResourceGateway` now declares:

| Method | Vehicle becomes |
|---|---|
| `markDispatched($trip, $vehicleId, $driverId, $tenantId, $actor)` | `ALLOCATED` |
| `markDeparted($trip, $vehicleId, $tenantId, $actor)` | `IN_TRANSIT` |
| `markReleased($vehicleId, $driverId, $tenantId)` | `AVAILABLE` |

`PendingFleetResourceGateway` implements all three too, so nothing breaks if the binding is ever
absent. Fleet's implementation is already bound and already answers them.

**Your ruling on Q2 is what it is built to.** `markDeparted()` is meant to be called on the SAME
event as your "Record departure" — one act, both objects, no second mechanism. Not the dispatch
button. And telemetry prompts rather than decides: a truck that has moved with no departure
recorded raises an anomaly, it does not write an operational state from an inference. That is
**T-55** on my side and it is parked with the rest of the hardware work until devices exist.

---

## Phase 1 — safe to delete today

Every file below has **zero inbound references** except the route file it is registered in, which
goes with it. I checked each one in the code rather than assuming.

### Backend — controllers, requests, routes

```
app/Http/Controllers/Api/Transport/TransportVehicleController.php
app/Http/Controllers/Api/Transport/TransportDriverController.php

app/Http/Requests/Transport/StoreTransportVehicleRequest.php
app/Http/Requests/Transport/UpdateTransportVehicleRequest.php
app/Http/Requests/Transport/TransitionTransportVehicleRequest.php
app/Http/Requests/Transport/StoreTransportDriverRequest.php
app/Http/Requests/Transport/UpdateTransportDriverRequest.php
app/Http/Requests/Transport/TransitionTransportDriverRequest.php
```

…plus the 18 route lines in `routes/transport.php` matching
`api/transport/vehicles*` and `api/transport/drivers*`.

### Frontend — the four unrouted pages and their two forms

```
frontend/src/modules/transport/pages/TransportVehicles.jsx
frontend/src/modules/transport/pages/TransportVehicleDetail.jsx
frontend/src/modules/transport/pages/TransportDrivers.jsx
frontend/src/modules/transport/pages/TransportDriverDetail.jsx

frontend/src/modules/transport/components/VehicleForm.jsx
frontend/src/modules/transport/components/DriverForm.jsx
```

…and the four now-dead `lazy()` imports in `routes.jsx` that point at those pages. They are
imported but never rendered, which the build tolerates silently.

### ⚠️ NOT on this list — you were right, and it is worse than you thought

```
frontend/src/modules/transport/components/MasterFormFields.jsx     ← KEEP
```

You named four panels. It is **seven**, plus the two forms that do go:

`AdvancesPanel` · `AllocationPanel` · `CollectionPanel` · `DispatchPanel` · `DocumentsPanel` ·
`PretripPanel` · `TripDocumentsPanel`

Deleting it with the forms takes out advances, collections and trip documents as well as the
three you listed.

### What replaces each thing before you delete it

| Retiring | Replacement | Built |
|---|---|---|
| `POST/PUT /api/transport/vehicles` | `POST/PUT /api/v1/fleet/vehicles` | ✅ |
| `PATCH /api/transport/vehicles/{id}/status` | `PATCH /api/v1/fleet/vehicles/{id}/status` | ✅ **T-56** |
| `POST /api/transport/vehicles/{id}/documents` | `POST /api/v1/fleet/vehicles/{id}/documents` | ✅ **T-57** |
| `…/documents/{doc}/renew` | `…/documents/{document}/renew` | ✅ |
| `GET /api/transport/vehicles/status-counts` | `GET /api/v1/fleet/vehicles` returns `counts` | ✅ |
| `GET/POST /api/transport/drivers` | `GET /api/v1/fleet/drivers` | ✅ |
| driver documents | **not yet** — T-43 | ⬜ |

**One gap, stated plainly:** driver documents are not absorbed yet. If anything is filing them
through your endpoint today, that half of the driver controller has to stay until T-43 lands.
If nothing is, it can go with the rest.

---

## Phase 2 — after allocation repoints, not before

These still have real inbound references, so deleting them now breaks working code:

| File | Referenced by |
|---|---|
| `Services/Transport/VehicleEligibilityService.php` | `AllocationService`, `PretripService`, `PretripCheckKey` |
| `Services/Transport/DriverEligibilityService.php` | same |
| `Services/Transport/TransportVehicleService.php` | 17 places |
| `Services/Transport/TransportDriverService.php` | 18 places |
| `Models/Transport/TransportVehicle.php` | 30 places |
| `Models/Transport/TransportDriver.php` | 31 places |

The order is the one you already have: repoint `AllocationService` and `PretripService` onto
`FleetService::getEligibleVehicles()` and `DriverService::eligible()`, run a read-only week, then
these become deletable with the tables.

**`getEligibleVehicles()` returns a `score` per candidate** — that is demonstration step 4's
missing number. It is already computed; the picker simply is not reading it yet, because it is
still querying your tables. So step 4 and blocker (c) close together, with no new code on either
side.

---

## And (c) — zero rows

Still true, and both halves of the fix now exist:

```bash
php artisan migrate                              # safe since D-109 was disarmed
php artisan stos:reconcile-fleet                 # what needs a human, and why
php artisan stos:repoint-trip-fleet-refs         # dry run, writes nothing
php artisan stos:repoint-trip-fleet-refs --apply # at the moment your readers switch
```

The repoint is deliberately not in the migration any more, and deliberately not automatic: it is
only safe at the exact moment your readers move, which is your call and not a deployment's.

---

## On Q7 — done, and I took the direction the spec gives

The owner's ruling (spec 12.S11) splits it: **database enums and state-machine states are
UPPERCASE; API blocker codes and machine reasons are lowercase snake_case.** So both halves of
what your board consumes are now lowercase:

```
blockers[].code   on_another_trip · broken_down · telemetry_stale · driver_license_expired
flags[]           service_overdue · service_due_soon · driver_unavailable · no_driver_assigned
```

`DRIVER_LICENSE_EXPIRED` and `SERVICE_OVERDUE` are gone. You will not have to remember which case
a given field uses, because there is only one.

The internal ones you left to me — genset, tyre, workshop job card — are still lowercase and are
database enums, so by the same rule they should be uppercase. That is **T-58** and it is mine.

---

## Step 8 is green

Three `record()` calls, as you said, and nothing needed from you. `gps.activated`, `genset.on`,
`genset.off`, `temperature.reading` and `temperature.excursion` now reach `trip_events`.

One judgement I made that you should know about, because it is visible on your screen: **the
timeline publishes on CHANGE, not on every ping.** A tracker reports every couple of minutes, and
several hundred identical position lines would bury the four events somebody actually reads a
journey for. The complete trail stays in `telemetry_records`; the timeline is the story. Genset
events fire on transition, temperature on a move of half a degree or more, position on the first
fix of the trip.

`occurredAt` carries the device's clock, as your contract asks.

**A ping with no live trip is dropped** — a truck idling in the yard has nowhere to put it, and I
would rather drop it than invent a destination. If you want a vehicle-scoped timeline later, that
is yours to define and I will write to it.
