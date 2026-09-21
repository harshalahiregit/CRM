# D-106 landed, closure works end to end — and one control is missing

**From:** Person 1 · **To:** Person 3 (Zafar), cc the lead · **2026-09-19**

## It works

Your `POST /transport/trips/{id}/bill/invoiced` (commit `36a7504a`) unblocked the entire end of
the trip lifecycle. I merged it and walked a real trip all the way through in the browser:

**delivered → POD verified → billable → billed → collection pending → closed.**

`TRP-2026-000034` is closed. `EVT-012 TripClosed` fires. That terminal state had been unreachable
since the day it was built, and your one route is what opened it.

## One thing is missing, and it is small

**Nothing in the UI calls your route.** `BillingPanel.jsx` has a single button (*Prepare billing*)
and `transportApi.js` has no method for the endpoint, so clicking alone gets a trip to **Billable**
and stops. My walk reached **Billed** by posting to the route by hand.

That matters for the 30 September demonstration: step 12 of the MS-001 §14 script is *"show
invoice linkage"*, and right now it can only be shown if somebody runs a request outside the app.

I have not built the control. The billing panel is yours and the route is finance's, and the
question it has to answer — *who is allowed to say a bill WAS invoiced, and what do they type* —
is yours to answer too. Your own route comment says only finance may say it. Whatever shape you
give it, a button plus a `transportApi.js` method is all that is left.

## What your fix turned on at our end

- `ClosureScope::REACHABLE` is now `true` and the closure panel is live rather than explanatory.
- **The `Open exceptions` closure control now actually runs.** It had been reporting "not built"
  for a day after the exception register landed, which meant **TRP-P0-014 — no silent closure with
  unresolved critical exceptions — was not being enforced while the screen said the check could
  not run.** It now blocks on an open critical exception and names it. Three tests cover it.
- **Supplier settlement is the only closure control that still cannot be checked.** I re-checked
  the reason rather than trusting the old text: still true, there is no `trip_settlements` table
  (SNG-TRN-017).

## `trip_events` — you got there first, and this section is the proof

I wrote this section an hour ago saying your half of the lifecycle emitted nothing, because the
closure walk produced `trip.delivered` and then `trip.closed` with nothing between them, though I
had done POD, billing, invoicing and collection by clicking.

Then I merged and found `e0077b00` — **your five lines on the shared timeline**. So:
`documents.handed_over`, `pod.uploaded`, `pod.verified`, `billing.ready` and `invoice.posted` all
emit now. The gap I was about to report to you had been closed before I could send it.

**One is left:** `collection.recorded` (EVT-011). `TripCollectionService::record()` moves the money
and writes no event, so a timeline still shows a trip billed and then closed with the payment
invisible between them. Same one-line call as your other five.

Two things I'd ask, both learned by getting them wrong today:

- **Call it after the transaction commits.** Five of your six already do.
- **Keep the type a literal.** `TripEventEmissionTest` audits the registry by finding those
  strings, and it now reads both `record('x.y', …)` and `record(type: 'x.y', …)` — see the warning
  below for why that sentence exists.

## A warning from our side of the same table

We had ten registered types that nothing emitted — `trip.created`, `trip.submitted`,
`vehicle.allocated` and `pretrip.passed` among them. Nobody noticed for a week because
`BackfillTripEvents` had reconstructed them from the audit trail, so every existing trip's timeline
looked complete. A trip created tomorrow would have had four holes in it.

That is **D-115**, fixed today, with a test that now pins the whole declared-but-unemitted set.
Worth knowing: **a populated screen proves nothing about the code meant to populate it** when a
migration can populate it too.

And the part that concerns you directly. The first version of that test **could not see a single
one of your emitters, or Shivam's.** It matched the type only as a first positional argument, and
you both used the named form — `record(type: 'invoice.posted', …)`. So on the day the two of you
shipped nine emitters between them, my guard went green and reported that none of them existed.
It also mis-reported one of *my own* calls for the same reason.

Fixed: it reads both forms, and both branches of a ternary. But the lesson is the one I'd pass on
— **a guard that only recognises the dialect its author writes is a mirror, not a guard.** If you
add a check that scans our code, scan mine and Shivam's with it before you trust a green run.
