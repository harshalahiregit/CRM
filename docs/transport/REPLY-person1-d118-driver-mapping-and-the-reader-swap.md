# D-118 fixed, and three things your list has wrong

**From:** Shivam (Person 2 — Fleet) · **To:** Person 1 (Mohammad Raza), cc the lead · **2026-09-22**
**Closes:** D-118 · **Unblocks:** `--apply`

---

## 1. The driver half is fixed, and you were exactly right about why

`ReconcileFleetMasters.php:212` was one line:

```php
$migrated = DB::table('driver_profiles')->whereNotNull($link)->count();
```

It compares two numbers. It never asks whether the ids in those links point at
anything — which is why it reported both drivers healthy against 33 and 34 while
the live rows were 39 and 40.

**The two halves are now one implementation**, parameterised by which master it is reading. That is
the actual defect, not the missing driver logic: two copies of a rule is how one of them stops
being the rule. The vehicle half got the real treatment in the last pass and the driver half kept
the old line, and nothing made them disagree out loud.

Five states now, instead of a count:

| state | meaning |
|---|---|
| migrated | a link that points at a row that **is there** |
| repairable | one Fleet row carries the identity; only the link is wrong |
| ambiguous | more than one carries it — needs a person |
| missing | nothing carries it and nothing was inserted — re-run the migration |
| **unidentifiable** | nothing to match on at all |

`--relink` now covers drivers, matching on the normalised **licence** exactly as vehicles match on
the normalised plate, and refusing when the Fleet profile is already claimed by a legacy row that
still exists.

**One state is new and it is yours to know about.** A legacy driver can carry no licence at all. A
vehicle always has a plate, so the vehicle half never needed this. A driver with nothing to match
on cannot be repaired by any rule, so it is reported rather than guessed at — putting one person's
licence and expiry on another driver is not a recoverable mistake.

```bash
php artisan stos:reconcile-fleet            # both masters, checked not counted
php artisan stos:reconcile-fleet --relink    # repairs vehicles AND drivers
php artisan stos:repoint-trip-fleet-refs     # dry run — real numbers on both columns
```

Four driver cases added, including your reseed shape. 23 passing in the file.

---

## 2. Your coupling (a) is worse than you wrote it — for drivers it is not a vocabulary problem

You have the vehicle side right: `VehicleStatus::ALLOCATABLE` is `['available','idle']`, Fleet's
column holds `AVAILABLE` / `IDLE`, and `VehicleEligibilityService.php:72` is a strict `in_array`.
Every Fleet vehicle reads as not allocatable. Agreed, and it would have cost a day.

**The driver side is a different problem wearing the same clothes.** `DriverEligibilityService`
checks **two** columns:

```php
in_array($driver->status,       DriverStatus::ALLOCATABLE, true)        // ['active']
in_array($driver->availability, DriverAvailability::ALLOCATABLE, true)  // ['available']
```

`driver_profiles` has **no `availability` column at all.** It folds your lifecycle and your
availability into one `status` of `available | on_trip | suspended | inactive`.

So a repointed driver fails **both** gates, and not because of casing:

- `status` is `available`, checked against `['active']` → false
- `availability` does not exist → null → false

Lower-casing the vocabulary fixes the vehicle and does nothing for the driver. **Two columns into
one is a mapping, not a translation**, and a translation table between two vocabularies is exactly
the kind of duplicated rule that rots — we have just spent a day on one.

**So do not translate. Call the services.** Both already answer in Fleet's own terms and both
already exist:

```php
FleetService::getEligibleVehicles($companyId, $vehicleType, $context)   // returns a `score` per candidate
DriverService::eligible($companyId, $filters)                          // eligible[] + excluded[] with blockers
```

`getEligibleVehicles()` returning a score is demonstration step 4's missing number — it is computed
today and the picker simply is not reading it. Step 4 and the repoint close together, with no new
code on either side.

## Coupling (b) is seven relations, not two

You named both `vehicle()` relations. It is **seven**, across four models, and it includes the
drivers:

```
TransportTrip::vehicle()       TransportTrip::driver()
TripAssignment::vehicle()      TripAssignment::driver()
TripException::vehicle()       TripException::driver()
                               TripAdvance::driver()
```

All `belongsTo(TransportVehicle::class)` or `belongsTo(TransportDriver::class)`. Repoint the columns
and miss any one of them and that screen shows a blank now and a **different** person or truck once
the ranges overlap — which is D-116 again, in a place neither of us has a guard on.

I have not touched them. They are yours, and the rule is the rule.

## (c) I agree, and `freeResources()` is the one I would check first

The plate search failing silently is bad; `freeResources()` quietly not freeing trucks is worse,
because the fleet just stops having capacity and nothing says why.

---

## 3. Telemetry is already done — this one should come off your list

This is the second time it has appeared as outstanding, so it is worth being precise rather than
just saying "done":

| event | where |
|---|---|
| `gps.activated` | `TripTimelinePublisher::positionEvents()` |
| `genset.on` / `genset.off` | `gensetEvents()` |
| `temperature.reading` | `temperatureEvents()` |
| `temperature.excursion` | `publishExcursion()` |

All five reach `trip_events` through your `TripEventRecorder`, with `occurredAt` carrying the
device's clock as your contract asks. Shipped before your first D-116 note — **you tested this code
twice**, which is how we found that the join was wrong and then that my first fix confirmed itself.
`TripTimelineTest` is 17 passing, including your three cases.

One judgement to know about, because it is visible on your screen: **the timeline publishes on
CHANGE, not per ping.** A tracker reports every couple of minutes; several hundred identical
position lines would bury the four events somebody reads a journey for. The full trail stays in
`telemetry_records`.

Step 8 does not need the repoint either. It needed the mapping, and the plate now finds candidates
as well as confirming them, so it works today.

---

## 4. The gateway read method — close it

You asked for a decision rather than a hedge, so: **close the request.**

Its entire value is the window between now and the repoint, and I have just removed the last thing
holding the repoint. After the switch you read Fleet's table directly and the question answers
itself. A new interface method that is dead on arrival costs us both more than the thing it fixes.

The behaviour you actually want in the meantime — a registration search that says *"Fleet holds
this vehicle and it has not migrated"* instead of a flat "no match" — is one line in your search
result, and you can write it against `vehicles.registration_normalized` today without an interface
between us.

**One condition, so this is not "someday":** if `--apply` has not run by **Monday 29 September**, I
build the method that week. Hold me to the date rather than the intention.

---

## Nothing is blocking me

You asked, so: nothing of yours is. The only thing I want visibility on is **when you make the
reader swap**, because that is the moment `--apply` is safe, and the ledger the repoint writes is
what keeps telemetry correct on both sides of it. Tell me the day and I will run it with you rather
than near you.
