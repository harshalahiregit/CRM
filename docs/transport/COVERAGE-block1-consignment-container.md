# Coverage checklist — Block 1 (Consignment / Container / LR / DO)

**Author:** Person 1 · **Date:** 2026-09-12 · **Posted before any code**, per TM-001 §8a
**Source re-read for this checklist:** RTM `STOS-REQ-CTD-001..021`, `MDM-008`, `ORD-004/005/006`,
and **STOS-CTD v1.0** §1–§9. Not taken from the brief.

**25 requirements. Every one is in exactly one of three states below.**

Legend — **B1** = this block · **B2** = Container 360 · **B4** = timeline · **B5** = search

---

## 1. MINE — built in Block 1 (the tables)

| Req | Requirement | What satisfies it |
|---|---|---|
| `MDM-008` | Maintain container master/reference · **P0** | `transport_containers` — one row per physical unit, `UNIQUE(tenant_id, container_number_normalized)`. The master is the *reason* Decision B1 exists. |
| `ORD-004` | Link container to order · **P0** | `transport_consignments.order_id` + `transport_consignment_containers`. Link direction order → consignment → container. |
| `CTD-002` | Link container to customer · **P0** | `transport_consignments.customer_id`, denormalised so search need not join through the order. |
| `CTD-003` | Link container to order · **P0** | Same chain as `ORD-004`. (The RTM states this requirement twice.) |

## 2. MINE — the link is mine, the data is not (Block 2, through a contract)

These say *"link container to X"*. The **link** is my schema; the **X** belongs to someone else and
is read through their service contract, never stored by me.

| Req | Requirement | Link (mine) | Data owner |
|---|---|---|---|
| `CTD-006` | Link container to vehicle · **P0** | consignment → trip → `vehicle_id` | Person 2 |
| `CTD-007` | Link container to driver · **P0** | consignment → trip → `driver_id` | Person 2 |
| `CTD-004` | Link container to LR · **P0** | `transport_documents` (`entity_type='consignment'`, `document_type='lr'`) | Person 3 owns the doc |
| `CTD-005` | Link container to DO · **P0** | same, once `delivery_order` exists in `ENUM-006` | Person 3 owns the enum |
| `CTD-001` | Search lifecycle by container number · **P0** | `container_number_normalized` index; endpoint in **B2**, search in **B5** | — |
| `CTD-021` | Chronological container timeline · **P0** | `trip_events` in **B4**, rendered with the existing `AuditTimeline` | — |

## 3. NOT MINE — Person 2 (Fleet / telemetry)

Requested from them; I hold the link and call the contract. Stub until the real service lands.

| Req | Requirement | Priority |
|---|---|---|
| `CTD-010` | Link container to GPS history | P1 |
| `CTD-011` | Link container to temperature history | **P0** |
| `CTD-015` | Link container to diesel/fuel records | P1 |

## 4. NOT MINE — Person 3 (documents / compliance / QC / billing)

| Req | Requirement | Priority |
|---|---|---|
| `CTD-008` | Link container to compliance records | **P0** |
| `CTD-012` | Link container to excursions/incidents | **P0** — *split:* exceptions are mine (SNG-TRN-013); QC incidents are theirs |
| `CTD-013` | Link container to CAPA | **P0** |
| `CTD-016` | Link container to document custody | **P0** |
| `CTD-017` | Link container to POD | **P0** |
| `CTD-018` | Link container to driver feedback | **P0** |
| `CTD-019` | Link container to billing documents | **P0** |
| `CTD-020` | Link container to invoice/payment | **P0** |
| `ORD-005` | Capture LR details | **P0** — LR is a document; see D-41 |
| `ORD-006` | Capture DO details | **P0** — needs `delivery_order` in `ENUM-006` |

## 5. DEFERRED — no data model anywhere, owner named

Both logged in `registry-defects.md` rather than dropped.

| Req | Requirement | Why deferred | Owner |
|---|---|---|---|
| `CTD-009` | Link container to gate/port records · P1 | No gate-pass or port-entry entity exists in Step 9, Step 11 or any migration. STOS-CTD §28/§29/§30 describe them narratively only. | Product + Architecture |
| `CTD-014` | Link container to urgent-trip records · P1 | Depends on `STOS-REQ-OPS-014` ("Handle urgent trips", P0), which no ticket owns and nothing has built. | Step 12 maintainer |

---

## 6. Totals

| State | Count |
|---|---|
| Built in Block 1 | 4 |
| Mine, Block 2/4/5 (link now, data via contract) | 6 |
| Not mine — Person 2 | 3 |
| Not mine — Person 3 | 10 |
| Deferred, logged | 2 |
| **Total** | **25** |

**Nothing is unaccounted for.** Block 1 itself delivers only the four in §1 plus the schema the six
in §2 hang from — the majority of CTD is Block 2 and belongs to other people's data.

---

## 7. Still blocking Block 1

From the schema proposal posted 2026-09-12 — unchanged, no code until these are answered:

1. **D-39** — Consignment and Container are not in Step 9's canonical domain model, whose header
   requires architecture approval for new business objects. *Architect.*
2. **D-40** — `UNIQUE(tenant, container_number)` and STOS-CTD §7's required historical reuse cannot
   both hold. *Architect.*

`D-41` (LR/DO stay documents) and the `decimal(18,2)` confirmation are on 24h / no objection.
