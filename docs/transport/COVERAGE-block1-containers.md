# Coverage checklist — `transport_containers` + the association

**Author:** Person 1 · **Date:** 2026-09-15 · **Posted before any code**, per §8a
**Re-read from source for this step:** STOS-CTD §6, §7, §8; STOS-CMP §76, §77; RTM `MDM-008`,
`CTD-001..021`; Step 11 registry (searched for `container` — **no row of any kind**).
**Ruling in force:** D-40, Option B1 — master + association.

---

## 1. Requirements this step covers

| Req | Text | What satisfies it |
|---|---|---|
| `MDM-008` | "Maintain container master/reference" · **P0** | `transport_containers` — one row per physical unit |
| `CTD-001` | "Search complete lifecycle using container number" · **P0** | `container_number_normalized`, indexed and unique per tenant. *Partial* — the search endpoint is Block 2/5; this step provides the anchor |
| `ORD-004` / `CTD-003` | "Link container to order" · **P0** | container → consignment → order, through the association |
| `CTD-002` | "Link container to customer" · **P0** | same chain; `customer_id` already denormalised on the consignment |
| STOS-CTD §8 | "Consignment ↔ Container as a **controlled relationship**" | `transport_consignment_containers`, with `attached_at` / `detached_at` |
| STOS-CTD §7 | unique where applicable · normalized for search · **retain original entered value** · maintain historical associations · reuse allowed historically but not simultaneously | see §2 |
| STOS-CTD §6 | Passport identity: Container Number, Container Type | `container_number`, `container_type` |

## 2. How STOS-CTD §7's six clauses are each met

| Clause | Mechanism |
|---|---|
| "unique **where applicable**" | `UNIQUE(tenant_id, container_number_normalized)` on the **master**. Uniqueness is true of the physical unit, not of an attachment |
| "follow configurable format validation" | **DEFERRED** — see §5. No format is specified anywhere |
| "be searchable" | indexed; endpoint is Block 2/5 |
| "be **normalized** for search" | `container_number_normalized` — upper-cased, non-alphanumerics stripped |
| "**retain original entered value** where required" | `container_number` stored exactly as typed, beside the normalized key |
| "maintain historical associations" | association rows are never deleted; `detached_at` is set |
| "reuse … not simultaneously" | one **active** attachment per container, enforced in the database — see §3 |

## 3. The one design question this step must answer

"Not simultaneously" needs enforcement, not a service check — two concurrent requests both pass a
service check and both insert.

**Chosen:** a generated column `active_container_key` = `container_id` when `detached_at IS NULL`,
else `NULL`, with `UNIQUE(tenant_id, active_container_key)`. MySQL ignores NULLs in a unique index,
so any number of *detached* rows coexist and only one *attached* row can exist per container.

Rejected: a partial index (`WHERE detached_at IS NULL`) — **MySQL does not support them**, and the
suite runs on sqlite where it would silently pass.

## 4. NOT MINE — requested, not built

| Req | Why | Owner |
|---|---|---|
| **STOS-CMP §76 Seal Control** — track seal number, issued by, issued date, container, verified at delivery, mismatch | Compliance is Person 3's under TM-001 §3. §77 turns a mismatch into a Security/Quality Incident — a QC entity I do not own and must not create | **Person 3** |
| `CTD-011` temperature, `CTD-010` GPS | telemetry | Person 2 |
| `CTD-008` compliance, `CTD-013` CAPA, `CTD-016/017` custody & POD, `CTD-019/020` billing | | Person 3 |

> **Correction to my own schema proposal of 2026-09-12.** It listed `seal_number` on the association
> table. I had not checked whether seal was specified — it is, in STOS-CMP §76, and it is **Person 3's**.
> It is therefore *not* built here, and the request goes to them. A field I invented is worse than a
> field I omitted; this one turned out to be both specified and someone else's.

## 5. DEFERRED — logged with a reason

| Item | Reason | Owner |
|---|---|---|
| **Container-number format validation** (CTD §7 "configurable format validation") | No format is specified in any document. ISO 6346 is the industry standard but **the package never names it**, and the check digit would reject legitimate non-ISO numbers. Inventing a regex would be Hard Rule 1 | Product |
| **`container_type` as an enum** | **No document anywhere defines its values.** Searched all 30 package documents for `20ft`/`40ft`/`HC`/`high cube`/`ISO 6346` — zero hits — and Step 11 has no container enum and no container DB row. A free-text column, not an enum. Inventing a vocabulary is the D-9 mistake | Product |
| `CTD-009` gate/port, `CTD-014` urgent-trip | no entity exists | D-42, D-43 |

## 5a. Diffed against my own approved proposal (added 2026-09-15)

The source check above did not catch two fields, because they were never in the source — they were
in **my** proposal. Nothing had compared the two documents. This section is that comparison, and it
is now part of the check.

`SCHEMA-PROPOSAL-consignment-container.md §E` listed **six** columns for `transport_containers`.
The migration has **three**. Every difference, resolved:

| Proposed | Built? | Why |
|---|---|---|
| `container_number` | ✅ | CTD §7 "retain original entered value" |
| `container_number_normalized` | ✅ | CTD §7 "normalized for search" |
| `container_type` | ✅ | CTD §6 — free text, no vocabulary exists (**D-50**) |
| `status` | ❌ | **D-44** — status comes from a lifecycle engine that does not exist |
| `size_feet` | ❌ | **Invented.** §6's identity is Number and Type; no size field appears in any of the thirty documents. Attribution in my proposal was wrong |
| `is_reefer` | ❌ | **Wrong owner and wrong level.** Temperature/genset/reefer is Person 2's (TM-001 §6); and CTD §15, CTD §19 and TM-001 §10 place the idea on the consignment, the trip and the service requirement respectively — never the container. Leaves TM-001 §12's P0 rule with nothing to key on: **D-52** |

And on the association table, from the same proposal:

| Proposed | Built? | Why |
|---|---|---|
| `seal_number` | ❌ | Specified after all — STOS-CMP §76/§77 — and **Person 3's**. Requested, not built |

**Four of the seven fields I proposed were wrong**, in three distinct ways: one had no source at all,
one was someone else's, one was at the wrong level, one was derived-not-stored. The proposal was
written before the source was read closely enough. That is the lesson this section exists to record:
**an approved proposal is not a source.** It is a plan made with less information than the build has.

## 6. What this step does NOT do

No search endpoint (Block 2/5). No Container 360 (Block 2). No container status — the same D-44
reasoning as the consignment: status comes from the lifecycle engine, which does not exist.

## 7. Steps, in order

migration → model → enum/value object → service → FormRequest → thin controller → route (staff-only
group) → tests incl. refusals → page. One step, then report.
