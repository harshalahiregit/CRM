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
| "be searchable" | indexed; **endpoint now built** — `GET /containers/lookup?number=` resolves however the number was typed, and `?search=` filters the list. Both go through the normalised key. The *lifecycle* search (CTD-001 in full) is still Block 5 |
| "be **normalized** for search" | `container_number_normalized` — upper-cased, non-alphanumerics stripped |
| "**retain original entered value** where required" | `container_number` stored exactly as typed, beside the normalized key |
| "maintain historical associations" | association rows are never deleted; `detached_at` is set |
| "reuse … not simultaneously" | one **active** attachment per container, enforced in the database — see §3 |

## 2a. STOS-CTD §8's clauses (added 2026-09-16, step 5)

§8 was cited in §1 as one line. It is four clauses, and the step-5 check found one of them with
no test and one not built at all.

| Clause | State |
|---|---|
| "must not be treated as identical concepts" | **BUILT** — two tables, two models; a container has no status, dates or customer of its own |
| "a consignment may contain **one** container" | **BUILT** — `ContainerServiceTest::test_detaching_keeps_the_row_as_history` and the attach path |
| "a consignment may contain **multiple** containers" | **BUILT** — `ContainerService::attachmentsFor()`; `ContainerServiceTest::test_one_consignment_may_carry_several_containers`. *The schema always allowed it; nothing proved it until now.* The unique index runs container→consignment, never the reverse |
| "have **other cargo references**" (non-container cargo) | **NOT BUILT** — see §5. No field, entity or example is given for what a non-container cargo reference is |
| "Consignment ↔ Container as a **controlled** relationship" | **BUILT** — attach/detach only, through the service; no delete path, history retained |

## 2b. Step 6 — the API surface (added 2026-09-16)

Seven endpoints, none in Step 11's API registry and none owned by a Step 12 ticket — the same
position as Consignment, recorded under the same precedent (D-45).

| Endpoint | Requirement | Proven by |
|---|---|---|
| `GET /containers` | §7 searchable · D-44's no-status rule | `test_the_list_can_filter_by_attachment_and_search`, `test_filtering_by_status_is_refused_rather_than_ignored` |
| `GET /containers/lookup?number=` | **CTD-001** anchor | `test_lookup_finds_a_container_however_the_number_was_typed` |
| `GET /containers/{id}` | §7 historical associations | `test_a_container_can_be_attached_and_detached` |
| `GET /consignments/{id}/containers` | **§8** one or several | `test_a_consignment_can_carry_several_containers_over_http` |
| `POST /containers` | MDM-008 | `test_a_container_can_be_created_and_read_back` |
| `POST /containers/{id}/attach` | §7 not simultaneously | `test_attaching_a_container_already_on_another_consignment_is_refused` |
| `POST /containers/{id}/detach` | §7 reuse allowed historically | same test |

**There is no PUT and no DELETE**, and that is a decision rather than an omission: the container
number is the identity, and §7 requires historical associations be maintained — editing the number
would rewrite that history and deleting the container would destroy it. Locked by
`test_there_is_no_update_or_delete_endpoint`, which asserts 405 on both, so nobody adds them
casually.

**Permissions.** Three keys — `CONTAINER_VIEW`, `CONTAINER_CREATE`, `CONTAINER_ATTACH`. Attach and
detach deliberately share one: they are a single authority (deciding what is on a consignment), and
splitting them would let someone attach a container they could not then remove. No `CONTAINER_UPDATE`
or `CONTAINER_DELETE` is declared, because a key with no operation behind it is a promise the code
does not keep. **No customer grant on `CONTAINER_VIEW`** — the D-46 reason, which bites hardest here:
STOS-CTD's Digital Passport is container-keyed, so this is precisely the surface a customer-facing
route would expose while `SCOPE_OWN` still narrows nothing.

### Two things the two-way check caught at this step

1. `?attached=0` and the falsy-string trap. `validate()` returns the RAW value, and PHP reads the
   string `"false"` as truthy — so a `boolean` rule plus a PHP ternary could have returned the exact
   opposite set with a 200. **Probed rather than assumed:** Laravel's `boolean` rule refuses
   `"true"`/`"false"` with 422 and accepts only `1`/`0`, and the repository uses `array_key_exists`
   rather than a falsy check. Correct as built, and now locked by a test.
2. My own assertion was too weak. `assertJsonCount(1, …)` on an attached/unattached filter passes
   **either way if the filter is inverted**, because each side returns exactly one row. Changed to
   assert *which* container comes back.

## 2c. Step 7 — the screen (added 2026-09-16)

`/app/transport/containers`, registered in `routes.jsx`, `TransportLayout.jsx` and `Sidebar.jsx`.

| Clause | How the screen carries it |
|---|---|
| §7 "retain original entered value" | the number is listed exactly as typed, with **"matched as SGOE4022159"** beneath it — and only when the two differ, otherwise it is the same string twice |
| §7 "normalized for search" | one search box; spacing, case and punctuation ignored on both sides |
| §7 "unique where applicable" | the duplicate refusal is shown in full, because it has to explain why two visibly different strings clash: *"already exists in this workspace. It was entered as "ABCD 1234 567", which is the same number."* |
| §7 "maintain historical associations" | the history drawer — every consignment the container has been on, newest first, the current one marked **ON IT NOW** |
| §7 "not simultaneously" | one row shows either **Attach** or **Detach**, never both. The state comes from `active_attachments_count`, not from a guess |
| §8 "multiple containers" | **the consignment detail drawer**, added in this step — see the gap below |

**No edit, no delete, no status filter** — the API offers none of them, so the screen offers none.
Buttons for operations the server refuses would be a lie with a spinner. The one filter present
(*On a consignment* / *Free*) is the only question the data can answer honestly.

### The gap the two-way check found this time

The page was built container-first, and it covers §7 completely. **§8 was not on screen at all.**
"A consignment may contain one container; contain multiple containers" is a statement about the
*consignment*, and nothing in the UI read the relationship from that side — the Containers screen
shows container → consignment, which is the other direction. The endpoint
(`GET /consignments/{id}/containers`) had existed since step 6 with no caller.

Closed by a **Containers panel on the consignment detail drawer**, showing current and historical
attachments with `detached_at` distinguishing them. Verified in the browser against real data:
`CONTAINERS (1) · sgoe-402215-9 · 40ft Reefer · On it now`, and the honest empty state on the
consignment that has none.

*Checking the API against the source would not have found this.* Both the endpoint and the
requirement existed; nothing connected them, and the checklist row for §8 was already ticked from
step 6 because the endpoint was built. The row now names the screen, not only the route.

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
| **Other cargo references** (CTD §8 "have other cargo references") | The package names the concept once and never again — no field, no entity, no example, and no requirement ID in the RTM. Consignment↔Container is specified; consignment↔*anything else* is not. Building a generic "cargo reference" column would be inventing the business rule | Product |
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
