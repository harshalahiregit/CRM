# Schema proposal — Consignment, Container, LR/DO

**Author:** Person 1 (Core, Commercial & Operations) · **Date:** 2026-09-12
**Branch:** `feat/p1-consignment-container` · **Status:** posted for review, nothing migrated
**Process:** TM-001 §8 step 5 — post before migrating, 24h / no objection = go

> **Four decisions are needed before I can migrate.** Three of them (§A, §B, §C) change the shape
> of the tables, not just their contents, so I have not written the migrations yet. §D needs
> Person 3.

---

## 0. What the source documents actually say

I re-read the package rather than working from the brief. Summary of what is and isn't specified:

| | Specified? | Where |
|---|---|---|
| **Container traceability** | **Yes, heavily** | RTM `STOS-REQ-CTD-001..021` (17 × P0), `MDM-008`, `ORD-004/005/006`; plus **STOS-CTD v1.0**, a 1 927-line master-baseline spec |
| **Consignment ≠ Container** | **Yes, explicitly** | STOS-CTD §8 |
| A ticket owning any of it | **No** | Step 12's 30 tickets have none — logged **D-38** |
| Consignment / Container as canonical entities | **No** | Step 9's domain model lists 20 objects; neither appears — logged **D-39** |
| A `DB-*` table for them | **No** | Step 11 has no consignment/container table |
| LR | **Yes — as a document** | Step 9 #7 "LR / Bilty — Consignment document"; Step 11 `ENUM-006 document_type` includes `lr` |
| DO | **Only as an RTM line** | `ORD-006` "Capture DO details". In no enum, no table, no domain-model row |

So the requirement is real and P0; the ticket and the canonical entity are missing. Same shape as
D-18 (dispatch), but twenty-five requirements instead of one.

---

## A. DECISION 1 — Do Consignment and Container become canonical entities? (D-39)

Step 9's `Domain_Model` header says, verbatim:

> "Canonical entities and ownership rules; **duplicate business objects are prohibited without
> architecture approval**."

