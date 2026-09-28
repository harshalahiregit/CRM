# Four decisions needed before 30 September

**From:** Person 3 (Finance, Documents, Billing, Collections)
**Raised:** 17 September 2026 · **Updated:** 19 September 2026
**Deadline:** 30 September 2026 — **11 days**
**Read time:** 4 minutes. Each question has a recommended answer; "yes to all four" is a
valid reply.

---

## Why this page exists

These questions are recorded in `registry-defects.md` among 59+ entries. That file
is the right place to keep evidence and the wrong place to ask for a decision, so the ones
that are actually blocking work are pulled out here.

None of them is a developer's call. Each is blocking something, one of them is getting more
expensive every day, and all four can be answered in a sentence.

---

## 1. What is the difference between a trip cost and a trip expense?

**Defect:** D-58 · **Needs:** Product + Finance · **Blocks:** SNG-TRN-017, SNG-TRN-018

Step 11 declares two LOCKED tables with the same owner and the same source reference:

```
DB-006  trip_costs     "Canonical trip cost facts"       LOCKED  Finance Control  FRS-CST
DB-008  trip_expenses  "Trip-linked operating expenses"  LOCKED  Finance Control  FRS-CST
```

It never says how they differ. The registry is markedly richer on *expense* — PERM-008
`submit`, PERM-009 `approve`, ENUM-005 `expense_approval_status` — and gives *cost* two
fields and no workflow.

**Why a developer cannot decide it.** Guessing produces one of two failures: one table that
later collides with the other LOCKED entity, or two tables that both feed SNG-TRN-018 and
double-count the margin. Its acceptance criterion is "revenue, cost and margin reconcile to
source transactions", so a wrong answer here is a wrong profit figure that looks right.

**Recommended answer:** *An expense is a claim a person submits and someone approves. A cost
is the canonical fact used for margin. An approved expense becomes a cost row, carrying the
expense id as its source.*

That reading explains every asymmetry above, and `trip_costs` was built to accept it — it
already has a `source` column with an `expense` value declared and deliberately unreachable.

**If yes:** 017 and 018 start immediately. **If no:** say which way round, and the same is true.

---

## 2. Which CRM users hold Step 11's nine roles?

**Blocker:** BLK-10 · **Needs:** Product + Security · **Affects:** every gated action

Step 11's permission matrix is written against nine roles — CEO/Owner, Operations,
Dispatcher, Accounts, Approver, Driver, Customer, Supplier, Admin.

Sangoe users carry `role` (admin, staff, client, vendor, third_party_vendor, company) and
`internal_role` (a `staff_roles` slug). **No document maps one onto the other.**

**What that means today.** Transport ships a `ROLE_MAP` that is the single inferred element
in the permission layer. It is deliberately conservative — it grants only what a Sangoe role
plainly implies and denies every ambiguous case — and it is flagged in the code. But it is a
developer's guess about **who may approve money**.

It currently decides who can approve a trip advance, prepare billing, verify a POD and
record a collection. Two features are already built and unreachable because of it: the
approved-exception waiver that lets a trip bill without a POD, and the advance override.

**Recommended answer:** *Confirm the existing conservative map, or send a corrected one.*
Confirmation is a five-minute read of one file and converts a guess into a decision.

**If left open:** the module ships with an unreviewed authority model. That is the finding a
release review will stop, not a bug a developer will find.

---

## 3. Which vehicle system survives?

**Defect:** D-62 · **Needs:** Person 1 + Person 2 · **Severity: this one is growing**

Two vehicle and driver systems are **both live on production**:

| | Operations | Fleet |
|---|---|---|
| Tables | `transport_vehicles`, `transport_drivers` | `vehicles`, driver directory |
| Read by | allocation, pre-trip checks, dispatch | telemetry, fuel, tyres, maintenance |
| Built by | Person 1 | Person 2 |

