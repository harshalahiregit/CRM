# The new document package — what it changes, and what it does not

**Person 1 · 20 September 2026 · read-only assessment. No code written, nothing planned into a
migration, the repoint untouched.**

Everything below was measured against the running system and the old package rather than read off
the new documents alone. Where a claim is a checksum or a query, I say so.

---

## 0 · First, the shape of what arrived

**348 files, 109 unique documents, not 348.** Folders `946`–`952` hold 107 of them. **`953` and
`954` are the same complete set** — `954` differs from `953` only in where one file sits, and `953`
carries two the other seven folders do not:

- **`STOS-AUTH-REC-001` — Final Authority Reconciliation, Step 9–13 vs 61 Newer Documents.** This
  turned out to be the most important document in the package and it is the one sitting outside the
  main folders. More below.
- A zip of the whole v2.1 structure.

**Read `953`. The other eight folders add nothing.**

### The foundation has not moved — this is the single most important fact here

Every document our build rests on is **byte-identical** to the copy we have been working from:

| | |
|---|---|
| Step 9 — Master Product Constitution | **identical** |
| Step 10 — Claude Developer Constitution | **identical** |
| Step 11 — Canonical DB/API/State/Event Registries | **identical** |
| Step 12 — Developer Ticket Pack | **identical** |
| Step 13 — QA / CI-CD / Release Control | **identical** |
| STOS-CTD, STOS-DB, STOS-API | **identical** |
| `00_READ_ME_FIRST` | **identical** |

And `00_START_HERE.md` restates the authority order we have been working to:

> *"Step 9–13 remain the controlling baseline… The newer STOS-xxx-001/002 documents expand and
> operationalize the baseline. **They do not silently override Step 9–13.**"*

…along with *"Do not invent business rules"* and *"Do not add DB fields/tables, API contracts,
states or events ad hoc."* Our Hard Rule 1 and our authority chain survive this package intact.

---

## a) What has actually changed

66 new documents. Ignoring the ones that do not touch us, these are the ones that do.

### The four that matter

| Document | Why it matters to us |
|---|---|
| **STOS-CLP v1.0** | An entire new client-facing module, 32 sections, with its own 15-step September 30 slice |
| **STOS-MS-001 v1.1** | Replaces the milestone plan we have been working to — see (b) |
| **STOS-AUTH-REC-001** | An independent audit of Step 9–13 against the 61 new documents. It confirms much of our defect register and adds nine BLOCKERs |
| **STOS-CR-001** | The code-review standard whose absence we logged. It now exists and is usable |

### The shape of the other 62, which is the thing to understand about them

They are **governance and control frameworks, not specifications.** STOS-AGR-001's "Scope & Control
Areas" lists customer contract, rate card, route, SLA terms, urgent-trip rules, additional charges,
billing rules — and gives every one of them the *identical* entry:

> *"Defined owner, lifecycle/status, validation, evidence, reporting and escalation requirements."*

That tells us a rate card must have an owner and a lifecycle. It does not say what a rate is or how
margin is computed. The same template runs through the -001/-002 series. **They are real and useful
for process, and they are not the missing specifications** — with the exceptions in (d).

**STOS-CR-001 is a genuine exception and worth reading.** Its list of review anti-patterns includes
*"Reviewer approval based only on screenshots or code appearance without behavioral verification"* —
which is the rule we arrived at independently and wrote into TEAM-CONTRACTS this week.

### Two more new modules, for awareness

**STOS-DVR-002 — a Driver App / digital workspace.** Not in the September 30 slice. Noting it
exists so nobody is surprised by it later.

---

## b) MS-001 v1.1 against v1.0 — diffed, not read alone

The package ships both versions. I confirmed the package's v1.0 is the same as the one we built
from (one blank line apart), so the comparison is like for like.

**v1.1 is a restructure, not an addendum — and it is *shorter* than v1.0 while adding a portal.**
What it dropped matters as much as what it added.

### What is gone

| v1.0 section | Status in v1.1 |
|---|---|
| **§14 — September 30 Management Demonstration (the fourteen steps)** | **GONE** |
| §4/§5/§6 — per-developer milestone plans | Replaced by one responsibility table |
| §9 — P0 test scenarios | Gone |
| §15/§16/§17 — status board, Step-7 completion and approval | Gone |

