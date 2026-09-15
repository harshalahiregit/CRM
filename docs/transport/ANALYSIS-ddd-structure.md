# Analysis — the DDD structure instruction

**Author:** Person 1 · **Date:** 2026-09-15 · **Status:** analysis only — *nothing moved, created or renamed*
**Defect:** D-47 · **Related:** D-48 (integration contracts, sourced to TM-001 §11)

---

## 0. A correction, and a rule that follows from it

An earlier version of this file analysed **`~/Desktop/Sangoe_Transport_OS_Three_Developer_Split.pdf`**
(dated 2026-09-12). **That was the wrong document.**

> **`Sangoe_Transport_OS_Three_Developer_Split.pdf` has NO authority over this build.** It is an
> unapproved planning aid written on 12 September. It is not part of the STOS package, it was never
> approved, and **nothing in it is a requirement.** It must not be cited as a source.

Three findings in that earlier analysis were wrong and are withdrawn:

| Withdrawn claim | Correct position |
|---|---|
| "D-41 is reversed — `lr_records` / `delivery_orders` are DEV A tables" | **D-41 stands exactly as ruled.** That was a table list in an unapproved PDF. |
| "AI Gateway conflict — DEV A vs Person 3" | **No conflict.** STOS-TM-001 §9: *"Person 3 owns the AI Gateway foundation."* The PDF was wrong. |
| "Seven behavioural requirements we do not have" | They were seven ideas from an unapproved document. Treating them as requirements would be inventing rules — Hard Rule 1. |

**The rule this produces:** when a document is referenced without its text or a path, ask for it.
Do not go looking for a file that resembles the description. Finding a plausible file is worse than
finding nothing, because the analysis then looks complete.

What survives from that work is the measurement of the repository, which was taken against the
codebase and not the PDF, and the integration-contract question — now re-sourced to **TM-001 §11**
and tracked as **D-48**.

---

## 1. The instruction, in full

This is the whole of it:

> All developers must organize code using Domain-Driven Design (DDD) under `app/Domains/`.
> Do not put business logic inside Controllers.
>
> ```
> app/
> ├── Domains/
> │   ├── Operations/          <-- Developer 1
> │   │   ├── Models/          (TransportOrder, Trip, Consignment)
> │   │   ├── Services/        (TripDispatchService, OrderService)
> │   │   └── Events/          (TripDispatched, TripDelivered)
> │   ├── Fleet/               <-- Developer 2 (You)
> │   ├── Integration/         <-- Developer 2 (You)
> │   ├── Finance/             <-- Developer 3
> │   ├── Document/            <-- Developer 3
> │   └── Compliance/          <-- Developer 3
> └── Http/Controllers/Api/V1/Transport/
> ```

It is addressed to **Developer 2** — the tree reads *"Developer 2 (You)"*. Whether it binds Person 1
is a question for the owner, not an assumption for me.

**It contains no interface list, no event contract, no DTO shapes and no CI enforcement.** It is a
layout instruction plus one rule about controllers.

---

## 2. What we already comply with

**"Do not put business logic inside Controllers" — we already do this, and it is tested.**

Every Transport controller is thin: FormRequest → service → `ApiResponse`. `TransportConsignmentController`
is 110 lines and contains no query and no rule. Every business rule this module has — the dispatch
gate, pre-trip re-validation, the consignment refusals, BR-P0-011 — lives in a service. The one piece
of logic that briefly sat in a controller (`?status=` refusal) is a validation rule, in the request layer.

So the second sentence of the instruction costs us nothing. **The disagreement is only about folders.**

Our `Domains/Operations/` contents would be, near enough, exactly what we own now:

| Instruction says Operations holds | We have |
|---|---|
| `TransportOrder` | `app/Models/Transport/TransportOrder.php` |
| `Trip` | `app/Models/Transport/TransportTrip.php` |
| `Consignment` | `app/Models/Transport/TransportConsignment.php` |
| `OrderService` | `app/Services/Transport/TransportOrderService.php` |
| `TripDispatchService` | `app/Services/Transport/DispatchService.php` |
| `Events/TripDispatched`, `TripDelivered` | **neither exists** — see D-48 |

