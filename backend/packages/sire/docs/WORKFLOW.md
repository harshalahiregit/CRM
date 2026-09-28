# Workflow

**Authoritative source: `src/Support/SireWorkflow.php`.**

`workflow/workflow.json` and `resources/js/lib/sire/workflow.generated.js` are
**exports**, produced by `php artisan sire:export-workflow`. The full state and
transition tables are in [`../workflow/WORKFLOW-STATES.md`](../workflow/WORKFLOW-STATES.md).

## PHP and JS cannot silently diverge

`tests/workflow-parity.test.mjs` parses the PHP **independently of the exporter**
and compares every state, label, tone, terminal flag, transition, capability,
guard, required field and track. It fails the build on drift.

The SPA uses the export only for labels, colours and ordering. **Legality is
decided server-side**: the case payload carries `available_transitions` computed by
`SireWorkflowService`, and the client renders what it is given rather than
recomputing the rules.

## The defect path

```
NEW → TRIAGED → ASSIGNED → IN_DEVELOPMENT → READY_FOR_QA
    → QA_IN_PROGRESS → QA_PASSED → READY_FOR_RELEASE
    → RELEASED → PRODUCTION_VALIDATED → CLOSED
```

Side states: `ON_HOLD` · `REOPENED` · `DUPLICATE` · `REJECTED` · `WONT_FIX` ·
`CANNOT_REPRODUCE`

## The change-request path

A change request is a `sire_reports` row with `workflow_track = 'change'` — **not a
separate entity**. It runs its own approval-gated path, then **rejoins the defect
track at ASSIGNED** and shares the whole QA and release pipeline.

```
NEW → TRIAGED → BUSINESS_REVIEW → IMPACT_ANALYSIS → APPROVAL → PLANNED
                                                                  ↓
                                              ASSIGNED → IN_DEVELOPMENT → (QA → release)
```

A parallel table would have meant a second numbering series, timeline, SLA and
dashboard — for a record that becomes ordinary engineering work once approved.

Change-only transitions carry `tracks: ['change']`, so a defect can never enter
business review. `APPROVAL` raises a row in `sire_approvals`.

## Developer workflow

| Step | Action | Effect |
|---|---|---|
| Accept | `accept_assignment` | Stamps `assignment_accepted_at`. **Deliberately not a status change** — acceptance and starting work are different moments. |
| Start | `start_development` | → `IN_DEVELOPMENT`. Opens a development work cycle. |
| Investigate | `add_investigation_notes` | Records work without moving the issue. |
| Fix summary | `add_fix_summary` | Required before QA handoff. |
| Self-test | `submit_dev_testing` | Records developer testing. |
| Hand off | `mark_ready_for_qa` | → `READY_FOR_QA`. **Requires `fix_summary`.** |

## QA workflow

| Action | Effect |
|---|---|
| `start_qa` | → `QA_IN_PROGRESS`, opens a QA cycle, claims QA ownership |
| `qa_pass` | → `QA_PASSED`, then **auto-advances** to `READY_FOR_RELEASE` |
| `qa_fail` | → `QA_FAILED`. **Requires notes** — a failure without them is useless to the developer. **Notifies the assigned developer.** |
| `add_qa_notes` | Records notes without a verdict |
| `pull_back_to_development` | Returns an issue QA has not started |

### The failure loop

```
QA_FAILED → start_development → IN_DEVELOPMENT → mark_ready_for_qa
          → READY_FOR_QA → start_qa → QA_IN_PROGRESS → …
```

Each pass through opens a new `sire_work_cycles` row, so a fail → fix → retest
round trip stays legible after the second attempt overwrites the first's notes.

**QA results are human-controlled.** Test-case results are written in exactly one
method, by a person, with `sire.qa.execute`, only while the issue is in development
or QA. There is no bulk pass and no automatic pass.

`sire.auto_ready_for_release` (default `true`) governs the auto-advance. Off, the
issue rests at `QA_PASSED` for a lead to advance explicitly.

## Guards

| Transition | Refuses unless |
|---|---|
| `triage` | severity and priority set |
| `assign` | an assignee is named |
| `start_development` | caller is the assignee, or a lead |
| `mark_ready_for_qa` | `fix_summary` present |
| `qa_fail` | `qa_notes` present |
| `release` | `release_ref` present |
| `hold` | a reason given |
| `mark_duplicate` | a target issue, and no loop |
| `reject` / `wont_fix` / `cannot_reproduce` | a resolution note |
| `close_directly` | a resolution note |
| `close` | a **confirmed** root cause, when the issue is critical, P1, recurring or reopened |

## Closing in one click — `close_directly`

Most defects are small, and walking a typo through assign → develop → QA →
release → validate to reach `CLOSED` is five clicks of ceremony for a one-line
fix. The cost of that ceremony is not the clicks: it is that people stop closing
things, and the backlog fills with work that is already done.

`close_directly` goes to `CLOSED` from **every live state except
`PRODUCTION_VALIDATED`**, takes `sire.report.close`, and requires a
`resolution_note`.

Three things about it are deliberate:

- **It grants no new power.** `wont_fix` already ended an issue from most of the
  same states on the same capability. The only difference is that the issue lands
  in `closed`, which is the honest label when the thing was in fact fixed.
- **It is not offered from `PRODUCTION_VALIDATED`.** That state keeps the guarded
  `close` above, and it is the one path that enforces the root-cause rule. If the
  shortcut appeared there too, that rule would be one button away from optional.
- **It carries no root-cause guard of its own.** A failing guard *removes* an
  action from `available_transitions` rather than refusing it, so guarding this
  one would make the button silently vanish on serious issues — which reads as a
  broken UI, not as a rule.

The note is the whole price, and it is not negotiable: an issue that leaves the
backlog without a word is one nobody can audit later.

## Reopen, hold, terminal

- **Reopen** works from every terminal state, increments `reopen_count`, clears the
  resolution, and **restarts the SLA clocks** — carrying old ones over would mark a
  reopened issue breached the instant it reopened.
- **Hold** remembers `held_from_status`, so resume returns exactly where it paused.
  Not available from `NEW` (triage it first) or `QA_IN_PROGRESS` (finish the run).
- **Every terminal status stamps `closed_at`**, not just `close` — otherwise an
  issue closed as `wont_fix` accrues elapsed time forever.
