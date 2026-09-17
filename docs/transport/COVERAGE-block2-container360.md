# Coverage — Block 2, Container 360 / Digital Passport

**Author:** Person 1 · **Date:** 2026-09-17
**Re-read from source for this block:** STOS-CTD §§3–12, 31–35, 69–79; RTM `CTD-001…022`;
**STOS-MS-001 §8, §9, §14**; **STOS-TM-001 §8, §9, §11** (the last two supplied by the lead —
they are not in the 22-document package and I had never read them).

Checked **three ways**: the code exists · it matches the source · **a user can reach it**.
A requirement whose only proof is an endpoint and a test is marked **PLUMBED**, not BUILT.

---

## 1. BUILT — code, source and a click path

| ID | P | What satisfies it | Click path |
|---|---|---|---|
| **CTD-001** | P0 | Normalised lookup; `ABCD1234567`, `abcd-123456-7`, `ABCD 1234 567` all resolve | Containers → type the number → row → **Open 360** |
| **CTD-002** | P0 | Customer on the chain, from consignment → order → client | Passport → *Where this container sits* → Customer |
| **CTD-003** | P0 | Order on the chain, with service type; opens the order | same panel → Transport order → **Open** |
| **CTD-006** | P0 | Vehicle registration and type | same panel → Vehicle |
| **CTD-007** | P0 | Driver name and licence class | same panel → Driver |
| **CTD-021** | P0 | One chronological timeline merging container, consignment and trip audit, newest first, filterable by source | Passport → *Everything that has happened* |
| **CTD-022** | P0 | Tenant-scoped at every lookup; cross-tenant reads as "no such container"; no customer grant | proven by test, and by the client role getting 403 |

**Also built, beyond the CTD list** — from MS-001 §14 and TM-001 §8:

| Source | What | Click path |
|---|---|---|
| **MS-001 §14 step 5** | *Dispatch eligibility* — pre-trip readiness, count and blockers. **Added after reading §14**; my approved proposal did not name it | Passport → *Ready to leave?* |
| **CTD §12** | Status **explained**, not shown. "On trip TRP-…, checked and cleared to leave." + next action | Passport → banner under the header |
| **CTD §76** | Lifecycle instances — current attachment prominent, prior ones listed | Passport → *Where it has been* |
| **TM-001 §8** | Universal search: container, trip, order, consignment, customer reference, vehicle, driver — one box | Containers → type any identifier → banner → **Open →** |

## 2. PLUMBED, NOT BUILT — reported, deliberately not rendered here

**CTD-016** custody · **CTD-017** POD · **CTD-019** billing · **CTD-020** invoice/collection — all **P0**.

The passport shows **counts and state** and links to the trip, which already renders Person 3's
panels in full. It does not re-render them. Reported honestly as *plumbed on this screen*: the
requirement is met by the trip page, and Container 360 proves the link exists rather than
duplicating a boundary that took two blocks to establish.

*Click path:* Passport → *Paperwork and money* → "Open TRP-… to work on these →".

## 3. BLOCKED ON PERSON 3 — not our gap

| ID | P | State |
|---|---|---|
| **CTD-004** LR · **CTD-005** DO | P0 | **Blocked, not missing.** D-41 ruled LR and DO stay DOCUMENTS in `transport_documents`. What blocks them is P3 adding `TransportDocumentEntity::CONSIGNMENT` and `'delivery_order'` to ENUM-006 — already requested in `REQUEST-person3-document-entity.md` with the owner's written approval attached. **No entity has been built for either**, deliberately |

## 4. NOT BUILDABLE — no entity anywhere, logged not dropped

| ID | P | Owner | Why |
|---|---|---|---|
| CTD-008 compliance | P0 | P2/P3 | vehicle/driver compliance has no read contract — `FleetResourceGateway` carries only `markDispatched()` (D-100) |
| CTD-009 gate/port | P1 | Product | no entity — D-42 |
| CTD-010 GPS | P1 | P2 | telemetry is P2's; no contract |
| CTD-011 temperature | P0 | Product | no entity — D-52's territory |
| CTD-012 excursions | P0 | Product | `trip_exceptions` has a table and no model; the engine was never completed |
| CTD-013 CAPA | P0 | P3 | SIRE owns CAPA; no container link |
| CTD-014 urgent-trip | P1 | Product | no entity — D-43 |
| CTD-015 fuel | P1 | P2 | Fleet's; no contract |
| CTD-018 driver feedback | P0 | Product | no entity |

**None of these renders an empty panel.** CTD §9 lists thirty sections; nine have no data, and an
empty panel implies the feature exists — the rule the consignment drawer already follows.

## 5. DEFERRED with a reason

- **CTD §77 snapshots** ("preserve rule versions, important snapshots") — a new table for
  speculative use. The audit stream already gives an immutable record. Ruled defer 2026-09-17.
- **CTD §70 customer-facing passport** — stays out until D-46's scope narrowing is real. Shipping
  a container-keyed customer screen on an unenforced scope is exactly the leak D-46 describes.
- **CTD §69 role-based views** (seven roles) — one staff view now.
- **CTD §78/§79 source and freshness** — every row this passport shows is written by this module,
  so "source: Container/Consignment/Trip" is shown and freshness is the event time. The feed-based
  sources §78 anticipates (GPS, sensor, accounting) have no producer yet.
- **CTD §80 data conflict** — needs two systems providing the same fact. There are none.

## 6. MS-001 §14 — the demonstration script, step by step

| # | Step | State |
|---|---|---|
| 1 | Search by container number | **BUILT** |
| 2 | Open Container 360 / Passport | **BUILT** |
| 3 | Customer, order, LR/DO, trip | **BUILT except LR/DO** (blocked on P3) |
| 4 | Recommended vehicle/driver + allocation reason/score | vehicle/driver **BUILT**; **reason/score is P2's** (TM-001 §9: "Person 2 owns recommendation/scoring") and has no read contract — D-100 |
| 5 | Compliance and dispatch eligibility | **eligibility BUILT**; compliance not ours |
| 6 | Document status and handover chain | **PLUMBED** — reported, links to the trip |
| 7 | Dispatch/trip progression | **BUILT** |
| 8 | GPS/temperature/generator | not buildable |
| 9 | Exception/CAPA | not buildable |
| 10 | Delivery, feedback, POD | POD **PLUMBED**; feedback not buildable |
| 11 | Billing Ready or exact blocker | **PLUMBED** |
| 12 | Invoice linkage | **PLUMBED** |
| 13 | Native CIA/management exception | P3's |
| 14 | Complete timeline/audit history | **BUILT** |

**Eight of fourteen steps run end to end on our data today.** Four more are one link away on P3's
screens. Two need entities nobody has built.

## 7. Diffed against my own approved proposal

An approved proposal is not a source — four of seven container fields were wrong last time, and
the source check could not catch them because they were never in the source. So:

| Proposal said | Built | Why the difference |
|---|---|---|
| six sections | **seven** | §14 step 5 asked for *dispatch eligibility*. Found by checking the script rather than assuming, exactly as instructed |
| "CTD-004/005 defer — a block of their own" | **blocked on P3, citing D-41** | The lead corrected the framing and was right: they are not unbuilt entities with no home. One reads as our gap, the other as a handover in motion |
| search "I'd also accept…" as an offer | **built, and it is the demo's first step** | Approved; scoped to exact matches only |
| no migration | **no migration** | held |
| 3 days | **1 day** | The passport needed no new storage, and the search reused normalisation that already existed |

**One thing built that the proposal did not mention:** the timeline filters by source
(CTD §35 asks for exactly this). Cheap once the source tag existed.