**This is the most consequential single change in the package.** The fourteen-step demonstration is
the thing we have been building and walking against — `DEMO-ms001-s14-walk.md`, the presenter's
script, and the "10 of 14 working" scoreboard all trace to v1.0 §14. **v1.1 does not contain it.**

### What replaces it

v1.1 §8 (M4) defines the September 30 slice as a **sixteen-step client-facing sequence**:

> Client Login → Client Dashboard → Create Normal Request → Urgent Request + contract rules →
> Transporter Accepts → Vehicle + Driver Assigned → Client Sees Vehicle/Driver → Trip/Container 360
> → Milestone Timeline → Location/Tracking → Exception/Concern → Documents/Evidence → Delivery +
> POD → Client Feedback → Billing Ready/Invoice → Audit Trail

The back half (8–16) is largely what we have built, viewed through a client's eyes. **Steps 1–7 do
not exist in any form** — there is no client login, no client dashboard, no Transport Request
entity, no acceptance step, no portal.

### The calendar, which is the timeline answer

| Milestone | Dates | CLP objective |
|---|---|---|
| M1 | **12–13 Sep** | Freeze CLP boundary, entities, RBAC, API/event contracts |
| M2 | **14–18 Sep** | Portal foundation, client access, dashboard shell, request/booking, core portal APIs |
| M3 | **19–23 Sep** | Connect CLP to order, allocation, trip, milestones, documents, exceptions, tracking |
| M4 | 24–26 Sep | End-to-end vertical slice |
| M5 | 27–28 Sep | QC and test closure |
| M6 | 29 Sep | Management prototype |
| M7 | 30 Sep | Sign-off |

### §4 — what is now Person 1's that was not before

v1.0 gave each developer their own milestone plan. v1.1 gives one table, and ours reads:

> **Person 1** — *"CLP architecture, client request/order handoff, trip/milestone APIs,
> Container 360, portal integration contracts, universal traceability and end-to-end
> orchestration."*

Of that list, **Container 360 and universal traceability are already ours and already built.**
Genuinely new to Person 1:

1. **CLP architecture** — the whole portal's architecture, not a piece of it
2. **Client request → order handoff** — a new `Transport Request` entity and its mapping into our existing order
3. **Trip/milestone APIs** — the M01–M14 engine, which has no canonical definition anywhere (see (c))
4. **Portal integration contracts**
5. **End-to-end orchestration** across all three streams

Person 2 gets client-visible vehicle/driver assignment and telemetry visibility. Person 3 gets the
client dashboard inputs, document access, exceptions/CAPA, billing visibility and CLP test
coordination.

**Plainly: the architecture of a new module, plus the one engine in it that nobody has specified,
is now Person 1's, on top of everything Person 1 already owns.**

---

## c) What contradicts what we have already built

**The good news first, and it is substantial: nothing we have built is reversed.** No entity is
renamed, no ownership boundary moves against us, no state machine is rewritten, no search key is
withdrawn. Container Number is *reaffirmed* as the universal traceability anchor in CLP §1 and §27,
which is exactly what we built the entry point around this week.

The contradictions are of a different kind — **the new layer asks for things the canonical layer
does not contain**, and `STOS-AUTH-REC-001` says so itself. It raises nine BLOCKERs. Five land on us.

### B-07 — Container is not a canonical object. This is our D-113, confirmed.

> *"Step 9's canonical domain model does not list Container as a canonical object, and Step 11 has
> no container/consignment registry entry or container field found in its DB/API/event registries."*

We raised this weeks ago and built `SCHEMA-PROPOSAL-consignment-container.md` because Step 11 had no
entry. The package now calls it a **blocker requiring formal promotion into the constitution and
registries.** Our reading was right; the ruling is still owed. Everything we built on containers —
Container 360, the passport, the whole search entry point — rests on a foundation the package
itself now flags as canonically absent.

### B-02 — the Trip state machine. Our standing rule, now formally in dispute.

> *"Step 9 includes PRETRIP_OK, ARRIVED, POD_PENDING and SETTLEMENT_PENDING. Step 11 omits those
> states and uses a shorter sequence."*

That is precisely the split we resolved with our standing rule — **"Vocabulary from Step 9. Edges
from Step 11. A state becomes reachable only when something can gate it."** — which is why our
`TripStatus` declares `arrived`, `pod_pending` and `settlement_pending` and leaves them unreachable.
AUTH-REC-001 says this must now be settled formally and **Step 11 updated**. Our approach is
vindicated; it is no longer ours to keep deciding.

