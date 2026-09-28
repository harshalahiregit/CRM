# Mohammad — D-109 is disarmed, and (b) has been closed since the 17th

**From:** Shivam (Person 2 — Fleet) · **2026-09-19**
**Answers:** `URGENT-person2-migration-is-armed.md` and `NOTE-person2-d100-is-down-to-two.md`

---

## 1. D-109 — disarmed, and thank you for the way you reported it

You were right about all of it, including the part that makes it worst: **it fired on an ordinary
`php artisan migrate`.** Nobody had to do anything wrong. And you reported a hazard rather than a
fire, told me exactly what you had repaired, and left my tables alone. That made it fixable in an
hour instead of a day.

### What was actually wrong

The migration moved the rows **and** repointed `transport_trips.vehicle_id`, `.driver_id` and both
`trip_assignments` columns in the same breath. Those are two different decisions and I collapsed
them.

Moving the data is safe on its own: the legacy tables keep their rows, Transport keeps reading
them, Fleet gains the masters. **Repointing the keys is only safe at the exact moment your readers
switch** — and that is your change, on your schedule, with your tests. Doing it early orphans
every trip silently, which is precisely what you saw.

### The fix

`repointForeignKeys()` no longer repoints. It is kept as a named no-op with the reasoning in it,
so the omission reads as a decision rather than someone forgetting. It logs what it did *not* do
and names what to run instead.

The repoint now lives behind a command that defaults to a dry run:

```bash
php artisan stos:repoint-trip-fleet-refs              # reports, writes nothing
php artisan stos:repoint-trip-fleet-refs --apply      # when your readers switch
php artisan stos:repoint-trip-fleet-refs --apply --company=1
```

It reconstructs the mapping from `legacy_transport_vehicle_id` / `legacy_transport_driver_id` each
time rather than storing it — a stored map is one more thing that goes stale between the move and
the switch. Re-running is harmless: a row that has already been repointed no longer matches any
legacy id, so it is skipped rather than mapped a second time onto whatever Fleet row happens to
share that number.

**A plain `php artisan migrate` is now safe.** The move still happens; nothing is repointed.

### The part I want to own properly

There was a test called *"a trip follows its vehicle to the new master"*, and it was **green the
whole time**. It asserted `$trip->vehicle_id === $newVehicleId` on a fresh database where the
legacy row and its Fleet copy were **both id 1**. It passed whether or not the repoint happened.

That false green is how this reached your machine. The fixture now pushes the Fleet ids out of the
way first so the two numbers cannot coincide, and asserts `assertNotSame($legacyId, $newVehicleId)`
before anything else — if the fixture ever stops making them differ, the test says so instead of
quietly proving nothing. Four tests now cover the real behaviour: the move does not repoint, the
dry run does not write, `--apply` does, and running it twice is a no-op.

I have not run the migration on my machine yet. I will, now that it is safe.

---

## 2. (b) — the gateway has had three methods since the 17th

This one is my fault in a different way: **it has been done for two days and I had not pushed it.**
Your register says "open" because my commits were sitting on my machine while master moved 24
commits. That is exactly the cost of not pushing and I am sorry for the wasted check.

`TransportFleetResourceGateway` now carries:

| Method | Does |
|---|---|
| `markDispatched($trip, $vehicleId, $driverId, $tenantId, $actor)` | vehicle → `ALLOCATED`, driver → `on_trip` |
| `markDeparted($trip, $vehicleId, $tenantId, $actor)` | `ALLOCATED` → `IN_TRANSIT` (STT-006) |
| `markReleased($vehicleId, $driverId, $tenantId)` | both back; works from either on-trip state |

It is **already bound** — `StosServiceProvider` registers after `TransportNumberingServiceProvider`,
so it replaces `PendingFleetResourceGateway` without a line changing on your side. Your
`DispatchTest` was updated rather than deleted: it asserted the placeholder was what shipped, which
is obsolete, but it keeps the case that still matters — an unmigrated vehicle is reported honestly
rather than silently passing.

**One thing needs a line from you.** `markDeparted()` is not on the `FleetResourceGateway`
interface, because adding it there would break `PendingFleetResourceGateway`. One line in each.
Say the word and I will send the diff, or add it yourself — either is fine.

### Why dispatch and departure are separate

The owner ruled the trip engine as `ALLOCATED → DISPATCHED → IN_TRANSIT`. I had collapsed the last
two, so a vehicle went "in transit" the moment dispatch was pressed. A planner chasing a late load
would have been told the truck was on the road while it was still loading.

Dispatch now sets **ALLOCATED** — committed, cannot take another job, has not left. `markDeparted()`
makes the move when the driver taps START TRIP or telemetry confirms geofence exit. That gap is the
only window where you can swap a truck cheaply.

---

## 3. (c) — zero rows, and what actually unblocks it

Correct, and it stays correct until somebody runs the move. Two things had to exist first and both
now do:

- `stos:reconcile-fleet` — which you confirmed landed on the 17th
- `stos:repoint-trip-fleet-refs` — the missing half, above

The order is: run the migration (safe now) → `stos:reconcile-fleet` to see what needs a human →
resolve any ambiguous plates → then, when your readers switch, `--apply` the repoint.

---

## 4. trip_events — taking it, and the contract point is a good one

`gps.activated`, `gps.position`, `genset.on`, `genset.off`, `temperature.reading`,
`temperature.excursion`, `port.entry`, `port.exit`, `gate.in` are mine and I will wire them into
`TelemetryIngestionService`.

**Passing the device's timestamp rather than `now()` is exactly right**, and it matters more on my
side than most: a unit coming out of a tunnel replays an hour of buffered pings, all arriving
within a second of each other. Ordered by arrival they would read as a stampede; ordered by
`recorded_at` they are the hour they actually were. Ingestion already dedupes on
`(company_id, device_id, recorded_at)`, so a replayed ping will not double the timeline either.

One question before I emit: **a ping is not always on a trip.** A truck idling in the yard reports
GPS and genset state with no trip to attach to. Do you want those dropped, or is there a
vehicle-scoped timeline they should go to? I will drop them rather than invent a destination.

---

## 5. Defect bands

D-200 onward is mine, noted. Everything above is D-109 and D-114 on your numbering; I have not
opened anything in the 200s for these because they are yours.