TEAM-CONTRACTS §1a records the first pair as **Person 1's placeholder, meant to be replaced**
when Person 2 built Fleet. Person 2 built it — beside the old one rather than into it — and
nobody retired the placeholder. A coordination gap, not a coding error.

**What it costs.** A vehicle added in one is invisible to the other. A trip can be allocated a
vehicle the fleet system has never heard of, and a vehicle can accumulate fuel and maintenance
history that allocation cannot see. Nothing fails loudly; the data just diverges.

**Why it is now urgent.** A week ago this was a code change. Production already holds rows in
both tables, so **whichever way it is decided, someone owns a data migration** — and that
migration grows with every row written to the losing side.

**Recommended answer:** *Fleet absorbs operations.* It matches the documented intent, and the
fleet model is the richer of the two. Allocation and pre-trip checks get repointed; the
placeholder tables are migrated and retired.

**Already done (17 Sep):** the sidebar showed two identical "Transport" entries and now shows
one. That fixed the confusion, not the split — and it makes the split *less* visible, which is
the one risk of having fixed it.

**Person 2 has now taken the recommended answer and built its half (17 Sep).** Fleet absorbs
Operations. `vehicles` gained every identity column the placeholder had, the data move refuses
ambiguous plates rather than guessing, and the two handovers Person 2 owed are closed: **C-05**
`markDispatched()` and **C-06** Fleet costs into `trip_costs`. Both suites pass together —
1,075 tests. Steps, order and rollback are in `D-62-RESOLUTION-PLAN.md`.

This does not close the question, it narrows it. **What is still open is Person 1's:** repoint
allocation and pre-trip to `FleetService::getEligibleVehicles()`, then retire the placeholder
tables after a clean week. And one decision inside D-62 still needs an answer from Person 1,
because his board consumes the result: **does an expired driver licence block allocation, or
only score it down?** Fleet currently offers the vehicle and flags the licence — the truck is
roadworthy and swapping drivers is the smaller decision — but the spec reads as a hard block.

---

## 4. Is Feedback in scope for 30 September? *(added 19 Sep)*

**Needs:** Product · **Affects:** step 10 of the demonstration script

MS-001 §14 step 10 reads *"Record delivery, feedback and POD."* Delivery and POD are built.
**Feedback has no entity, no field and no requirement ID anywhere in Step 11 or Step 12** —
zero occurrences of `feedback`, `complaint` or `CAPA` across every sheet of both. Person 1
found the same gap independently on 19 September.

TM-001 §8 assigns the domain to Person 3. So it is assigned to a developer, absent from every
authority document, and on the demonstration script.

**Recommended answer:** *strike "feedback" from step 10 for R1 and record the domain as
deferred.* Building it means inventing an entity, a vocabulary and an acceptance criterion —
which is the one thing the developer constitution forbids.

**If left open:** on 30 September it reads as a developer who did not deliver their domain,
rather than a domain nobody specified.

---

## One thing that is not a question, but should be seen

Building SNG-TRN-011 through 016 required **constructed values in eleven places** — vocabularies,
permission rows, event payloads and MIME limits that a LOCKED registry requirement depends on
and the registry never defines. Recorded as D-57 through D-61.

Each is confined to a single file and flagged in the code. None is a guess about behaviour;
each is the narrowest thing that satisfies a rule Step 11 states but does not equip.

They are listed here because eleven is enough that somebody with authority should confirm them
before the deadline, rather than discover them in a release review afterwards.

---

## What is already finished and live

Advances · Trip costs · POD capture and verification · Billing trigger · Collections —
backend, API and screens, deployed, **934 Transport tests passing**.

Handovers are built and waiting on the other side, not on us:

- **Accounts** — fill `trip_bills.invoice_id` (C-10), post the receipts (C-11). Queue and doors built.
- **Person 1** — wire the transit and closure state edges (C-09, C-12). POD verification starts
  firing with no change on our side.
