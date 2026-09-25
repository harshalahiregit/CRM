# I ran `--relink` on your command. Here is exactly what moved.

**From:** Person 1 · **To:** Person 2 (Shivam), cc the lead · **2026-09-21**

You built the command and the flag; I ran it against your column, with the owner's authorisation,
and you should hear the numbers from me rather than find them in a diff.

## What I ran, and what it wrote

```
php artisan stos:reconcile-fleet --relink
```

It repaired **two links**, one-to-one on the plate, and touched nothing else:

```
vehicles.legacy_transport_vehicle_id   {1: 29, 2: 30}  ->  {1: 35, 2: 36}
```

Before it, a `mysqldump` was taken **and restored into a throwaway MySQL 8.0.46 container** to
prove it comes back — 570 tables, row counts matching live on every table involved, content spot-
checked rather than just counted. The dump had been sitting there for two days as a file nobody
had tested, which is not a backup.

## Your dry run is meaningful for the first time

| | Before | After |
|---|---|---|
| `transport_trips.vehicle_id` | 0 to move, 5 unmapped | **2 to move**, 3 unmapped |
| `transport_trips.driver_id` | 0 to move, 5 unmapped | 0 to move, **5 unmapped** |
| `trip_assignments.vehicle_id` | 0 to move, 3 unmapped | **3 to move**, 0 unmapped |
| `trip_assignments.driver_id` | 0 to move, 3 unmapped | 0 to move, **3 unmapped** |

**Your vehicle side is clean.** Every unmappable vehicle reference is a soft-deleted junk trip
(2, 12, 14 — leftovers from an old reseed). No live vehicle reference would get a permanent NULL.

## `--apply` has not run, and should not until the driver half lands

Five **live** driver references would be written into `fleet_reference_repoints` as permanent
`to_id = NULL` — *never match this row*:

```
trip 43        TRP-2026-000034  driver_id=39
trip 44        TRP-2026-000035  driver_id=40
assignment 34  trip 43          driver_id=39
assignment 35  trip 44          driver_id=40
assignment 36  trip 44          driver_id=40
```

All five are real records pointing at real drivers. They are unmappable only because
`driver_profiles.legacy_transport_driver_id` still holds **33 and 34** while the live
`transport_drivers` rows are **39 and 40** — the same reseed damage the plate side had, and
`--relink` is vehicle-only.

That is **D-118**, unchanged from Friday. The fix is still one pass: match drivers on the
normalised licence the way you match vehicles on the normalised plate.
`driver_profiles.licence_normalized` already exists and the licences match one-to-one:

```
driver_profile #2  RJ14 2019 0011221  ->  transport_drivers #39
driver_profile #3  MH12 2020 0033445  ->  transport_drivers #40
```

Simulated with both halves relinked, every column reaches **0 unmappable**. Then `--apply` is
safe and the repoint finishes in one sitting.

## Two smaller things I found while in there

**1. `RepointTripFleetReferences` does not honour soft deletes.** It reads through
`DB::table($table)` with no `deleted_at` filter, so it sees deleted rows and would repoint and
ledger them. Harmless for the three junk trips, but it is the kind of thing that is much harder to
spot once it is mixed into real data.

This caught me out too, in the other direction: I reported "clear the junk trips first" when they
had **already** been soft-deleted on 16 September. My count used `DB::table()` and so did not know
about `deleted_at` either. Same trap, both of us.

**2. Your reconcile still reports the driver side as healthy.** `Drivers: 2 legacy rows — 2 have a
Fleet profile` counts profiles whose `legacy_transport_driver_id` is not null; it never asks
whether that id points at a driver that exists. The vehicle side now says *"link points at #29,
which is gone. Repairable."* — the driver side deserves the same sentence, and it is why this was
missed for two days.

## And a second mention in passing, no chase intended

The **read method on `FleetResourceGateway`** is still not there — the interface has the same three
write methods. Not urgent; the portal search states the Fleet-only plate limit in words in the
meantime, and I am not reaching around the seam to do better.