### B-01 — the Order state machine. We implemented one side of a conflict.

> *"Step 9 locks Order as DRAFT → QUOTED → CONFIRMED → ALLOCATING → READY → CANCELLED. Step 11
> instead defines draft → submitted → approved → rejected."*

Our `OrderStatus` is `draft / submitted / approved / rejected` — **Step 11's version.** The package
says neither should be guessed and both documents must be updated together. We are not wrong, but
we are on one side of an unreconciled conflict, and CLP adds a *third* request lifecycle on top
(M-03 warns it must not become a second Order state machine).

### B-08 — M01–M14 has no registry, and it has just been assigned to us

> *"Step 11 does not contain a dedicated milestone registry/state/transition structure."*

The milestone APIs are Person 1's under v1.1 §4. The milestones they expose have **no canonical
machine-readable definition** — no states, no transitions, no events. Under our own Hard Rule 4,
that is unbuildable until Step 11 gains an entry or the owner rules.

The nearest thing that exists is ours: `TripEventType`'s 39-entry registry and the `trip_events`
table. **M01–M14 maps onto our event timeline far more naturally than onto `TripStatus`** — that is
the shape I would propose if asked, but it is a proposal, not a decision.

### B-09 — the portal has no tickets

> *"Step 12's 30-ticket pack contains no dedicated Client Portal ticket set… Do not implement from
> CLP narrative alone."*

Step 10 says implementation is ticket-driven and Step 12 is the approved executable scope. **There
is no approved ticket authorising a single line of the portal.**

### One more, which the package surfaced and I verified myself

**Step 11 uses `company_id` as the tenant key — 34 occurrences, and `tenant_id` zero.** Our
Transport module uses `tenant_id` throughout; Fleet's newer code uses `company_id`. So the split is
already live in our own repository. This is inherited rather than new, it is large, and it needs a
ruling rather than a fix. Raising it, not touching it.

### And the contradiction inside the package itself

v1.1 §15 lists **eighteen controlled documents that must be updated** for the CLP — STOS-DB for
"CLP entities, permissions, events", STOS-OPS, STOS-FLEET, STOS-SEC, STOS-RTM and the rest.

**I checked every one of them. All are byte-identical to the versions we already have.** None of the
required updates has been made. The portal is specified in narrative and has no registry, no
entities, no permissions, no events and no tickets behind it.

---

## d) Which of our open defects are now answered

Genuinely mixed, and better than I expected in two places.

### Answered

**Customer feedback — yes, and it is buildable.** CLP §20 and CRM-002 §16 together give what was
missing: captured *at or after final handover/delivery, as configured*; linked to trip/container/
service; configurable fields; comments and evidence attachments; negative feedback can trigger the
Support/Quality workflow; client management sees trends. With one real rule attached: *"Do not alter
feedback merely to improve KPI results."* This moves from "specified by nobody" to specified.
Ownership is unchanged — it stays Person 3's.

**STOS-CR-001 — yes.** The code-review standard we logged as absent now exists, with a review
authority hierarchy, entry criteria, reviewer-independence rules and an anti-pattern list.

**D-46, the client scope narrowing — yes, substantially.** CLP §3 defines **six** client roles
(Admin, Operations, Warehouse/Gate, Quality/Compliance, Finance, Management) and requires
permissions to be *"organisation-, branch-, role-, transaction- and document-type aware"*, with
v1.1's DoD adding *"enforced server-side"*. That is the row-level narrowing `SCOPE_OWN` never had.
It also means the one `client` identity we have is not the model any more — it is six.

### Partly answered — the shape changed, the number did not

**The temperature marker.** No threshold is given, and that is now deliberate: TRK-002 and QMS-002
both make it **configurable per customer / route / service**, not a constant. So the gap changes
shape — from "nobody has specified the number" to "it is a configuration field somebody fills per
contract", which is a different and smaller problem. Two real rules come with it:

- *"Temperature excursions must preserve actual observed values and duration."*
- *"Temperature excursions must not be closed merely because the vehicle later returned to range."*

The second is a genuine business rule we did not have.

### Not answered

