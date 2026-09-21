# Shivam — read this before you next run `php artisan migrate`

**To:** Shivam (Person 2 — Fleet) · **Copy:** Zafar (Person 3) — your machine is armed too
**From:** Mohammad (Person 1)
**Date:** 2026-09-18 · **Urgent.**

---

## The hazard

`backend/database/migrations/2027_01_02_000002_move_transport_masters_into_fleet.php` is
**pending on your machine**, and on Zafar's, and anywhere this branch deploys.

**A plain `php artisan migrate` runs it.** Not a flag, not a target, not a script — the ordinary
command you run after a `git pull`, or to apply a column of your own that has nothing to do with
Fleet. Nobody has to do anything wrong to trigger it.

## What it does when it fires

It moves `transport_vehicles` and `transport_drivers` into the Fleet masters and **repoints every
foreign key that pointed at them**:

- `transport_trips.vehicle_id`
- `transport_trips.driver_id`
- `trip_assignments.vehicle_id`
- `trip_assignments.driver_id`

The Transport module still reads `transport_vehicles` and `transport_drivers`. So every repointed
row becomes an orphan: trips silently lose their vehicle and driver on screen. No error, no
warning — the fields just go blank.

## It cannot be rolled back

`down()` is an empty method with a comment explaining why: the rows are now the live masters and
may have picked up history since, so deletion would be wrong. That reasoning is sound. The
consequence is that **`php artisan migrate:rollback` will not undo this.**

## And the part that makes it worse

**It is already marked RUN on my machine**, because I hit it yesterday. So it will never fire
here again, and my database looks clean.

Yours doesn't. Mine looking fine is exactly why nobody would notice before it bites you.

---

## Our side is repaired — this is a hazard, not a crisis

I ran it by accident yesterday applying two unrelated columns. The Transport demo lost its
vehicle and driver; 4 trips and 1 assignment were affected.

**It is fixed.** Re-running `TransportDemoSeeder` rebuilt the demo through the real services, and
there are zero orphaned live rows on all four table/column pairs. The demo works, the walkthrough
works, and nothing is broken on my machine right now.

I did **not** delete the rows your migration inserted into `vehicles`, `driver_profiles` or
`stos_drivers` — two in each. They carry their `legacy_transport_*_id`, so they are identifiable,
and they are yours to reverse or keep. I have not touched your tables.

So please respond to the hazard, not to a fire. There isn't one here any more.

---

## The ask — and the choice is yours

**Please pick one today**, because every hour it sits pending is an hour somebody's routine
`migrate` can fire it:

1. **Guard it.** An opt-in check at the top of `up()` — an env flag, `STOS_FLEET_MIGRATION=1`,
   anything — so it refuses unless somebody deliberately asked for it. Smallest change, and it
   keeps the migration in the branch.

2. **Withdraw it** until the repoint is genuinely ready, and re-land it together with the code
   change that moves Transport's reads onto Fleet. This is the one I'd choose if it were mine,
   because the migration and the repoint only make sense as one deploy — but it is your call.

3. **Let it stand**, and say so. Then we all run it knowingly, on the same day, with the reads
   moved in the same change and everybody watching.

Whatever you choose, say which so Zafar and I know whether to keep checking `migrate:status`
before every `migrate`.

---

## One thing to know before you choose option 3

I measured this on 2026-09-16 and it is why I stopped the repoint then (**D-100**): Fleet's
`vehicles` table **reads zero rows** through the current Transport read paths. Moving the data
without moving the reads in the same change leaves Transport pointing at rows it cannot see.

That is not an argument against the migration. It is an argument that the migration and the code
change have to ship together, which is what option 2 buys you.

---

Everything above is also in the register as **D-109**, but I did not want this sitting in a
document waiting to be read.
