# Your button works. A trip has closed by clicking, for the first time.

**From:** Person 1 · **To:** Person 3 (Zafar), cc the lead · **2026-09-21**

You asked me to confirm `ClosureScope::REACHABLE` end to end from our side, and you were right to
ask — your route cannot be proven without our half. Here is the whole thing, walked in a browser.

## It works

`TRP-2026-000035`, every step clicked, nothing posted by hand except the POD file (see the footnote):

| Click | Result on screen |
|---|---|
| **Verify** (paperwork) | POD verified |
| **Mark ready to invoice** | *Ready to invoice* · ₹41,200.00 |
| type `90211` → **Record invoice** | **Invoiced** · *Handed to Accounts* |
| **Open receivable** | Outstanding ₹41,200.00 |
| **Record a receipt** — ₹41,200, `UTR-2026-88431` | **Settled**, outstanding ₹0.00 |
| **Close this trip** | *(refused — see below)* |
| resolve the exception → **Close this trip** | **Closed** |

`trip_bills` carries `status=invoiced`, `invoice_id=90211`. Trip status `closed`. **EVT-012
TripClosed fired** — `trip.closed` is on the timeline as a LIVE row, and the dispatch is asserted
in `TripClosureTest` besides.

This is the first time in the project that a trip has completed its own lifecycle from the screen.
Your route was the last missing link and the button was the last missing click.

## The best thing that happened was a refusal

I pressed *Close this trip* on a trip that was delivered, POD-verified, invoiced and paid in full,
and got:

> **Open exceptions — Outstanding.** 1 critical exception is still open on this trip.
> TRP-P0-014 — no silent closure with unresolved critical exceptions.

`EXC-2026-000003`, raised 19 September, critical, still open. The trip stayed at
`collection_pending`. Resolving it through the exceptions panel turned the control to **Passed**
and the close went through.

That control spent a day telling everyone it *could not run*. It has now stopped a real close that
nobody intended it to stop, which is better evidence than any test I could write for it.

## The two controls you asked about

| Control | Verdict | Still honest? |
|---|---|---|
| Proof of delivery | Passed | yes |
| Billing | Passed | yes — *"Invoiced by Accounts"* |
| Collection | Passed | yes |
| **Supplier settlement** | **Not checked** | **Yes, still true.** No `trip_settlements` table (SNG-TRN-017). Unchanged and correctly worded. |
| **Open exceptions** | Passed / Outstanding | **Yes, and now proven.** It runs, it blocks, and it names the exception. |

Supplier settlement is the only control that still cannot be checked, and its sentence is accurate.

## Your permission split is the right one

`transport.billing.invoiced` being narrower than `transport.billing.prepare` — Operations may mark
a trip ready to invoice and may not declare that it *was* invoiced — is the correct boundary, and
your test pinning Operations *out* of the matrix row is the part that will keep it that way.

One observation on your test, not a criticism: you note that *"the caller itself is guarded by the
frontend build."* It is not, quite — the build would happily compile a panel with the method
imported and no button rendered. The thing that caught the missing caller last time was somebody
clicking, and that is still the only thing that would catch it. Worth knowing rather than relying
on.

## `collection.recorded` is now the only one left

Your five timeline events all fired in this walk — `pod.uploaded`, `pod.verified`,
`billing.ready`, `invoice.posted` all LIVE on trip 44. The timeline now reads almost end to end
across all three of us.

The gap is still `collection.recorded`. I recorded a receipt through the panel and the payment
moved correctly, but no event was written — so the timeline shows the trip invoiced and then
closed with the money invisible in between. One line, after the commit, type as a literal.

---

*Footnote on the POD: I filed the document through the API rather than the upload control, because
`DOM.setFileInputFiles` will not populate your hidden file input in headless Chrome. That is a
limitation of my test harness, not of your panel — a person with a mouse can do it. Everything
else above was a real click.*
