# D-116 is closed. Before I relink, the driver half has the same damage.

**From:** Person 1 · **To:** Person 2 (Shivam), cc the lead · **2026-09-19**

## First — D-116 is genuinely closed, and I tested it properly this time

Five cases, not three. The extra two matter because the first three only exercise the world
*before* the switch, and the repoint's whole risk is the world *after* it:

| Trip's `vehicle_id` | Want | Got |
|---|---|---|
| Same truck (`#35`) | publish | publish ✅ |
| Different truck (`#36`) | block | block ✅ |
| Dangling id equal to a live Fleet id (`1`) | block | block ✅ |
| *post-switch* repointed `35 → 1`, ledger `to_id = 1` | publish | publish ✅ |
| *post-switch* unmappable row, ledger `to_id = NULL` | block | block ✅ |

`legacyPlateFor()` reading one table and treating a missing row as unknown is exactly right, and
the `fleet_reference_repoints` ledger is a better answer than the one I suggested — I proposed
switching the lookup "when the meaning changes", and you were right that a remembered edit
recreates the defect inside repointed data. I checked the ledger is **read** by the publisher and
not merely written by the command, because a table nothing reads is how D-115 happened.

The dry-run message is the other thing worth saying out loud. *"0 to move, 5 pointing at ids that
no longer map"* with the likely cause beats *"0 rows"* and silence, and it is the difference
between a diagnosis and an afternoon.

## Then — I was about to run `--relink` and stopped

The vehicle half does what you built it for. Simulated in a rolled-back transaction:
mapping `{35 → 1, 36 → 2}`, and with the three junk demo trips cleared,
**`transport_trips.vehicle_id` goes to 0 unmappable.**

**The driver half is broken the same way and reports itself as fine.**

| | Live rows | The link says |
|---|---|---|
| Vehicles | `transport_vehicles` 35, 36 | `legacy_transport_vehicle_id` 29, 30 — reported repairable ✅ |
| Drivers | `transport_drivers` 39, 40 | `legacy_transport_driver_id` 33, 34 — **reported fine** ❌ |

Same reseed, same damage. But `reconcileDrivers()` counts profiles whose
`legacy_transport_driver_id` is not null; it never asks whether that id points at a driver that
exists. So it prints:

```
Drivers: 2 legacy rows — 2 have a Fleet profile.
```

Two profiles do carry a legacy id. Neither carries one of the two drivers that are actually there.

If I relink now, the vehicle side goes clean and **five driver references get written into the
ledger as permanent `to_id = NULL`** — the entry that means *never match this row* — for rows that
are repairable in one pass.

## The fix is the exact analogue of the plate, and you have the column already

`driver_profiles` carries `licence_number` **and `licence_normalized`**. The licences match
one-to-one today:

```
driver_profile #2  RJ14 2019 0011221  ->  transport_drivers #39
driver_profile #3  MH12 2020 0033445  ->  transport_drivers #40
```

So `--relink` matching drivers on the normalised licence, the way it matches vehicles on the
normalised plate, with the same one-to-one-only rule. I simulated it — junk trips cleared, both
halves relinked:

```
transport_trips    vehicle_id   2 would move, 0 unmappable
transport_trips    driver_id    2 would move, 0 unmappable
trip_assignments   vehicle_id   3 would move, 0 unmappable
trip_assignments   driver_id    3 would move, 0 unmappable
```

**Everything reaches zero.** No permanent NULLs in the ledger at all, which is the condition the
owner set for the repoint going ahead.

I have not written anything — not the relink, not the driver links, not the junk trips. Logged as
**D-118**. It is your command and your column, and matching drivers on a licence is a judgement
about Fleet's data that belongs with you rather than with me.

## One smaller thing, no action needed from you

There is no way to remove a trip through the services — no `cancelled` state, no delete route, no
delete method. The three junk trips hold a `vehicle_id` and `driver_id` with **zero assignment
rows**, which the services cannot produce, so `release()` cannot reach them either. Noted in D-118
because "clear it through the real services" turns out not to be possible for a trip, and that is
better known now than the first time somebody needs it on real data.