Its twenty objects do not include Consignment or Container. LR/Bilty (#7) is described as the
*"Consignment document"* — Step 9 appears to fold the consignment into the LR.

STOS-CTD §8 contradicts that directly:

> "These must not be treated as identical concepts. **Container** — the physical transport unit.
> **Consignment** — the commercial/operational shipment being transported. A consignment may contain
> one container; contain multiple containers; have other cargo references."

Step 9 outranks STOS-CTD (authority order: 9 > 10 > 11 > 12 > 13 > everything else).

**I cannot resolve this myself** — it is precisely the "architecture approval" Step 9 names.

- **Option A1** — Approve both as canonical entities, and record the Step 9 amendment.
  STOS-CTD is a master-baseline spec written entirely about them, and 25 P0 requirements depend on
  them. Without this, Container 360 (Block 2) and universal search (Block 5) have nothing to stand on.
- **Option A2** — Treat the consignment as the LR, per Step 9 #7, and model only the container.
  Cheaper, but it makes "a consignment with multiple containers" (CTD §8) unrepresentable, and
  the Sept 30 demo chain is explicitly *order → consignment → container*.

**My recommendation: A1**, with the approval written down. The requirements are P0 and numerous;
the omission from Step 9 reads like a gap in a 20-row summary rather than a deliberate exclusion —
but that is a judgement the architect should make, not me.

---

## B. DECISION 2 — How is `container_number` constrained? (D-40)

The brief says *"container_number unique per tenant and indexed"*. STOS-CTD §7 says:

> "be unique **where applicable** … be **normalized for search** … **retain original entered value**
> where required; maintain historical associations. Container reuse across different trips is allowed
> **historically** but not simultaneously where business rules prohibit it."

**A plain `UNIQUE(tenant_id, container_number)` makes the required history impossible.** The same
physical box (`ABCD1234567`) carries a different consignment every few weeks, and that history *is*
the Digital Passport.

Three separable things are hiding in one line:

1. a **container master** — one row per physical unit, where the number genuinely is unique;
2. a **consignment ↔ container association** — many over time, unique only while active;
3. a **normalized search key** held alongside the original entered value.

- **Option B1 — master + association (3 tables).** `transport_containers` is the master with
  `UNIQUE(tenant_id, container_number_normalized)`; `transport_consignment_containers` holds each
  attachment with `attached_at` / `detached_at` and a partial-unique on "one active attachment per
  container". Satisfies `MDM-008` ("maintain container **master**/reference") literally, and keeps
  per-container attributes (type, reefer, size) in one place.
- **Option B2 — occurrence rows only (1 table).** One row per container-on-consignment,
  `container_number` indexed but **not** unique. Simpler; but `MDM-008` has no home and container
  type/reefer get re-entered per occurrence, so two rows for the same box can disagree.

**My recommendation: B1.** B2 is smaller today and becomes the "which row is right?" bug in Block 2,
where Container 360 has to render one identity from many occurrences.

---

## C. DECISION 3 — `transport_` prefix and money precision

Two smaller conflicts between the team conventions and my brief, both of which the existing code
has already effectively decided:

| | TEAM-CONVENTIONS.md | Person 1 brief | Existing Transport code |
|---|---|---|---|
| Money | `decimal(15,2)` §4 | `DECIMAL(18,2)` §4 | **`decimal(18,2)`** (`transport_trips.approved_freight`) |
| Table prefix | lists per-module prefixes, none for transport | `transport_` / `trip_` | **`transport_` / `trip_`** across 13 migrations |

**My recommendation:** follow the existing code — `decimal(18,2)` and `transport_` — since changing
either now would mean a migration across shipped tables. Flagging it so the 15,2 in the conventions
doc is corrected rather than quietly contradicted.

---

## D. DECISION 4 — LR and DO belong to Person 3, not me (D-41)

**LR already has a home.** `transport_documents` exists with `document_type='lr'`, a
`document_number`, `issued_on`, validity dates, versioning and
`IDX-010 UNIQUE(tenant, entity_type, entity_id, document_type, version)`. `entity_type` is an
unconstrained string, so an LR can already hang off a consignment today with no schema change at all.

Building `transport_lr_records` would give LR **two homes** — the thing Step 9's domain model and our
own "no duplicate master data" rule both forbid.

**DO has no home and no enum value.** `ENUM-006` is
`lr|ewaybill|invoice|pod|driver_doc|vehicle_doc|insurance|permit|fitness|other`. Adding
`delivery_order` changes an enum whose recorded owner is *Product+Compliance* — **Person 3's area**
under TM-001 §3.

- **Option D1** — LR and DO are documents. I add nothing; Person 3 adds `delivery_order` to the
  document-type enum. I reference them from the consignment by `document_number`.
- **Option D2** — LR and DO get their own tables, as the brief lists them.

**My recommendation: D1**, and I will not build either table until Person 3 has agreed — it is their
enum and their module. This removes two of the four tables from Block 1.

---

## E. Proposed schema (assuming A1 + B1 + D1)

Tenancy is not negotiable and is stated once here: **every table has a non-nullable `tenant_id`,
`use BelongsToTenant`, every index leads with `tenant_id`, and every read chains `->forTenant()`.**

### `transport_consignments`
| Column | Type | Source |
|---|---|---|
| `id` | bigint pk | |
| `tenant_id` | bigint, indexed | BelongsToTenant |
| `consignment_number` | varchar(40) | CTD §4 "Internal Consignment ID"; `CNM-YYYY-NNNNNN` via the Document Numbering Engine |
| `order_id` | bigint | `ORD-004`; link direction order → consignment |
| `customer_id` | bigint, nullable | `CTD-002` "link container to customer" — denormalised so search does not need a join through the order |
| `customer_reference` | varchar(120), nullable | CTD §4 search key |
| `cargo_description` | text | CTD §8 "other cargo references" |
| `service_type` | varchar(60), nullable | FRS `TRP-P0-001` |
| `package_count` | unsigned int, nullable | brief |
| `gross_weight_kg` | decimal(12,3), nullable | brief |
| `volume_cbm` | decimal(12,3), nullable | brief |
| `special_handling` | text, nullable | FRS `TRP-P0-001` |
| `status` | varchar(30), default `draft` | needs its own small enum — **no registry enum exists**, so I would derive it from the order's, not invent one |
| audit | `created_by`, `updated_by`, timestamps, `deleted_at` | |

Indexes: `(tenant_id, consignment_number)` unique · `(tenant_id, order_id)` · `(tenant_id, customer_id)`

### `transport_containers` — the master
| Column | Type | Source |
|---|---|---|
| `container_number` | varchar(20) | CTD §7 "retain original entered value" |
| `container_number_normalized` | varchar(20), indexed | CTD §7 "normalized for search" |
| `container_type` | varchar(30), nullable | CTD §6 |
| `size_feet` | unsigned smallint, nullable | CTD §6 |
| `is_reefer` | boolean, default false | CTD §15/§18 temperature + genset panels |
| `status` | varchar(30), default `active` | |

Index: `(tenant_id, container_number_normalized)` **unique** — the master is where uniqueness is true.

### `transport_consignment_containers` — the controlled relationship (CTD §8)
| Column | Type | Source |
|---|---|---|
| `consignment_id`, `container_id` | bigint | CTD §8 |
| `seal_number` | varchar(40), nullable | CTD §6 |
| `attached_at`, `detached_at` | datetime, `detached_at` nullable | CTD §7 "maintain historical associations" |

Indexes: `(tenant_id, container_id, detached_at)` — enforces "not simultaneously" while allowing
history · `(tenant_id, consignment_id)`

### `transport_trips` — one column
`consignment_id` bigint **nullable**, indexed. Nullable because the trip already exists and has
shipped; and because the container number is a **search anchor, not a mandatory parent** (TM-001 §4
rule 3). No container_id on the trip — it is reached through the consignment.

---

## F. What I am NOT proposing to build

| | Why |
|---|---|
| `transport_lr_records` | §D — LR is already a document. Duplicate home. |
| `transport_delivery_orders` | §D — Person 3's enum, Person 3's module. |
| A consignment status **machine** | No registry enum and no state rows exist. I will not invent one (Hard Rule 1). |
| Container GPS / temperature / genset columns | `CTD-010/011` are real P0s, but telemetry is **Person 2**. Reached through their contract, never stored here. |
| Anything writing `transport_vehicles` / `transport_drivers` | Person 2's tables. Read-only references only. |

---

## G. What I need, and from whom

| # | Decision | Who | Blocks |
|---|---|---|---|
| 1 | Consignment + Container as canonical entities (**A1**) | Architect / Owner | every table below |
| 2 | Container uniqueness model (**B1**) | Architect | `transport_containers` shape |
| 3 | `decimal(18,2)` + `transport_` confirmed; conventions doc corrected | Team | nothing — following existing code |
| 4 | LR/DO stay documents (**D1**); Person 3 adds `delivery_order` | Person 3 | two tables I would otherwise build |

**24h / no objection = go**, per TM-001 §8 step 5 — except **#1 and #2**, which I will not proceed on
without an explicit answer, because Step 9 requires architecture approval by name and because getting
the uniqueness wrong is a migration we would have to undo.
