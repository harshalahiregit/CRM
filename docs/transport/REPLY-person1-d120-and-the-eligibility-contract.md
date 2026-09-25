# D-120 closed, and the filter contract you asked for

**From:** Shivam (Person 2 — Fleet) · **To:** Person 1 (Mohammad Raza), cc the lead · **2026-09-23**
**Closes:** D-120 · **Answers:** the `getEligibleVehicles()` parameter question

---

## The model lands, and it changes one thing for me

"No screen sends data to another screen" is right, and the part I want to keep is *the trip's state
is the messenger*. It settles a question I had been answering badly in my own head: I kept looking
for the place where Fleet hands you a vehicle. There isn't one, and there shouldn't be.

It also disposes of the gateway read method properly. I closed that request as "dead after the
repoint"; under this model it was never right — it was a screen-to-screen channel wearing an
interface. Better reason, same answer.

---

## 1. D-120 — closed, and it was seven

You had the three omissions exactly right. The total is **seven**, not six:

```
transport_trips.vehicle_id        ✓ was covered
transport_trips.driver_id         ✓ was covered
trip_assignments.vehicle_id       ✓ was covered
trip_assignments.driver_id        ✓ was covered
trip_exceptions.vehicle_id        ← yours
trip_exceptions.driver_id         ← yours
trip_advances.driver_id           ← yours
```

**The list is no longer written by hand.** `RepointCoversEveryReferenceTest` derives the truth from
the schema — every tenant-scoped `vehicle_id`/`driver_id` column — and compares it against the
command's declared list, both directions. A table added later fails that test instead of being
quietly missed. That is the only thing that stops a third round of this, and it is more use than my
being more careful next time.

`trip_assignments.active_vehicle_id` / `active_driver_id` are deliberately excluded: stored
generated columns off the two real ones, so they follow. They matter for the next part.

### Two hazards I found while in there, both silent

**The mapping could be contested and nothing said so.** `legacyMap()` used
`pluck('id', $link)`, which keys by the legacy id — so if two Fleet rows both claim legacy #35 the
second silently overwrote the first and the entire repoint ran against a mapping nobody chose.
Same family as D-116: wrong without being an error. It now stops the run, **including the dry
run** — numbers computed from a guessed map are worse than no numbers.

**`trip_assignments` has a uniqueness rule the repoint can break.** One ACTIVE assignment per
vehicle and per driver per tenant, enforced by unique indexes on those generated columns. Land two
active assignments on one Fleet id and the index fires partway through. The write is transactional
so nothing half-writes — but switch-day is the worst moment to discover it, so the dry run reports
it now.

Worth telling you how that one went: **my first version of the check was wrong and its own test
caught it.** I compared the moving rows against each other and missed the likelier case — a row
that is *not* moving because it already holds the Fleet id natively. That is precisely the partial
state the switch creates. Fixed, and the test is the one that failed.

**`--apply` is unblocked from my side.** Dry run first; it now reports seven columns, and it will
refuse rather than half-do anything.

---

## 2. `getEligibleVehicles()` — and you will not like the honest answer

You asked so you could shape the order fields to my contract instead of inventing a vocabulary.
Right instinct, so here is what is actually there rather than what the spec implies.

```php
FleetService::getEligibleVehicles(int $companyId, ?string $vehicleType = null, array $context = [])
```

**Everything it filters on, in full:**

| parameter | accepts | notes |
|---|---|---|
| `vehicleType` | `truck · trailer · tipper · tanker · reefer · lcv · other` | lower-cased on the way in, so case does not matter |
| `context['pickup_lat']` | float | optional; feeds proximity in the score |
| `context['pickup_lng']` | float | optional; both or neither |

That is the whole surface. Three things.

**Everything else is computed, not filtered** — it comes back per candidate and you choose what to
do with it: `score`, `blockers[]` (why a vehicle is excluded), `warnings[]`, service-due, licence
verdict for the regular driver, utilisation, efficiency.

### The three gaps, named plainly

**Capacity is not filtered, and that is a gap on my side, not yours.** `vehicles.capacity_tonnes`
exists — I added it in the D-62 union precisely because Fleet had no capacity column and
Operations did — and `transport_orders.required_capacity_tonnes` exists. **Nothing matches them.**
PLN-001 says eligibility matches an order's required capacity; today it does not. Do not design
around that; it is mine to fix and it is small.

**Container type and size have nowhere to land at all.** Fleet has no container columns. Not
unfiltered — absent.

**Trailer type does not exist either.** `trailer` is one of seven values of `vehicle_type`; there
is no sub-type beneath it. Trailers as a master in their own right is **T-54** on my board and not
built.

### So what I would do about CLP §5

Do not add order fields against a contract that cannot consume three of the four. The order in
which this is safe:

1. I add `required_capacity_tonnes` to the filter — the column exists on both sides, so it is a
   filter and a blocker, not a schema change. **This week.**
2. Container type/size and trailer type wait for **T-54**, because a trailer sub-type on a vehicle
   row is the wrong place for it and we would be unpicking it later.
3. Until then CLP §5 records what the client asked for on the order and it does **not** reach
   eligibility. Better a field that is honestly not yet wired than a vocabulary I have to map.

If §5 needs container matching sooner than T-54, say so and I will reorder — but I would rather you
made that call knowing it moves T-54 up than have me quietly bolt a container column onto
`vehicles`.

---

## 3. The milestone collision — put my name on it

CLP §3 giving Warehouse/Gate the gate-arrival, loading and sealing confirmations, and DVR §9 giving
the driver the same three, is a genuine conflict and I agree it has to be ruled before any of us
builds. **Add me to it.**

My view, for whatever it is worth to the ruling:

**Both should be allowed to record, and neither should be allowed to overwrite.** A gate clerk and
a driver in the same yard will not click at the same moment, and whichever we designate as "the"
source will be wrong some of the time — the driver whose gate has no clerk on shift, the clerk
whose driver's phone is dead. Picking a winner produces a milestone that is sometimes simply
missing.

What makes that workable is that a milestone is **an observation, not a state**. Two parties
observing the same event at 14:02 and 14:09 is not a conflict to resolve; it is two timestamps, and
the trip's state moves on the first one. That fits the model in your note exactly — the record's
state moves, and both windows read it.

I have been wrong about the condition-versus-state distinction three times in this module, so I
hold that loosely. But if it is ruled as a single-writer field, the rule needs to say what happens
when the designated writer is absent, or we will be looking at blank milestones and calling it a
bug.

### On Step 11 outranking CLP and DVR

Agreed, and I have applied it: `trip 131 · POD 26 · milestone 0 · container 0 · portal 0`. Nothing
I am building names a milestone or container event. The five telemetry events I publish are
registered against Fleet's name in Step 11, which is why they were safe to build.

---

## What I need from you

Only the date. Tell me when you make the reader swap and I will run `--apply` alongside you rather
than near you — the ledger it writes is what keeps telemetry correct on both sides of the switch,
and I would rather we watched the first ten minutes together.
