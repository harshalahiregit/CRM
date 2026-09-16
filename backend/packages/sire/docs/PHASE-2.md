# Phase 2 — Quality & Governance

Eight capabilities. The distinction the whole phase rests on:

> **Duplicate** = the same occurrence reported twice.
> **Recurring** = the same defect happening again.
> **Regression** = a defect a change re-introduced.

Conflating them is the commonest way a defect register stops meaning anything.

---

## 1. Duplicate management

**Data flow:** mark duplicate → validate → flatten the chain → link → audit both sides.

- `sire_reports.duplicate_of_id` — a **1:1 pointer on the row**, guarded by the
  `mark_duplicate` transition. Many-to-many relationships live in
  `sire_report_links`; two sources of truth for one relationship is how they drift.
- **Nothing is deleted.** A duplicate keeps its number, comments, attachments,
  audit trail and reporter. History is written on **both** sides.
- **Chains flatten on write.** A → B where B duplicates C links A straight to C.
- **Loops are refused with the loop named.** A → B → A is easy to create by
  accident: mark A duplicate of B on Monday, then B duplicate of A on Friday.

**Deterministic, not AI.** `SireDuplicateService` handles the relationship;
`SireDuplicateDetector` *suggests* candidates from a relational index. See
[`AI.md`](AI.md) for where a future external model would attach.

## 2. Root cause

`sire_root_causes`, one per issue: category, description, contributing factors,
detection gap, corrective action, preventive action, five whys, `confirmed_by`,
`confirmed_at`.

**Human-confirmed, always.** AI may suggest; confirming is
`SireRootCauseService::confirm()` with its own capability.

**Five Whys are required for serious issues only** — derived from data the register
already holds (critical severity, P1, a recurrence, a reopen), never a checkbox.
Requiring them everywhere is how a form gets filled with "because it was broken"
five times.

**Editing a confirmed analysis clears the confirmation.** A signature attests to
what it signed.

## 3. Release / version mapping

`sire_releases` plus four version roles per issue:

| Role | Question |
|---|---|
| `detected_version_id` | where we first saw it |
| `affected_versions` (json) | everywhere it is present — display only |
| `fixed_version_id` | where the fix was merged |
| `released_version_id` | where the fix actually **shipped** |

Fixed and released are separate on purpose: a fix merged into 2026.4 that slips the
train has been fixed and **not** released. Conflating them tells a customer a bug is
gone before it is.

**Rolling back clears the released stamp** on every issue in the release.

Release lifecycle: `BLOCKED ⇄ READY → APPROVED → RELEASED` (plus `CANCELLED` and
`ROLLED_BACK`). **READY and BLOCKED are derived from the gates, never set.** See
[`../workflow/WORKFLOW-STATES.md`](../workflow/WORKFLOW-STATES.md).

### Release gates

Four defaults, configurable in one settings key:

- no open critical issues
- no unresolved QA failures
- required approvals complete
- mandatory regression testing complete

An **unknown** gate key **blocks** — a typo in settings must not silently disable
governance. An **empty** gate list reports `ungoverned` rather than all-clear.
`blocking: false` makes a gate advisory.

Emergency overrides need a separate capability, **name the specific gates**, and
**snapshot what those gates said** — six months later *"we overrode the critical
gate"* is worth little next to *"we overrode it while three criticals were open"*.
Both an audit entry and a queryable register.

## 4. Change approval

A change request is a `sire_reports` row with `workflow_track = 'change'`.

```
TRIAGED → BUSINESS_REVIEW → IMPACT_ANALYSIS → APPROVAL → PLANNED → ASSIGNED
```

`request_change_approval` opens a pending `sire_approvals` row; `approve_change` /
`reject_change` decide it. Without that register the track would transition with no
record of who approved what.

**Reuses the purchase/tpv register shape** so a future consolidation of the host's
five approval implementations is a merge, not a rewrite. **No sixth engine.**

## 5. Regression tracking

`is_regression`, `regression_of_id`, `caused_by_release_id`, `regression_notes`,
plus a `regression_of` link so the **original** issue also shows that it came back.

**Deliberately manual.** Inferring regressions from text similarity would quietly
poison the regression rate on the dashboard.

## 6. Recurring bugs

`sire_recurrence_groups`: occurrence count, first and latest occurrence, average
interval, root cause, permanent fix status, owner, risk.

**Every statistic is derived and recomputed** — a count someone can type is a count
that will be wrong.

Grouping is human. The engine contributes a deterministic signature
(`module|section|screen|type`) used to **suggest** candidates by exact match —
closed issues only, never duplicates, nothing auto-assigned.

**A duplicate can never join a group.** It is not an occurrence, and counting it
inflates the number the engine exists to report. Enforced, not documented.

Risk is a documented formula (occurrences, interval, fix status, recency). Two
rules a naive version gets wrong: an **unknown interval scores 0, not worst-case**;
and a **verified fix forces low — unless it recurred since**, because then the fix
did not work.

## 7. Knowledge base linkage

**SIRE has no KB and must not grow one.** `sire_kb_links` is a join to
`<HOST_KNOWLEDGEBASE_SERVICE>`'s articles. Five link types: resolution, known issue,
prevention, troubleshooting, reference.

"Create article from resolved issue" creates a **draft** — SIRE proposes, the KB
owner publishes. Unlinking removes the link, never the article.

## 8. Automatic release notes

`sire_release_notes`, one row per (release, audience).

**Structured data first** — entries are built from columns, not by parsing prose.

| Audience | Contains |
|---|---|
| Internal | issue numbers, modules, assignees, fix summaries, root-cause categories, regression flags |
| User | one line per user-visible change; no issue numbers, no internal titles |

**An issue with no `user_facing_summary` is excluded from the user-facing document
rather than falling back to its title.** *"null deref in LeadPolicy::view"* is not a
release note, and a silent fallback is how it reaches a customer.

**Security fixes are counted, never described** in user-facing notes. A public note
describing a vulnerability is a working exploit for anyone who has not patched.

**Publication requires an approval on the record**: generate → submit → approve →
publish. Once published the note is **frozen** — reopening an issue must not
rewrite what customers have already read.