---

## 3. The cost, measured against the repository

| | |
|---|---|
| Transport PHP files under `app/` | **88** |
| Transport test files | **27** |
| `App\…\Transport` namespace occurrences | **543**, across **106 files** |
| Passing Transport tests that would all need to resolve moved classes | **671** |
| Frontend files referencing `modules/transport` / `transportApi` | **14** |
| Module folders under `app/Models/` | **30** — none using `app/Domains/` |
| Days to the 30 September milestone | **15** |

---

## 4. Three options

### Option 1 — Keep TEAM-CONVENTIONS, defer the structure past the milestone

| Files moved | Test risk | Blocks | Others must move | Cost |
|---|---|---|---|---|
| 0 | none | nobody | no | **0 days** |

Block 1 continues. The controller rule is already satisfied.

### Option 2 — Adopt for Transport only, now

| Files moved | Test risk | Blocks | Others must move | Cost |
|---|---|---|---|---|
| 88 + 27 tests, 543 namespace edits | **High** — 671 tests, every one resolving a moved class | Block 1 stops | **yes, unavoidably** | **3–5 days**, optimistically |

**This option is not available to Person 1.** The instruction splits `app/Models/Transport/` between
`Domains/Operations/` (mine: TransportOrder, Trip, Consignment) and `Domains/Fleet/` (Person 2's:
`TransportVehicle`, `TransportDriver`). Performing that split means editing Person 2's files —
the boundary held since D-39. It needs Person 2 working in the same commit, or it does not happen.

It also leaves the repository in a **third** state: 29 modules on one convention, one on another.

### Option 3 — Adopt across all modules

| Files moved | Test risk | Blocks | Others must move | Cost |
|---|---|---|---|---|
| Transport's 88 are a fraction of 30 model folders | **Severe** — full suite ~2,700 assertions | **all three developers, simultaneously** | **yes, same commit or the repo does not compile** | **2–4 weeks** |

Against 15 days to a milestone whose exit condition is *"the chain runs without manual database
intervention"*, this ends the milestone.

---

## 5. Recommendation — Option 1, defer

Grounds, on structure alone:

1. **We already comply with the half that has behavioural meaning.** Business logic is out of the
   controllers and tested to be. What remains is a path change.

2. **A namespace move is cheap later and expensive now.** It can be done in a quiet week with a green
   suite on both sides. It cannot be done in 15 days alongside a milestone without risking both.

3. **Person 1 cannot execute it alone.** Person 2's models sit in the folder that must be split.
   Not reluctance — the same rule that has governed every decision since D-39.

4. **Deferring costs almost nothing.** Our layout is already one module per folder, with a service
   layer, thin controllers and no cross-module concrete references. That is the shape the instruction
   wants; only the path differs. The move is mechanical whenever it happens.

5. **The instruction is addressed to Developer 2.** Before three developers spend a week on a
   repository-wide move, it is worth establishing whether it was meant to bind all three.

### What I would do instead, now, at low cost

**Emit `EVT-001 OrderCreated` and `EVT-002 TripCreated`** — roughly half a day, tracked as **D-48**.
Both are **LOCKED** in Step 11's registry, both name Person 1 as producer, and both sit on code paths
that run in production today and emit nothing. That is a real registry gap, sourced to an approved
document, and fixing it gives Person 2 and Person 3 something to subscribe to instead of a reason to
read our tables — which is D-46's residual exposure.

That is worth more to the other two developers than any folder rename.

---

## 6. What I have NOT done

No `app/Domains/` created. No file moved. No namespace renamed. Nothing in `app/Models/Transport/`
touched. Block 1 continues on the current structure until the owner rules.
