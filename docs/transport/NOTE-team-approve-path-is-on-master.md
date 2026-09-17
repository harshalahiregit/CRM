# To Zafar (P3) and Shivam (P2) — the trip approve path is on master

**From:** Person 1 · **2026-09-17** · master `3c6fe3dc`

---

## The one that matters: a trip can now be approved

**Zafar — this is the one you have been waiting on.** Until today no trip created through the
application could ever reach `approved`: `viability_pending` had no outgoing edge, so allocation,
pre-trip, dispatch and everything downstream of them were built but unreachable by a real user. The
demo only walked because the seeder wrote the status column directly.

Both of `viability_pending`'s exits are now wired and on master:

| | | |
|---|---|---|
| **STT-002** | `viability_pending → approved` | `PATCH /api/transport/trips/{id}/approve` |
| **STT-003** | `viability_pending → draft` | `PATCH /api/transport/trips/{id}/reject` |

- **Permission** is `transport.trip.approve`, mirroring PERM-003 exactly — Owner, Operations,
  Accounts, Approver, Admin. **Dispatcher is denied**, deliberately; reject reuses the same key
  (D-66).
- **EVT-004 `TripApproved`** is emitted, payload `trip_id, approved_by`. Emit-only, no listeners
  yet — the same seam shape as EVT-001 and EVT-002, so subscribe when you need it.
- Rejection requires a reason, stores it on the trip, audits it, and the trip can go round the
  loop as many times as needed.
- **The margin gate is NOT enforced** (D-64). STT-002's precondition "Margin policy passed" needs
  SNG-TRN-008, which is unbuilt and blocked on SNG-TRN-005's rate card — a P0 ticket with no
  assigned owner. Approval today checks state and permission, nothing commercial. There is a test
  written to fail the day viability lands, so it cannot be quietly forgotten.

**Zafar:** `TripStatus::TRANSITIONS` now has nine keys. Your four edges and my two both landed in
the same snapshot test on the same day and we both updated it on purpose — it is doing its job.

## Also on master

- **Consignment deep-link fix** — the "What is being moved" card on a trip led to *Page not found*.
  It now deep-links into the consignment list and opens the drawer.
- **Link guard** — every `/app/transport/...` target in the module and the shared nav files is
  asserted against the route table. It fails with file, line and the unregistered path.
- **D-54 guard** — no Transport fixture may draw a unique identifier at random again.
- **D-63 (was D-58)** — the write-up of why the chain was unreachable, and that the seeder was
  hiding it. The seeder now walks the real transitions; both `forceFill(status)` calls are gone.

## Defect numbers now have per-person bands

P1 and P3 both allocated D-58…D-61 on the same day, on different branches, to different defects.
Mine were renumbered to **D-63…D-66** (master is the shared baseline); Zafar's are unchanged and
every reference still resolves. TEAM-CONTRACTS now assigns:

**P1 from D-100 · P2 from D-200 · P3 from D-300.** D-1…D-66 are frozen — never renumber one, they
are cited from code across all three sections.

---

## Shivam — one process point, and a question

**The outcome is fine and probably better than what I did.** You repointed
`/app/transport/vehicles`, `/vehicles/:id`, `/drivers` and `/drivers/:id` at Fleet's components.
That goes further than the owner's ruling, which was "hide, not delete" — my four page files are
now unreachable and their lazy imports in `routes.jsx` are dead code. I have left all of it exactly
as you wrote it and changed nothing.

The ask is only this: **say it in the group next time rather than leaving it to be found in a
diff.** It is the same point you already accepted about `transport_trips`, and it costs one line.

**The question:** D-62 step 5 — retiring P1's placeholder — is now partly done by you. Do you want
my four page files (`TransportVehicles`, `TransportVehicleDetail`, `TransportDrivers`,
`TransportDriverDetail`) **deleted now**, or **left dormant** until allocation is repointed at
Fleet? Either is fine by me; I am not touching them without your word.

**One file that must NOT go in any version of this:** `MasterFormFields.jsx`. Four unrelated panels
import it — Pretrip, Allocation, Dispatch and Documents.

## Known and expected: Fleet, Drivers and Workshop read zero

The demo vehicles and drivers are still in `transport_vehicles` / `transport_drivers`, and the
data-move migration `2027_01_02_000002` has **not been run** — the owner held it because
`stos:reconcile-fleet` does not exist yet.

So those three screens show nothing. **That is correct, not broken, and nobody should "fix" it by
seeding copies into the fleet tables** — two sets of the same trucks in two tables is the
duplicate-master-data failure this whole arrangement exists to prevent.

Allocation still reads P1's tables, so the dispatch chain works today. Repointing it onto Fleet is
the next piece of work and starts now.
