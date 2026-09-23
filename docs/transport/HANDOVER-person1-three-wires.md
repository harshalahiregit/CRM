# The three wires, with the actual diffs

**From:** Shivam (Person 2 — Fleet) · **To:** Person 1 (Mohammad Raza) · **2026-09-23**
**Why this exists:** I audited the flow against the code rather than against the map. Everything
Fleet owes you is built. Three connections are missing, all three are in your files, and I would
rather hand you the diffs than a defect number.

None of this is a request for a redesign. Wire 2 is one line.

---

## Wire 1 — the trip's Allocate screen still reads the placeholder masters

This is the one the owner saw. `AllocationService::candidates()` →
`VehicleEligibilityService::candidatesFor()` → `TransportVehicle::forTenant()`. So a vehicle added
in Fleet can never appear on a trip, and Fleet's score, blockers, capacity match, licence and
medical verdicts are not in front of the person allocating.

**The good news, and the reason I am writing rather than logging it:** your `evaluate()` reads
exactly **five fields** off the vehicle, and `vehicles` has all five.

```php
'id' => $vehicle->id,
'registration_number' => $vehicle->registration_number,
'vehicle_type' => $vehicle->vehicle_type,
'capacity_tonnes' => $vehicle->capacity_tonnes,     // exists on `vehicles` — added by the D-62 union
'status' => $vehicle->status,
```

So the swap is a query change, a type widen, and the status vocabulary. Not a rewrite.

### Option A — keep your engine, change its source *(smallest)*

In `VehicleEligibilityService::candidatesFor()`:

```php
-$vehicles = TransportVehicle::forTenant($tenantId)
-    ->when(! $includeIneligible, fn ($q) => $q->allocatable())
+$vehicles = \App\Domains\Fleet\Models\Vehicle::forCompany($tenantId)
+    ->when(! $includeIneligible, fn ($q) => $q->whereIn('status', \App\Domains\Fleet\Models\Vehicle::ALLOCATABLE))
     ->orderBy('registration_number')
     ->get();
```

and widen `evaluate(TransportVehicle $vehicle, ...)` to accept either. **`scopeAllocatable()` is the
trap** — it uses `VehicleStatus::ALLOCATABLE`, which is `['available','idle']`, and Fleet holds
`AVAILABLE` / `IDLE`. That is coupling (a) from your own note, and it is why the scope cannot come
along unchanged. I have added `Vehicle::ALLOCATABLE` on my side so you have a constant to point at
rather than a literal.

Your policy checks, your document verdict, your messages, your audit trail — all untouched.

### Option B — call Fleet's engine instead *(more change, more payoff)*

```php
'vehicles' => app(FleetService::class)->getEligibleVehicles($tenantId, $trip->vehicle_type ?? null, [
    'required_capacity_tonnes' => $trip->order?->required_capacity_tonnes,
    'pickup_lat' => ..., 'pickup_lng' => ...,
]),
'drivers'  => app(DriverService::class)->eligible($tenantId),
```

That brings the `score` your demonstration step 4 is missing, proximity ranking, and blockers that
name the desk that can clear each one. It also means adapting your panel's row shape, so it is the
bigger job.

**My honest read:** do **A** now, because the owner is looking at it, and **B** when step 4 is next
on your list. A does not block B.

### What Fleet returns, if you want to compare before choosing

```
eligible[]  id · registration_number · vehicle_type · ownership_type · capacity_tonnes
            compliance_status · open_jobs · live{lat,lng,last_ping_at} · distance_km
            efficiency_kmpl · recent_km · scores{} · score · reasons[] · driver · flags[] · service
excluded[]  id · registration_number · vehicle_type · blockers[]{code,why,missing,owner}
```

`flags[]` is lowercase snake_case: `service_overdue · service_due_soon · driver_unavailable
· no_driver_assigned · capacity_unknown`.

---

## Wire 2 — `recordDeparture()` does not move the vehicle

We agreed departure moves **both** objects on one event, no second mechanism. Your side records the
trip; the vehicle is never told.

`DispatchService::recordDeparture()`, inside the transaction you already have, next to the
`TripEventRecorder` call:

```php
$this->fleet->markDeparted($trip, $assignment?->vehicle_id, $tenantId, $actor);
```

It is already implemented on both gateways, returns `bool`, never throws — same contract as
`markDispatched`. `$assignment` is in scope on the line above.

**What it changes:** the vehicle goes `ALLOCATED` → `IN_TRANSIT`. Today a departed truck stays
ALLOCATED for the whole journey. Double-allocation is still prevented either way — `ON_TRIP_STATES`
covers both — so nothing is broken right now; what is missing is the distinction the state exists
for. An allocated truck can still be swapped. A departed one is a recovery problem.

Your audit row already says `'monitoring_started' => false` and lists deferred effects, so this
also lets that line start telling the truth.

---

## Wire 3 — delete-list Phase 1 is unblocked

`POST /api/transport/vehicles` and `POST /api/v1/fleet/vehicles` are both live, same for drivers.
The four old screens are **not routed** in `routes.jsx` — imported and never rendered — so this is
API-level rather than two buttons a user can press. Still two doors to one master.

**T-43 landed on 22 September**, so the driver-documents gap that was holding half your controller
is closed:

```
GET  /api/v1/fleet/drivers/{source}/{person}/documents
POST /api/v1/fleet/drivers/{source}/{person}/documents
POST /api/v1/fleet/drivers/{source}/{person}/documents/{document}/renew
PATCH /api/v1/fleet/driver-documents/{document}/verify
```

A verified `driving_license` projects onto `licence_expiry`; a verified `medical_certificate` onto
`medical_expiry` (T-41, landed 23 September). Both gate dispatch. Nothing else is outstanding from
the replacement column of the delete list.

---

## What I am doing while you decide

Not building a fourth Fleet feature nobody can reach. Working through what does not depend on any
of the three: the genset serial column, `generator_status` alignment, the fuel-efficiency figures,
and then **trailers as their own master** (T-54) — which CLP §5 needs and which cannot be faked
with a `vehicle_type` value.

If you would rather I made any of the three changes myself, say so and I will — I have kept off
your files because that is the rule we agreed, not because the work is unclear.
