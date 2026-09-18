# `trip_events` — the consignment's event stream

**Person 1. Plan only — no code written.** Posted 2026-09-18.
Authorised by the owner ("build it"), planned first because **this is a table two other people
have to write into**, and a schema is the one thing that is expensive to get wrong.

---

## 1. The source, read before proposing anything

The table is **named in the documents**. I had assumed it would have to be derived.

| ID | Source | Says |
|---|---|---|
| **STOS-DB §37** | *TRIP EVENT LOG* | **"Do not overwrite every historical status. Maintain: `trip_events`."** Examples: PLANNED, ASSIGNED, DISPATCHED, STARTED, GATE_IN, PORT_ENTRY, PORT_EXIT, DELIVERED |
| **STOS-DB §38** | *EVENT SOURCING PRINCIPLE* | "Current status = latest valid state. **History = events.**" |
| **STOS-DB §19** | Timestamps | "Transaction/event records may additionally include: `occurred_at`; `processed_at`; `completed_at`" |
| **STOS-CTD §31** | *TRIP TIMELINE* | The worked example — 16 entries from `08:10 Order Approved` to `17:15 Billing Ready` |
| **STOS-CTD §32** | *EVENT TIMELINE* | **"Timeline must combine events from all connected systems."** Sources: user action, system automation, GPS, temperature, Genset, integration, accounting, support, compliance. **"Each event should identify its source."** |
| **STOS-CTD §33** | *EVENT SOURCE* | USER, SYSTEM, GPS, SENSOR, ACCOUNTING, HR, COMPLIANCE, CUSTOMER, DRIVER, EXTERNAL API |
| **STOS-CTD §34** | *EVENT IMMUTABILITY* | **"Historical events should not be silently edited. Corrections should create a Correction Event with audit trail."** |
| **STOS-CTD §35** | *DOCUMENT TIMELINE* | Filter by: documents, operations, GPS, temperature, financial, customer, incident |
| **STOS-CTD §101** | *EVENT STORE PRINCIPLE* | The stream's branches: Commercial, Operational, Document, Compliance, GPS, Temperature, Financial, Customer, Quality |
| **STOS-CTD §133** | *DATA MODEL PRINCIPLE* | `Events` is one of the eighteen records the Passport is composed from. "Detailed schema belongs in STOS-DB" |

### 1.1 And a gap, immediately — D-113

**Step 11's DB_Registry has no events table.** DB-001…DB-020 cover orders, trips, assignments,
vehicles, drivers, costs, advances, expenses, documents, exceptions, risks, bills, collections,
settlements, profit snapshots, rates, customers, suppliers, documents and policies. There is no
`trip_events`, no field registry rows for it, no API row and no permission row — while STOS-DB
§37 names the table outright and CTD calls its timeline the thing the Passport is built on.

So the canonical registry is silent about the one table the product documents describe as
central. Recorded as **D-113**; the schema below is derived from STOS-DB §37 and CTD §§31–35,
and every column says which line it comes from.

---

## 2. Why this cannot be our audit log

We already have `transport_audit_logs`, and Container 360's timeline reads it today. It is the
wrong instrument for this, for three reasons — and the third is the one that matters.

