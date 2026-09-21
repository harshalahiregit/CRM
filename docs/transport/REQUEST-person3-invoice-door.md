# Zafar — one missing route is holding the whole trip lifecycle shut

**From:** Mohammad (Person 1 — Core & Operations)
**Date:** 2026-09-17
**Defect:** D-106
**Ask:** give `TripBill::markInvoiced()` a caller and a route.

---

## The short version

`EVT-012 TripClosed` — the event you have been waiting on since the 16th — **is built and is
emitted.** You cannot receive it, and the reason is one missing route on your side.

---

## What I found

Block 3 built STT-012 (`collection_pending → closed`). Before writing it I walked the chain
backwards to check the state was reachable, the way D-58 taught us to:

| Edge | Built | Whose | Can a user reach it? |
|---|---|---|---|
| `delivered → pod_verified` (STT-008) | yes | yours | **yes** — `POST /trips/{id}/pod/{doc}/verify` |
| `pod_verified → billable` (STT-009) | yes | yours | **yes** — `POST /trips/{id}/bill` |
| `billable → billed` (STT-010) | model method only | Accounts / yours | **NO** |
| `billed → collection_pending` (STT-011) | yes | yours | only via `billed` |
| `collection_pending → closed` (STT-012) | yes, today | mine | only via `collection_pending` |

`TripBill::markInvoiced()` at `backend/app/Models/Transport/TripBill.php:89` is the single door
into `billed`. **It has no caller.** Every other mention of it in the codebase is a comment —
including one of mine, and including the docblock in `TripStatus` that describes it as "the door
Accounts calls after they have posted".

Nobody can call it. There is no service method, no controller action and no route.

Your own `TripCollectionService::open()` is already honest about the consequence, which is how I
am confident this is a gap and not a misreading — it guards the move with
`TripStatus::canTransition()`, so opening a collection on a `billable` trip creates the
collection row and correctly declines to advance the status. The trip stays at `billable`.

**So no trip in this system can reach `collection_pending`, and therefore no trip can ever be
closed.**

---

## What I did NOT do

I did not add the route. `trip_bills` is your table, `markInvoiced()` is your method, and the
standing rule is that we do not edit another developer's file to make our own work reachable.

I have also left your code untouched everywhere else. Nothing in Block 3 writes to
`trip_bills`, `trip_collections` or `trip_documents`.

---

## What I built on my side, and what state it is in

STT-012 is complete and tested — API-009 verbatim, CTR-013's mandatory `closure_reason`,
PERM-005 including the Dispatcher denial, EVT-012 emitted after commit, and five closure
controls each returning a sentence.

It is marked **PLUMBED, not BUILT** in the coverage document, and
`ClosureScope::REACHABLE = false` states it in code. There is a test —
`TripClosureTest::test_nothing_in_the_application_can_reach_collection_pending` — that scans
`app/` and `routes/` for a caller and **fails the moment you add one**, with a message telling
whoever sees it to flip that constant and close D-106. That is deliberate: the day this stops
being true, somebody finds out immediately.

---

## The ask

One route and one thin action, wherever it fits your billing controller:

```
POST /api/transport/trips/{id}/bill/invoiced   { invoice_id }
  → TripBill::markInvoiced($invoiceId, $actorId)
```

`markInvoiced()` already does everything else — it sets the status, stamps the invoice id, walks
`billable → billed` and audits it. It needs a way in.

Two things worth deciding as you build it, both yours:

1. **Permission.** There is no registry row for it. `transport.billing.prepare` is the nearest
   thing you already hold, and PERM-005's set (Owner, Operations, Accounts, Approver, Admin) is
   the nearest quoted row. Your call — I have not assumed either.
2. **Idempotency.** Posting the same invoice twice should be a no-op, not a second transition.
   `TripBill::isInvoiced()` already exists.

---

## What becomes live the moment that lands

- `billed`, `collection_pending` and `closed` all become reachable for the first time.
- **`EVT-012 TripClosed` starts firing.** Payload is `trip_id` and `closure_timestamp`, exactly
  as the registry locks it, plus a `controls()` accessor telling you which closure checks
  actually ran. Two of the five (supplier settlement, open exceptions) report **not checked**
  rather than passed, because `trip_settlements` does not exist and `trip_exceptions` has a
  schema but no model — so do not read a `TripClosed` as evidence that a trip had no unresolved
  exception.
- MS-001 §9's first test scenario — "normal transport order from creation to invoice" — becomes
  walkable end to end.

---

## And while you are in there: C-09 is closed

You wrote that `TripDocumentService::verify()` could not fire until P1 wired STT-006 and
STT-007, and that nothing would need coordinating beyond us landing those two edges.

**Both landed on 2026-09-17.** Trips now arrive in `delivered`, and your POD edge starts firing
with no change on your side, exactly as you predicted. There is a test on my side
(`TransitTest::test_delivery_opens_p3s_pod_edge_which_was_previously_dead`) that asserts it.

One thing to know about how a trip gets there: recording a delivery is a **manual** act on
`transport.trip.deliver`, mirroring PERM-004 — Owner, Operations, Dispatcher, Admin. A **Driver
is deliberately denied** on that key and still holds `own` on PERM-010 for the POD itself. A
driver submits their proof; an operator confirms the trip is delivered, because that is what
unlocks your billing chain for everyone downstream.
