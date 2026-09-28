# Coverage — LR and DO on the shipment

**Person 1, 2026-09-19.** Four P0 requirements, unblocked by work P3 landed on 16 September that
neither of us noticed for three days (D-114).

---

## 1. Against the documents

| ID | Source | Acceptance | Where it landed |
|---|---|---|---|
| `STOS-REQ-ORD-005` | RTM · **P0** | **"LR traceable"** | Capture: the consignment's Paperwork panel. Traceable: `TransportSearchService::shipmentDocument()` |
| `STOS-REQ-ORD-006` | RTM · **P0** | **"DO traceable"** | same |
| `STOS-REQ-CTD-004` | RTM · **P0** | **"LR visible"** | `ContainerPassportService::chain()['lr']` → the LR row on Container 360 |
| `STOS-REQ-CTD-005` | RTM · **P0** | **"DO visible"** | `…['do']` |
| `CTD §4` | PRIMARY SEARCH KEY | "LR Number", "DO Number" among the entry points that "must ultimately lead to the same Digital Passport" | both resolve to the consignment |
| `CTD §5` | SEARCH EXAMPLE | shows `LR / LR-XXXXX` between Transport Order and Vehicle | the chain places it exactly there |
| `CTD §150` | **NON-NEGOTIABLE REQUIREMENTS** | **"LR and DO must be searchable"** | done, and the reason it was in scope at all |
| `CTD §24` | DOCUMENT PASSPORT | "Every document related to the consignment should be visible" | the Paperwork panel lists all filed |
| `D-41` | our own ruling, 12 Sep | LR and DO stay **documents**, no tables of their own | honoured — this is a `transport_documents` row against a consignment |

**Nothing invented.** The one judgement call is recorded below.

## 2. Can a user reach it?

| | route | permission | on screen | verdict |
|---|---|---|---|---|
| File an LR/DO | `POST /consignments/{id}/documents` *(P3, 16 Sep)* | existing consignment grant | Consignments → row → **View details** → Shipment paperwork → File document | **BUILT** |
| Renew one | `POST …/documents/{id}/renew` *(P3)* | same | **Renew** on the row | **BUILT** |
| See it on the passport | — | `transport.container.view` | Container 360 → the chain | **BUILT** |
| Search by LR/DO number | `GET /transport/search` | existing | the Containers box **and ⌘K from any screen** | **BUILT** |

Walked in a browser: filed `LR-2026-0042` and `DO-2026-0099`, both appeared on the chain between
the consignment and the container, and both resolved from the search box — including
`lr-2026-0042` in lower case.

## 3. Against what I would have designed

| I would have | what shipped | |
|---|---|---|
| built an LR capture form | **reused `DocumentsPanel`** | three screens filing paperwork three different ways is how the rules on one of them drift |
| hardcoded the six types | **server sends `document_types`** | D-37's lesson: a client keeping its own copy of a list has nothing to check it against |
| shown LR/DO in a documents list | **put them in the CHAIN** | CTD §5 puts the LR between the order and the vehicle, and CTD-004's acceptance is "LR **visible**" |
| stopped at capture + display | **added search** | CTD §150 makes it non-negotiable, and I only found that by reading §4 and §150 rather than the two RTM rows |

## 4. The judgement calls, both recorded

**Only LR and DELIVERY_ORDER resolve from the search box.** The same `document_number` column
holds e-way bill and invoice numbers, and CTD §4 lists "Invoice Number" as its **own** entry
point — answering an invoice search with a consignment would be guessing at what somebody meant.
Tested.

**No normalisation.** A container number normalises because CTD §7 defines the rule. Nothing in
the package defines a format for an LR, so the search is case-insensitive and trimmed and
nothing more. Stripping dashes from `LR-2026-0042` would assume a format no document states —
D-9's mistake. Tested: `LR20260042` does **not** match.

## 5. What this did not touch

- **P3's document service and routes are untouched.** The entity, the type and the two endpoints
  are his; the panel, the chain, the search and the exposure of applicable types are ours.
- `DocumentsPanel` gained three props (`heading`, `emptyText`, `showExpiry`) whose defaults are
  the existing vehicle/driver behaviour exactly, so those screens are unchanged. An LR is not a
  compliance document and does not expire, and the panel said both until today.

## 6. Evidence

**Transport + unit: 1364 passed, 3 skipped.** `ShipmentPaperworkTest` covers the chain, the
absence case, versioning, both searches, case-insensitivity, the no-normalisation rule, the
invoice exclusion, tenancy, and that a consignment is not offered a fitness certificate.
Frontend build clean. Walkthrough documents removed.