**The rate card — no.** This is the big one and it is still open. AGR-001 lists "Rate card" as a
control area and gives it the same boilerplate as everything else. Nothing defines what a rate is,
how margin is computed, or what threshold blocks an approval. **D-63/D-64 stand, and SNG-TRN-005
still has no owner.** CLP §6 makes this *worse*, not better: booking is to be contract- and
rate-driven, and urgent pricing must come from configuration — so the portal's booking step depends
on the one thing nobody has specified.

**POD numbering — no.** Nothing in DOC-002 gives a POD a number, a format or an issuer. Our ruling
to not invent one stands, and the package supports it.

---

## e) My honest read on the timeline

**Today is 20 September. On v1.1's own calendar, M1 and M2 are already past.**

- **M1 (12–13 Sep)** — freeze CLP boundary, entities, RBAC, API and event contracts. **Seven days
  overdue.** Not started, because the document arrived today.
- **M2 (14–18 Sep)** — portal foundation, client access, dashboard shell, request/booking, core
  portal APIs. **Two days overdue.** Nothing exists.
- **M3 (19–23 Sep)** — connect the portal to orders, allocation, trips, milestones, documents,
  exceptions and tracking. Nominally in progress now, due in three days, with nothing to connect.

The plan allocates **seven working days** to building the portal foundation, and all seven are in
the past. What remains is the ten days that were costed for integration, QC and sign-off — of work
that assumed the foundation existed.

**Seven of the sixteen M4 steps cannot be demonstrated from anything that exists today.** Client
login, client dashboard, normal request, urgent request with contract-rule handling, transporter
acceptance, client-visible assignment, client sees vehicle/driver. Those are not adjustments to
screens we have; they are a new authentication surface, a new entity and a new workflow.

And they cannot legitimately be started, because:

- **there is no Step 12 ticket for any of it** (B-09 says so explicitly, in the package's own words:
  *"Do not implement from CLP narrative alone"*);
- **M01–M14 has no canonical definition** (B-08) and the milestone APIs are ours;
- **the eighteen documents v1.1 says must be updated have not been**;
- **booking depends on the rate card**, which is the oldest unanswered question on the project.

So the honest statement is this. **The September 30 date is unchanged, the scope has roughly
doubled, the first third of the new plan's calendar has already elapsed, and the package itself
forbids building the new part from the document it arrived in.** I do not think the sixteen-step
slice is deliverable by 30 September, and I would not want that judgement softened on my behalf.

**What I think is genuinely available by 30 September**, if the owner wants a number: the fourteen-
step internal demonstration, which stands at **10 of 14 working**, plus the back half of the client
sequence — Container 360, milestone timeline, exceptions, documents, delivery/POD, billing status
and audit trail — already work, viewed internally rather than through a portal. Steps 8, 9, 11, 12,
13, 15 and 16 of the new sixteen are substantially the system we have. That is a real and
demonstrable thing, and it is not a client portal.

**The decision I think the owner actually faces** is not "can we do it" but **which demonstration
happens on 30 September**: the internal one that exists and is nearly finished, or a client-facing
one that has no tickets, no registry entries and seven missing steps. v1.1 quietly answers that by
deleting the internal demonstration from the plan. I do not think that deletion should go
unremarked, because it is the only thing on the current plan that we can actually show.

---

## What I have not done

No code. Nothing planned into a migration. The repoint is untouched and still held behind D-118.
Nothing in our register has been changed on the strength of this package — the defects it answers
(feedback, CR-001, D-46, the temperature marker's shape) are recorded here and await your word
before I touch their entries.

## The questions I would want answered before any of this becomes work

1. **Which demonstration is 30 September?** Everything else follows from that.
2. **Does v1.1 supersede v1.0, or sit beside it?** If it supersedes, the fourteen-step walk and the
   presenter's script are no longer the target and should be retired rather than left to look
   current.
3. **B-09 — are we authorised to build the portal without Step 12 tickets?** The package says no. If
   the answer is yes, that is a conscious suspension of Step 10's ticket-driven rule and should be
   recorded as one.
4. **B-08 — may M01–M14 bind to our existing `trip_events` registry** rather than waiting for a new
   Step 11 milestone registry? That is the difference between buildable and blocked.
5. **The rate card.** Unchanged for weeks, still unowned, and the portal's booking step now depends
   on it.
