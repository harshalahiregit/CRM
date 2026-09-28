# D-116 — the idea is right, and it does not fire yet

**From:** Person 1 · **To:** Person 2 (Shivam), cc the lead · **2026-09-19**
**On:** `6842b70a` *fix(telemetry): the id finds the candidate, the plate decides*

"The id finds the candidate, the plate decides" is exactly the right shape, and failing closed with
a logged warning rather than publishing is the right default. I re-ran my collision experiment
against it. Two things came out, and the second one is the reason I am writing rather than closing
the defect.

## 1. On our data it never gets as far as the plate check

`idsThisVehicleMayBeKnownBy()` builds the candidate set from the Fleet vehicle's own id plus its
`legacy_transport_vehicle_id`. Here that is:

| Fleet | plate | `legacy_transport_vehicle_id` |
|---|---|---|
| #1 | MH12DEMO01 | **29** |
| #2 | MH14DEMO02 | **30** |

…but the live `transport_vehicles` rows for those same two trucks are **#35 and #36**, and the
trips in the database point at `10, 17, 35`. Nothing overlaps, so every real reading finds **no
candidate at all** and is dropped before any plate is compared.

I checked the correct case explicitly: a trip pointing at `transport_vehicles#35` — genuinely the
same truck as Fleet #1 — **is blocked.** Not wrongly published: blocked. So step 8 still shows
nothing on the demonstration trip.

**This half is a data problem, not a code one.** `legacy_transport_vehicle_id` is stale; 29 and 30
are rows that a reseed replaced. `stos:reconcile-fleet` says the same thing from the other end —
*"2 legacy rows — 0 migrated, 2 outstanding"*, both AMBIGUOUS. Fixing the mapping is what makes
your guard reachable.

*(Small thing while you are in there: the reconcile output says* "AMBIGUOUS, matches 1 Fleet
vehicles" *and then advises* "Resolve by correcting the duplicate plates in Fleet". *With exactly
one match there is no duplicate to correct, so the instruction sends the reader looking for
something that is not there.)*

## 2. The one case that does publish is the one that should not

I put a trip at `vehicle_id = 1` — meaning `transport_vehicles#1`, a row that does not exist —
and published a reading for **Fleet vehicle 1**. It published.

`platesFor($id, $company)` looks the same integer up in **both** masters and unions the results.
When the `transport_vehicles` row is missing, the only plate that comes back is **Fleet's own**,
read with the trip's id — so the comparison is the Fleet vehicle's plate against itself, and it
always agrees.

```
platesFor(1)  ->  ['MH12DEMO01']     (from `vehicles`, because transport_vehicles#1 is absent)
$plate        ->   'MH12DEMO01'      (the same vehicle)
                  === $plate  ->  true  ->  publish
```

**This is not hypothetical on our data.** Trips 2, 12 and 14 hold `vehicle_id` 10 and 17, and
neither exists in `transport_vehicles` — the same reseed that moved 29/30 to 35/36. The day Fleet
issues a vehicle with id 10 or 17, its telemetry attaches to those trips, which carried different
trucks.

### The suggestion

Until the repoint, `transport_trips.vehicle_id` means one thing: a `transport_vehicles` id. So
resolve the candidate's plate **only** in `transport_vehicles`, and treat a missing row as
*unknown* rather than as a match:

```php
$legacy = DB::table('transport_vehicles')->where('tenant_id', $companyId)
    ->where('id', $vehicleId)->value('registration_number');

if (! $legacy) {
    return [];       // the trip points at a row that is gone — never a match
}

return [$this->normalisePlate($legacy)];
```

That keeps your design and removes the self-confirmation. After the repoint the column changes
meaning and this reads Fleet's table instead — which is a one-line change made at the moment the
meaning actually changes, rather than a lookup that quietly spans both.

## Where this leaves us

**D-116 stays open**, and the repoint stays on hold — the lead's sequencing call, and this
strengthens it. The dry run reports **0 rows on every column**, which reads like "nothing to do"
but actually means the stored mapping no longer matches any live row: a repoint today would change
nothing *and* leave every trip still pointing at the placeholder table.

So the order is: fix the mapping, then your plate check starts firing, then we repoint.

Tell me when the mapping is corrected and I will re-run all three cases the same afternoon —
same truck, wrong truck, dangling id. The first should publish and the other two should not.
