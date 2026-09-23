# The repoint ran. Numbers, two migrations I had to touch, and one thing that is yours.

**From:** Person 1 · **2026-09-23** · **Read the last section first if you only read one.**

---

## 1 · The numbers

`stos:repoint-trip-fleet-refs --apply --force`, after a fresh backup proved by restoring it into a
throwaway `mysql:8.0` container and checking today's rows were in it — not just that tables existed.

```
Mapping: 2 vehicles, 2 drivers.

  transport_trips    vehicle_id   2 moved, 3 never_valid
  transport_trips    driver_id    2 moved, 3 never_valid
  trip_assignments   vehicle_id   4 moved, 0
  trip_assignments   driver_id    4 moved, 0
  trip_exceptions    vehicle_id   2 moved, 0
  trip_exceptions    driver_id    2 moved, 0
  trip_advances      driver_id    0 moved, 1 never_valid

Repointed 16 rows; 7 recorded as unmatchable.
```

`stos:reconcile-fleet` reported **0 outstanding on both halves** beforehand, so `--relink` had
nothing to repair and wrote nothing. Your two new guards both passed — no contested mapping, no
unsafe collision — which is why a clean dry run means more than it did last week.

**Your D-120 fix held up to reading.** The contested check stops the run *before surveying*, which is
right: numbers computed from a guessed map are worse than no numbers. And the collision check's
second case — a row not moving because it already holds the Fleet id natively — is the one that
would actually have bitten on switch-day, not the pair-wise one.

## 2 · A third verdict in the ledger

`trip_advances` #2 holds `driver_id = 1212010`. That value is an id in **no table anywhere** — not a
legacy driver, not a profile, not a user. The trip's real driver is 40.

The ledger could only say *"points at a legacy row with no Fleet counterpart"*. For that row the
sentence is not a rough approximation, it is false — and every reader downstream would then refuse it
on a stated ground that was not the real one. So there is now a `verdict` column with three words:
`moved`, `unmapped_legacy`, `never_valid`. Migration `2027_01_11_000001`, backfilled from `to_id` so
it is never silently wrong about history it did not witness.

Worth knowing: **all seven unmovable rows came out `never_valid`**, including the three junk trips
(2, 12, 14). Their legacy ids 10, 16, 17 and 21 are gone from the legacy tables too, so there is no
legacy row to point at. The verdict claims only what can be demonstrated — *nothing in the legacy
master answers to this value* — and its docblock says plainly that it cannot tell "never existed"
from "deleted since".

## 3 · Your new test has one blind spot, and it is not wrong today

`RepointCoversEveryReferenceTest::inTheSchema()` keys on **`tenant_id`**. That is correct for every
table that exists now. But Step 11 uses **`company_id`** as the tenant key — 34 occurrences,
`tenant_id` zero, which I recounted from the XLSX today — and Fleet's newer tables already follow it.

So a *new* transport table built to Step 11's convention would carry a legacy reference, be invisible
to the test, and be missed a third time. One line in the docblock would be enough; I have not touched
your file for it.

Separately, **D-133**: `transport_documents` references the masters polymorphically
(`entity_type` + `entity_id`), so it is an eighth reference your list cannot see — it looks for
columns *named* `vehicle_id`/`driver_id`. Seven rows live, all already orphaned onto deleted legacy
ids, so nothing changes today. Extending `REFERENCES` to a polymorphic pair means reshaping the test
you just installed, and that is a design call, so it is yours.

## 4 · Two of your migrations, which I had to repair to move at all — D-132

Both pass on SQLite and fail on MySQL, so a green suite could never have caught them.

- **`adopt_uppercase_fleet_enums`** — `DB::raw('count(*) as rows')`, and `rows` is reserved in MySQL
  8.0. It halted in the straggler *report*, not the data change, **after** converting some tables:
  the dev database sat with `vehicles.status = 'AVAILABLE'` and `driver_profiles.status =
  'available'`. Backticked.
- **`generalise_party_assignees`** — `dropUnique('task_party_unique')` is refused while the foreign
  key `000004` removes still exists, and `000004` runs *after* it. It was also not re-runnable: the
  rename had applied, so the retry failed with "Table 'party_assignees' already exists". I guarded
  each step rather than reordering, because `000004` rebuilds the table with
  `party_subject_unique_v2` anyway and moving things would change what `000004` means.

Normally these would be a request, not an edit. They blocked the whole chain and the database was
already half-converted. **Both are minimal and change no intent — review them, and if you prefer the
reorder, take it.**

---

## 5 · THE ONE THAT IS YOURS — D-134, and it blocks the driver half

With allocation reading Fleet, the driver picker on an approved trip says:

```
None ready for TRP-2026-000036
CANNOT BE USED RIGHT NOW · 1
  Rajesh Kumar  HMV  — Not eligible
  No licence is on file for this driver. (Fleet compliance desk)
```

One person, correctly blocked. **The two real drivers are not there at all.** Not listed, not shown
as ineligible — absent.

`DriverService::list()` starts from `DriverDirectory::people()`. The bound implementation is
`CrmDriverDirectory`: *"Read live from TPV workforce, Purchase workforce, Vendor contacts, Customer
contacts."* It returns one person. The two migrated profiles carry **`source = 'stos'`**, written by
the D-62 move migration, and only `StandaloneDriverDirectory` resolves that source.

```
driver_profiles
  1  crm_client_contact:1   licence NULL         AVAILABLE   ← the only one offered
  2  stos:1   RJ14 2019 0011221   exp 2029-09-17  AVAILABLE   ← invisible
  3  stos:2   MH12 2020 0033445   exp 2029-09-17  AVAILABLE   ← invisible
```

This is not a data problem. The licences are in Fleet and valid until 2029. The migration did its
job. The directory that reads people and the migration that writes profiles disagree about what a
`source` may be, and nothing reconciles them.

**What has to be decided (yours):**

1. Should `CrmDriverDirectory` also resolve `stos`-sourced profiles — someone who drives for us but
   is not a TPV, purchase, vendor or customer contact?
2. Or should the D-62 migration have created those people in the CRM directory first, making
   `source = 'stos'` a state that should not exist?
3. Either way — what happens to the profiles carrying it today?

**The vehicle half is unaffected and works end to end.** The Fleet page finally shows MH12DEMO01
and MH14DEMO02, and trip 44 reads its crew from Fleet. For the allocation itself I checked the
service rather than the screen, because the screen misled me first: the trip page showed
"MH14DEMO02 — Vehicle and driver assigned", which was a **released** assignment from 21 September
rendered with repointed ids, and I nearly reported it as a successful allocation.

What is actually proven: `VehicleEligibilityService::candidatesFor()` returns both trucks with
`status: "AVAILABLE"` matched and `eligible: true`, and `AllocationService::assign()` on trip 45
wrote `vehicle_id = 1` and moved Fleet vehicle 1 to `ALLOCATED`. Trip 45 is deliberately left in
that state — a vehicle, no driver — because of the blocker below. Your gateway's wording carried through untouched, and
so did `DriverService`'s blockers — *"No licence is on file for this driver. (Fleet compliance desk)"*
is your sentence, and it reached the dispatcher's screen exactly as you wrote it, naming the desk.

I have not touched the directory or the binding. Waiting on you.
