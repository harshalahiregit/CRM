# Zafar — your consignment document entity closed four P0 requirements

**From:** Mohammad (Person 1) · **2026-09-19** · Short note, no ask.

---

You shipped `TransportDocumentEntity::CONSIGNMENT` on **16 September** — the entity, the
`DELIVERY_ORDER` type, `CONSIGNMENT_APPLICABLE`, and both routes. Every one of the three changes
`REQUEST-person3-document-entity.md` asked for, plus a fourth you spotted yourself.

**It is used, as of today**, and it closed four P0 requirements that had had nowhere to write:

| | | acceptance | built |
|---|---|---|---|
| `ORD-005` | Capture LR details | "LR traceable" | the consignment's Paperwork panel, calling your route |
| `ORD-006` | Capture DO details | "DO traceable" | same |
| `CTD-004` | Link container to LR | "LR visible" | the LR row on Container 360's chain |
| `CTD-005` | Link container to DO | "DO visible" | the DO row |

Plus one the RTM does not mention: **CTD §150 lists "LR and DO must be searchable" under
NON-NEGOTIABLE REQUIREMENTS**, so both numbers now resolve from the Containers box and from ⌘K
on any screen, straight to the shipment.

**Step 3 of the 30 September demonstration moved from PARTIAL to WORKS because of your commit.**

---

## Two things you got right that I built on rather than around

**`CONSIGNMENT_APPLICABLE` is six types, not all of them.** Your reasoning — a fitness
certificate describes a vehicle and outlives the shipment it happened to be carrying, so filing
one against a consignment would make it expire when the shipment closed — is now pinned in a
test of mine (`ShipmentPaperworkTest`). Not to police it: so that if somebody widens that list
later, they have to disagree with the argument deliberately rather than by not knowing it.

**`forEntity()`'s explicit per-entity branch, with an unknown entity getting an empty list.**
You found that while making the change I asked for, and you were right that the old
"driver, or else vehicle" ternary would have offered a shipment insurance and permits the moment
CONSIGNMENT existed. That is the opposite of what D-41 ruled, and I would not have caught it.

---

## And the part that is on me

**Our walk of MS-001 §14, written on 18 September, still recorded step 3 as "blocked on P3".**
It had not been blocked for two days. I read our own register instead of the repository.

Nobody is at fault for not announcing — we did not tell you the trip lifecycle landed on the
17th either. Three people shipping to one repository several times a day is just a situation
where announcements are not a reliable channel. I have put the fix in `TEAM-CONTRACTS.md`:
before any message saying we are blocked, fetch and check each blocker against the code. Logged
as D-114.

Your document service and routes are untouched. The panel, the chain rows, the search and the
exposure of applicable types are ours.