1. **It records field changes, not occurrences.** An audit row answers "who changed what, from
   what, to what". `Genset ON`, `Port Entry`, `Temperature excursion started` and `Feedback
   received` are not field changes on a Transport row. They are things that happened.

2. **It is scoped to a subject we own.** Audit rows hang off `auditable_type` +
   `auditable_id` — a Transport model. A GPS ping and an accounting posting have no Transport
   model to hang off.

3. **IT IS OURS, AND HALF THESE EVENTS ARE NOT.** Of CTD §31's sixteen example entries,
   **seven belong to Person 2 or Person 3**:

   | CTD §31 entry | whose |
   |---|---|
   | Order Approved · Driver Allocated · Vehicle Allocated · Dispatch · Delivery Location · Delivery Confirmed | **P1** |
   | GPS Active · Genset ON · Port Entry · Port Exit | **P2** |
   | Documents Handed Over · Feedback Requested · Feedback Received · POD Uploaded · Billing Ready | **P3** |

   `transport_audit_logs` is written through `TransportAuditLogger`, a Transport service, from
   inside Transport models. For P2 and P3 to contribute to the timeline through it, they would
   have to write into our audit trail — which is the record of what *we* did and must stay that
   way.

   **A shared table with a documented contract is the only shape that lets three people fill one
   timeline without any of them reaching into another's service.** That is the whole argument for
   building it, and it is the owner's.

---

## 3. The schema

`trip_events`, per STOS-DB §37. Every column traced to a line.

| column | type | why |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT, NOT NULL, indexed | every table in this module |
| `trip_id` | BIGINT, **nullable**, indexed | STOS-DB §37 names the table for trips. **Nullable because CTD §31's own example opens with `08:10 Order Approved`** — which happens before a trip exists, since a trip is created from an approved order |
| `order_id` · `consignment_id` · `container_id` | BIGINT, nullable, indexed | CTD §133 composes the Passport from the chain; the Container Passport must be able to ask for a container's events without joining through three tables. Denormalised on write, deliberately |
| `event_type` | VARCHAR(60), indexed | STOS-DB §37's examples. **Not a locked enum — see §4** |
| `category` | VARCHAR(20), indexed | CTD §101's nine branches. Drives CTD §35's filters |
| `source` | VARCHAR(20), indexed | CTD §32: "each event should identify its source". CTD §33's vocabulary |
| `occurred_at` | DATETIME, NOT NULL, indexed | **STOS-DB §19**, verbatim. When it HAPPENED — not when we heard about it |
| `recorded_at` | DATETIME, NOT NULL | when we heard. A GPS ping buffered for an hour has two different times and CTD §31 is a chronology of the first |
| `summary` | VARCHAR(190) | the line the timeline shows — "Genset ON", "Delivery confirmed" |
| `detail` | JSON, nullable | whatever the producer wants to carry. Nobody else parses it |
| `actor_id` · `actor_name` · `actor_role` | | CTD §33's USER/DRIVER sources. Denormalised name, as the audit log already does, so a deleted user does not erase history |
| `corrects_event_id` | BIGINT, nullable | **CTD §34.** A correction is a NEW row pointing at the one it corrects |
| `created_by` | BIGINT | |

**No `updated_at` and no `deleted_at`.** CTD §34: historical events are not silently edited. The
model refuses `updating` and `deleting` the way `TransportAuditLog` already does, and a
correction is an append.

Indexes: `(tenant_id, trip_id, occurred_at)`, `(tenant_id, container_id, occurred_at)`,
`(tenant_id, consignment_id, occurred_at)`, `(tenant_id, category, occurred_at)`.

---

## 4. The decisions I need ruled — four of them

### Q1. `event_type` — open vocabulary or locked enum?

CTD §31 gives sixteen examples; STOS-DB §37 gives eight. **Both are labelled "Example".** No
document gives a closed list.

- **Locked enum** — safe, and means **P2 and P3 cannot add an event without a migration from
  me.** Every new telemetry or document event becomes a cross-team ticket.
- **Open string with a DECLARED registry** — `TripEventType` holds every type we know of, with
  its category, source and owner, and the column accepts any string. Unknown types render with a
  humanised label instead of being rejected.

**My recommendation: open, with a declared registry.** The argument for a locked enum is
consistency, and we would buy it by making the table useless to the two people who own half the
events. The registry gives the consistency without the gate. It is also what "declare all, wire
only reachable" has meant everywhere else in this module.

### Q2. `category` — CTD §101's nine, or §35's seven?

They disagree. §101 (the event-store definition): Commercial, Operational, Document, Compliance,
GPS, Temperature, Financial, Customer, Quality. §35 (the filter list): documents, operations,
GPS, temperature, financial, customer, incident.

**My recommendation: §101's nine**, because it is the section that defines the stream rather than
one screen's filter, and it is a superset except that §35's "incident" is §101's "Quality".
Recorded as a divergence either way.

### Q3. What happens to the existing audit-log timeline?

Container 360's timeline reads `transport_audit_logs` today and reads well — eleven events, three
sources, transitions in English. Three options:

  (a) **`trip_events` becomes the timeline; audit stays the compliance record.** Cleanest, but
      every trip that exists today has no events, so the passport goes blank for them unless
      backfilled.
  (b) **Union both**, de-duplicated. No backfill, no blank screens, two sources forever.
  (c) **(a) plus a one-off backfill** from existing audit rows into `trip_events`.

**My recommendation: (c).** The backfill is a read of our own audit table and is re-runnable;
(b) means the "single source of truth" is two sources, which is the thing the document is
arguing against.

### Q4. Who may write, and who may read?

Step 11 has no permission row (D-113). Reading a trip's events is reading the trip, so
`transport.trip.view`. Writing is done by SERVICES, not users — P2 and P3 call a recorder rather
than posting to an endpoint.

**My recommendation: no write endpoint at all in this pass.** A `TripEventRecorder` service with
one method, called in-process. An HTTP write surface is a second decision (auth, idempotency,
rate) and nobody has asked for one yet.

---

## 5. What I would build, if ruled

1. **Migration** — `trip_events` as above.
2. **`TripEvent` model** — append-only, refuses update and delete, tenant-scoped, the usual
   `forTenant`/`forTrip`/`forContainer` scopes.
3. **`TripEventType`** — the declared registry: type → category, source, owner, label. Every
   entry traced to CTD §31, STOS-DB §37, or marked as ours.
4. **`TripEventRecorder`** — one `record()` method, plus `correct()` for CTD §34. **This is the
   contract P2 and P3 call.** Documented in TEAM-CONTRACTS with a worked example each.
5. **P1's own events emitted** at the transitions we already own — order approved, trip created,
   submitted, approved, vehicle and driver allocated, pre-trip passed, dispatched, departed,
   delivered, closed. Emitted from the same services that already audit, after commit.
6. **Backfill command** — `stos:backfill-trip-events`, re-runnable, from existing audit rows.
7. **Container 360's timeline reads `trip_events`**, with CTD §35's category filters replacing
   the current source filter.
8. **Notes to P2 and P3** — the contract, the types each of them owns from CTD §31, and a worked
   call.

**Not in this pass:** an HTTP write endpoint; the Passport PDF (§95); native intelligence (§102);
the AI context package (§106). None has been asked for.

## 6. Honest estimate

~18 files. **Half a day** for 1–5, another half for 6–8 with the walk and the coverage check.
The schema is small; the registry and the two team notes are where the time goes, and they are
the parts that make it usable by anyone other than me.

---

**No code written. Four questions in §4, each with a recommendation. Ready to build on the word.**
