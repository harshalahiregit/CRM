# Zafar — "feedback" is yours, and nobody has specified it

**From:** Mohammad (Person 1 — Core & Operations)
**Date:** 2026-09-17
**Not a defect. A gap with an owner's name on it, and the name is yours.**

---

## Why you are hearing this from me

I built delivery this week (STT-007, RTM STOS-REQ-OPS-010). While checking my work against the
demonstration script I hit this:

> **STOS-MS-001 §14, step 11** — "Record delivery, **feedback** and POD."

Delivery is mine. POD is yours. Feedback I could not place, so I asked the owner rather than
guessing, and the answer is in a document I had not read:

> **STOS-TM-001 §8, Domain Ownership Matrix**
> `Feedback / Complaints / CAPA | Person 3 | Quality`

So it is yours. I am telling you rather than filing it as out of scope, because "out of scope"
reads as a decision somebody made, and this is not that.

---

## The part that matters

**Nothing in the package specifies it.**

I checked every document I have:

| Where I looked | What is there |
|---|---|
| Step 11 `DB_Registry` (DB-001…DB-020) | no feedback table |
| Step 11 `DB_Fields` | no feedback field on any table |
| Step 11 `API_Registry` (API-001…API-015) | no feedback endpoint |
| Step 11 `Permissions` (PERM-001…PERM-013) | no feedback row |
| Step 11 `Event_Registry` (EVT-001…EVT-012) | no feedback event |
| RTM | no `STOS-REQ-*` for feedback |
| Step 3 FRS (`TRP-P0-001…019`) | `TRP-P0-013` is POD capture; none is feedback |
| Step 3 BRWM | no rule mentions it |
| TM-001 §8 | **assigns it to you** |
| MS-001 §14 | **requires it in the 30 September demonstration** |

That is the whole problem in two rows: it is **required for the demo and defined nowhere.**

TM-001's own §8 lists it beside "Complaints" and "CAPA" under Quality, and MS-001 §8's Delivery
criterion reads "Delivery, feedback and POD can be recorded" — so the intent is clearly a
customer's verdict on the delivery, captured at the point of delivery. But intent is not a field
list, and I am not going to infer one for a table in your section.

---

## What I would suggest

Raise it as a defect in **your band (D-300…)** against MS-001 §14 step 11, and put the question
to the owner before building anything:

- What is a feedback record — a rating, free text, a complaint category, or all three?
- Is it per trip, per consignment, or per delivery?
- Who records it — the driver, the operator, or the customer through a portal?
- Does a negative one raise an exception or a CAPA, and if so under which rule?

None of those has an answer in the package, and each one changes the table.

---

## What I did on my side

`TransitScope::EXCLUDED['feedback']` records it, citing TM-001 §8 and naming you, so anyone
reading the delivery code finds out where it went instead of assuming it was forgotten. There is
a test asserting that note stays accurate (`TransitTest::test_feedback_is_recorded_as_person_3s_and_not_as_out_of_scope`).

My delivery endpoint **refuses** any feedback-shaped field rather than silently dropping it, so
nothing lands in my table by accident and has to be migrated out of it later.

Nothing else from me. It is your section and I have not touched it.
