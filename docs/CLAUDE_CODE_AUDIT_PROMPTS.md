# TPV/CRM Gap Audit — Claude Code Prompt Pack

Run these in order. Each phase produces a file on disk, so progress survives
session restarts, context compaction, and you closing your laptop.

## Setup (do this once, before Phase 0)

```
your-repo/
└── docs/
    └── source/          ← copy all 18 .md files here
```

The register file (`docs/audit/requirements.md`) is the single source of truth for
"what's done". Never let it live only in chat.

---

## PHASE 0 — Build the requirement register

> **Paste this:**

I'm auditing an existing CRM/TPV codebase against 18 specification documents from
my senior. Before touching any code, I need an exhaustive requirement register.

Read every file in `docs/source/`. Extract **every atomic, checkable requirement** —
not summaries. Each one gets its own row.

Granularity rule: a requirement is atomic if a developer could mark it done or not
done without ambiguity. "Vendor onboarding wizard" is too coarse. "Step 2 shows
amber indicator when a document is expiring soon" is atomic. Database tables get one
row per table, plus one row per column that carries business logic (status enums,
FKs, validity/expiry dates) — plain `id`/`created_at` columns don't need rows.

Write `docs/audit/requirements.md` as a Markdown table:

| ID | Source doc | Module | Requirement | Type | Depends on | Status | Evidence |

- **ID**: `REQ-<DOCSHORT>-<nnn>`, e.g. `REQ-STATUS-014`, `REQ-DOC4-007`
- **Type**: schema / api / ui / logic / workflow / report / integration / notification
- **Status**: leave every row as `UNAUDITED` for now
- **Evidence**: leave blank

Rules that matter:
- Do not merge similar requirements from different docs. If `Tpv_doc` and
  `27-2-2026onboardingprocess` both describe onboarding steps, both get their own
  rows even where they overlap — they contradict each other and I need to see it.
- Do not invent requirements to fill perceived gaps. Only what the documents say.
- Quote or closely paraphrase the source so I can trace each row back.

When done, report only: total count, count per source doc, count per type.

---

## PHASE 1 — Record the known conflicts and blockers

> **Paste this:**

Now create `docs/audit/conflicts.md`. I already know about these three — verify each
against the docs, correct me if I'm wrong, and add any others you find:

1. **Doc 3 is missing.** The ER series has Doc 1, 2, 4, 5, 6 — Workforce & Access
   Control is absent, but Docs 4, 5, 6 and the UI wireframe all reference "Doc 3
   tables" as a dependency. Find every requirement that transitively depends on Doc 3
   and mark those rows `BLOCKED-DOC3` in the register.

2. **Onboarding step count conflict.** `Tpv_doc.md` defines a 6-step vendor
   onboarding ending at Start Work Letter. `27-2-2026onboardingprocess.md` defines
   5 steps in a different order. Lay them side by side so I can take it to my senior.

3. **Scope conflict.** `CRM_Project_TASKS_Vendor_TPV.md` says enhancement-only, do
   not break existing features. `1_CRM_TPV.md` describes a 46-step greenfield
   architecture. Flag which register rows fall on each side of that line.

Then scan for further contradictions across all 18 docs — conflicting field names,
status enums, approval sequences, role names. Add each to `conflicts.md`.

**Critical:** where documents conflict, do not choose. Do not implement either side.
Record both and stop. I'll get a decision from my senior. Guessing here creates
rework that costs more than the delay.

Also note: `Comments_Harshal.md` is HRMS feedback for a different product, and
`Report.md` is a frontend-tooling note. Keep their requirements in the register but
tag the module `OUT-OF-SCOPE-HRMS` / `OUT-OF-SCOPE-NOTES` so they don't pollute the
TPV build.

---

## PHASE 2 — Inventory what actually exists

> **Paste this:**

Don't read the specs for this step — I want an unbiased picture of the codebase, so
that you're describing what's there rather than looking for what you expect.

Inventory the repository into `docs/audit/codebase-inventory.md`:

- **Database**: every table, its columns, types, FKs, indexes. Read the migrations
  *and* the actual schema if a DB is reachable — migrations sometimes lie about the
  live state. Note any drift.
- **Models**: relationships, scopes, casts, observers.
- **API**: every route, its controller method, middleware, and auth guard.
- **Frontend**: pages/screens, major components, forms, state stores.
- **Background**: jobs, queues, scheduled commands, event listeners, mail/notifications.
- **Access control**: roles, permissions, policies, and where they're enforced.

