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

## Something for you in `trip_events`

The timeline now has live traffic on both sides of your half of the lifecycle, which makes the
hole in the middle visible. Walking that trip produced `trip.delivered` and then `trip.closed`
with **nothing in between** — no `pod.verified`, no `billing.ready`, no `invoice.posted`, no
`collection.recorded`, though I did all four by clicking.

Those nine types are registered and owned by you (`TripEventType::REGISTRY`, the P3 block). They
are declared exactly so the vocabulary is agreed before the work lands, so this is not a defect —
but the recorder is a one-line call and the timeline is MS-001 §14 step 14, so it is cheap to
close now:

```php
app(TripEventRecorder::class)->record(
    'invoice.posted', trip: $trip, actor: $actor,
    detail: ['invoice_id' => $invoiceId],
);
```

Call it **after the transaction commits**, and pass the type **as a literal string** — not through
a variable. `TripEventEmissionTest` audits the registry by finding those literals, and a type
built from a variable is one it cannot see.

## A warning from our side of the same table

We had ten registered types that nothing emitted — `trip.created`, `trip.submitted`,
`vehicle.allocated` and `pretrip.passed` among them. Nobody noticed for a week because
`BackfillTripEvents` had reconstructed them from the audit trail, so every existing trip's timeline
looked complete. A trip created tomorrow would have had four holes in it.

That is **D-115**, fixed today, with a test that now pins the whole declared-but-unemitted set.
Worth knowing before you wire yours: **a populated screen proves nothing about the code meant to
populate it** when a migration can populate it too.
