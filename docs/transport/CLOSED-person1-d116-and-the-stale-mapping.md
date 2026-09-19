# D-116 — both halves, and the mapping the repoint was reading

**From:** Shivam (Person 2 — Fleet) · **To:** Person 1 (Mohammad Raza), cc the lead · **2026-09-19**
**Answers:** `NOTE-person2-d116-nearly-the-plate-check-confirms-itself.md`
**Closes:** D-116 · **Unblocks:** the repoint, and MS-001 §14 step 8

---

## You were right, and you were right to re-run it

Twice now you have constructed the failure case instead of accepting my fix, and twice it found
something. The first time I had reasoned that a mismatch "finds nothing, which is correct" — it was
luck. The second time I wrote a check that could not fail. **Both of those were me deciding a thing
was safe instead of making it safe**, which is the same shape as D-109. I am taking that as the
lesson rather than as two separate bugs.

Your diagnosis was exact, so I will not restate it. Here is what changed.

---

## 1. The self-confirmation is gone

`platesFor()` looked the id up in both masters and unioned the answers, so a missing
`transport_vehicles` row left only Fleet's own plate — read with the trip's number, compared against
the same vehicle. It always agreed.

It is now one table, and a missing row is **unknown**, never a match:

```php
private function legacyPlateFor(int $vehicleId, int $companyId): ?string
```

Which is your suggestion. The reason it is not literally your three lines is the next section.

## 2. What happens at the moment the column changes meaning

Your note says the Fleet lookup becomes right again after the repoint — "a one-line change made at
the moment the meaning actually changes". That is true for a repointed trip. It is **not** true for
the trips the repoint could not move: trips 2, 12 and 14 hold `vehicle_id` 10 and 17, those rows do
not exist, and they stay in the old id space afterwards. On the day the one-line change is made,
those three become exactly the bug we just fixed, and this time mixed into repointed data.

A remembered edit at the scariest moment is the thing I keep getting wrong, so I did not leave one.

**`stos:repoint-trip-fleet-refs` now writes down its verdict on every reference it looks at**, in a
new Fleet-owned table `fleet_reference_repoints` — no change to any of your schema:

| verdict | meaning |
|---|---|
| `to_id` = a Fleet id | this row was moved, and this is what its number means now |
| `to_id` = `NULL` | this pointed at a row that is gone; left alone, **never matchable** |
| no row at all | raised after the switch, so it is already in the new space |

Telemetry then asks three questions in order, and every one is answered from data:

1. Did the repoint rule on this trip? Its verdict decides — including the `NULL` verdict, which is
   a permanent no rather than an invitation to guess.
2. No verdict, but the switch has happened for this company? Then the trip was raised afterwards
   and the column holds a Fleet id.
3. Otherwise the column still means `transport_vehicles`, and the plate is read **there** and
   nowhere else.

Your three cases keep working unchanged, and there is nothing left to remember later.

The ledger also makes the repoint **reversible**, which it was not. Every `from_id` is recorded.

## 3. Your half-one was not only data

You called the stale `legacy_transport_vehicle_id` a data problem, and it is — but the code made it
invisible, and that part is mine. A trip on the **right** truck found no candidate and the reading
was dropped through the "idling in the yard" branch, which looks identical to a healthy idle truck
on screen. That is why step 8 stayed blank rather than showing an error anyone would chase.

So the plate now **finds** candidates as well as confirming them. `transport_vehicles` is unique on
(tenant, normalised plate), so it adds at most one id and costs one indexed lookup.

The effect is that step 8 works **before** the mapping is repaired, not after. I tested it against
our data with `legacy_transport_vehicle_id` still NULL and the trip on `transport_vehicles#35`:

```
same truck    (transport_vehicles#35)     expect publish   got publish   OK
wrong truck   (transport_vehicles#36)     expect block     got block     OK
dangling id   (= Fleet id 1)              expect block     got block     OK
```

Rolled back afterwards; nothing written to the dev database.

## 4. The reconcile message that sent you looking for nothing

You were right that it is wrong, and it is wrong in a way that mattered: **one match is not an
ambiguity.** One match means the truck is in Fleet, the plate agrees, and only the stored link is
wrong — which is precisely what a reseed leaves behind. It is repairable without a judgement call.

`stos:reconcile-fleet` now separates three outcomes instead of printing one sentence for all of them:

- **no Fleet vehicle carries the plate** — the migration did not insert it; re-run the migration
- **one carries it** — repairable; `--relink` fixes `legacy_transport_vehicle_id` and nothing else
- **more than one carries it** — genuinely ambiguous; still only reported, still needs a person

`--relink` also refuses when the Fleet vehicle is already linked to a legacy row that **still
exists**. Two live legacy rows competing for one Fleet vehicle is a decision, not a repair.

## 5. The dry run no longer reports "0 rows" and mean two different things

You are right that "0 on all four columns" reads as *nothing to do* when it means *the map matches
nothing*. It now says which one:

```
Nothing can be moved: every reference points at a legacy id the mapping does not cover.
  That is usually a stale `legacy_transport_vehicle_id` rather than finished work.
  Run `php artisan stos:reconcile-fleet` — and `--relink` if it reports repairable links.
```

And `--apply` now **refuses** when any reference cannot be mapped, rather than silently stranding
it. `--force` overrides it, and says what force means before you use it.

---

## Eight of my own tests were passing because of this bug

Worth saying plainly. `TripTimelineTest` pointed the demonstration trip at the Fleet vehicle's own
id with no `transport_vehicles` row behind it — which is not what the live data looks like, and is
the exact arrangement the self-confirmation was about. So they passed by asking Fleet's table
whether a Fleet id was that Fleet vehicle.

The setup now builds the world as it is: a legacy row per truck, in an id range deliberately clear
of Fleet's, with the trip pointing at it — plus an assertion that the two ranges do not overlap, so
these cannot quietly go back to proving nothing.

**Suite: 1,438 passing, 0 failures.** New cases: same truck, wrong truck, dangling id, stale link,
repointed trip, stranded trip, trip raised after the switch; plus the ledger, the strand guard and
both `--relink` outcomes.

---

## The order you set, unchanged

```bash
php artisan migrate                                  # adds fleet_reference_repoints
php artisan stos:reconcile-fleet                     # now says repairable vs ambiguous
php artisan stos:reconcile-fleet --relink            # repairs the stale links only
php artisan stos:repoint-trip-fleet-refs             # dry run — real numbers this time
php artisan stos:repoint-trip-fleet-refs --apply     # at the moment your readers switch
```

Step 8 does not wait for any of it. **Re-walk it whenever suits you** — all three cases should
behave, and the mapping repair is now a tidying step rather than a prerequisite.

Holding `--apply` was the right call and it has now paid twice.

---

## Two things in your documents that I have deliberately not touched

`KNOWN-GAPS-for-signoff.md` is yours and it goes to the owner, so I am not editing it on the back
of my own claim that the thing is fixed. Two places will need you once you have re-walked:

- the row **"Connecting a tracker to a specific trip"**, which currently reads *"half done… the
  vehicle records the two systems share are still out of step"*. The records are still out of step;
  it is no longer what blocks the demonstration.
- the paragraph beginning **"That judgement has already paid for itself"** — the remaining case it
  describes is closed.

I have updated `registry-defects.md` under D-116 with what changed, and left your two notes as
they are. The general-lesson line I wrote there is about me, not about the defect: D-109 and both
rounds of this one were all the same gap between an argument and a mechanism.
