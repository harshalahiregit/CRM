# Stopped before `--apply`. Your command moves four reference columns; there are six.

**From:** Person 1 · **To:** Person 2 (Shivam), cc the lead · **2026-09-22**

Your three fixes all check out and I got as far as step 5 of six. I have **not** run `--apply`,
and I do not think either of us should until this is answered.

## First — your corrections were right and mine were wrong

**(a) The driver vocabulary.** You were right and my analysis was wrong. `driver_profiles` has no
`availability` column at all; it folds lifecycle and availability into one `status`. My proposed
fix — lower-casing a comparison — would have done nothing, because the second gate reads a column
that does not exist. Calling `DriverService::eligible()` is the right answer and I am taking it. A
translation table between two people's vocabularies would have been a third thing to keep in sync,
and you were right to refuse to build one.

**(b) Seven relations, not two.** I found them independently rather than working from your count,
and it is seven across four models. The two I had missed are the two that matter — see below.

**(c) `freeResources()`.** Noted, on my list, and it is in the reader swap.

## Your reconcile fix does what it says

```
Vehicles: 2 legacy rows — 2 linked, 0 outstanding.
Drivers:  2 legacy rows — 0 linked, 2 outstanding.
  · #39 SANGOE DEMO Ramesh Kumar · RJ14 2019 0011221 — Fleet profile #2 (link points at #33, which is gone). Repairable.
  · #40 SANGOE DEMO Suresh Patil · MH12 2020 0033445 — Fleet profile #3 (link points at #34, which is gone). Repairable.
```

It checks the link points at a row that *exists* rather than that a link exists, which was the whole
of D-118. `--relink` repaired both on the normalised licence. **No `unidentifiable` rows** — the
licence-less driver you warned about does not arise in our data, and I would have stopped if it had.

Dry run after relinking, and these are real numbers:

```
transport_trips    vehicle_id   2 to move, 3 pointing at ids that no longer map
transport_trips    driver_id    2 to move, 3 pointing at ids that no longer map
trip_assignments   vehicle_id   4 to move, 0 pointing at ids that no longer map
trip_assignments   driver_id    4 to move, 0 pointing at ids that no longer map
```

**Every unmappable row is a soft-deleted junk trip** (2, 12, 14 — reseed leftovers). No live
reference would be lost. That part is ready.

## Then I stopped — `trip_exceptions` and `trip_advances`

Your command moves four columns. There are six:

| Table | Columns | Live references | Moved? |
|---|---|---|---|
| `transport_trips` | vehicle_id, driver_id | | ✅ |
| `trip_assignments` | vehicle_id, driver_id | | ✅ |
| **`trip_exceptions`** | **vehicle_id, driver_id** | **4** | ❌ |
| **`trip_advances`** | **driver_id** | 1 | ❌ |

And they hold exactly the ids you are moving away from:

```
trip_exceptions #2  EXC-2026-000001  vehicle_id=35  driver_id=39
trip_exceptions #4  EXC-2026-000003  vehicle_id=36  driver_id=40
```

After `--apply`, trips and assignments hold `1, 2` / `2, 3` while these hold `35, 36, 39, 40`.
**Same column name, two meanings, one schema, nothing marking which.**

There is no correct reader swap for the three relations on those models:

- **Repoint them** → legacy ids resolved against Fleet: blank now, a **different truck** once the
  ranges overlap. That is D-116's shape in a place neither of us had a guard.
- **Leave them** → the ambiguity is permanent and undocumented.

**And it would not announce itself.** Nothing loads `$exception->vehicle` or `$advance->driver`
today, so `--apply` would pass, no screen would blank and no test would fail. It would surface
whenever somebody first renders a vehicle on an exception — against data that had been wrong for
weeks.

**The ledger does not catch it either.** `fleet_reference_repoints` gets a verdict per row for the
four columns the command processes. These two get no entry, so the rule *"a row with no entry was
created after the switch and is in the new space"* would read them as new-space rows and confirm
the wrong answer.

## One thing that is not a mapping problem

`trip_advances #2` carries `driver_id = 1212010`. Our drivers are 39 and 40. Whatever that value
is, it is not a foreign key, and I would rather it were looked at than carried through a migration
as though it were.

## What I would suggest, and it is your call

Extend the command to all six columns — the spec-and-engine shape you refactored the reconcile into
looks like it would take a third and fourth entry cheaply. If you would rather scope `--apply` to
the four and declare the other two out of scope, that works too, **as long as it is written down and
the three relations stay pointed at the legacy master** — but I would rather do it once.

I have stopped where I was told to stop at the first surprise. Everything up to here is done and
holds: merged, suite green on the usual nine, fresh backup taken **and proved by restoring it into
a scratch database with today's state in it**, reconcile read, both relinks run, dry run reported.
The reader swap is designed and not started.

Raised as **D-120**. Tell me which way you want it and I will pick up the same day.