For each item record the file path. Where something is clearly a stub — a route with
an empty controller, a component rendering placeholder text, a migration with no
model — say so explicitly rather than counting it as built.

---

## PHASE 3 — Audit, with evidence

> **Paste this:**

Now audit the register against the inventory. Work module by module, updating
`docs/audit/requirements.md` in place as you go — don't hold results in memory and
write at the end, because if this session compacts I lose everything.

Set each row's Status to exactly one of:

- `DONE` — fully implemented and wired end to end
- `PARTIAL` — exists but incomplete; the Evidence column must say what's missing
- `MISSING` — no implementation found
- `CONFLICT` — spec contradiction, from `conflicts.md`
- `BLOCKED-DOC3` — depends on the missing Doc 3
- `OUT-OF-SCOPE` — HRMS or notes

**Evidence is mandatory for every `DONE` and `PARTIAL`.** Format: `path/to/file.php:142`.
A requirement with no file reference is not `DONE`, however obvious it seems.

Be strict about what "done" means. A migration creating `tbl_tpv_vendors` does not
make the vendor master `DONE` if there's no model, no API, and no UI touching it —
that's `PARTIAL` with the gap named. I'd much rather over-report gaps and delete rows
later than ship believing something works when it doesn't.

When finished, write `docs/audit/GAP-REPORT.md` for my senior:
- Counts and percentage by status, overall and per module
- The critical path: what blocks the most other work
- Everything `BLOCKED-DOC3`, as a formal request for the missing document
- Everything `CONFLICT`, as decisions I need from him
- Your recommended build order

On order: `1_Vendor_Onboarding_Status_Logic.md` defines an 11-state machine with 5
hard gates, and the wireframe explicitly says the UI cannot override status rules.
So the state machine and its gate conditions come before any onboarding UI work. If
your analysis disagrees with that, say so and explain why.

---

## PHASE 4 — Implement, one module at a time

> **Paste this, once per module:**

Implement the `MISSING` and `PARTIAL` requirements for module **<MODULE NAME>** from
`docs/audit/requirements.md`.

Before writing code, list the requirement IDs you're about to implement and wait for
my confirmation.

Ground rules:
- Branch: `feat/audit-<module>`. Never commit straight to main.
- Do not touch working features outside this module — my senior's instruction in
  `CRM_Project_TASKS_Vendor_TPV.md` is explicit that existing functionality must not
  regress. If a change requires modifying shared code, tell me before you do it.
- Skip anything `BLOCKED-DOC3` or `CONFLICT`. Don't improvise the missing schema.
- Follow the existing conventions in the codebase — naming, structure, error handling.
  Match what's there rather than importing patterns from elsewhere.
- After each requirement is implemented, update its row to `DONE` with the file
  reference, in the same commit as the code.
- Write tests for the gate conditions specifically. The status logic is where a
  compliance bug becomes a legal problem, given this feeds HSSE approval on a live
  site with real workers.

When the module is complete: summarise what changed, what tests cover it, and any
requirement you couldn't satisfy and why.

---

## PHASE 5 — Verify nothing was lost

> **Paste this after each module:**

Re-run the audit for module **<MODULE NAME>** only. Re-read the source docs for that
module and re-check every requirement row against the current code, from scratch —
treat the existing `DONE` marks as claims to verify, not facts.

Report any row marked `DONE` that you cannot prove with a file reference, and any
requirement in the source docs that never made it into the register at all.

---

## Optional: `CLAUDE.md` for the repo root

Drop this in so every session starts with the rules already loaded.

```markdown
# Project context

Multi-tenant CRM with a Third Party Vendor (TPV) governance module.
Client: Nexfore Consulting, acting as PMC on the DNEG/Prime Focus Adlab project.

## Audit workflow
- Specs: `docs/source/` (18 documents, authoritative)
- Requirement register: `docs/audit/requirements.md` — the source of truth for status
- Conflicts and blockers: `docs/audit/conflicts.md`

## Rules
- Update the register in the same commit as the code it describes.
- `DONE` requires a `file:line` reference. No exceptions.
- Doc 3 (Workforce & Access Control) does not exist. Never invent its schema —
  mark dependent work `BLOCKED-DOC3` and move on.
- Where specs conflict, surface both and stop. Do not choose.
- Enhancement-only on existing CRM features; do not regress working functionality.
- Onboarding status logic is a strict state machine. No free-text status, no skipped
  gates, and the UI never overrides it.
```
