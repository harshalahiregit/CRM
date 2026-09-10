# Audit and timeline

## Two sources, one timeline, no new tables

| | Reached through | Stored in | Editable |
|---|---|---|---|
| **System events** | `SireAuditProvider` | the host's polymorphic `audit_logs` | **Never** |
| **User comments** | `SireNotesProvider` | the host's polymorphic `notes` | By the author |
| **AI suggestions** | (SIRE's own) | `sire_ai_suggestions` | Never |

`Services\Sire\SireTimelineService` merges them for display. **It never writes.**

Writes go through `RecordsSireAudit`, a trait on SIRE's models: around 45
`recordAudit()` calls across the workflow, all arriving at the one bound adapter.
Change the binding and the entire trail moves; no service is touched.

**`SireAuditProvider` has no `update` and no `delete`, and there is no SIRE
endpoint that edits a recorded event.** That absence is the feature — release
approvals and emergency overrides are defended by this trail, and a history that
can be rewritten afterwards proves nothing. `tests/routes-resolve.test.mjs`
asserts no such route exists.

## The distinctions a reader must be able to make

Each entry carries a `kind`, and the three look different without a reader needing
to know the schema:

| Kind | Appearance | Attribution |
|---|---|---|
| `system` | grey, immutable | the person who acted |
| `comment` | blue, editable by its author | the person who wrote it |
| `ai_suggestion` | **dashed violet**, never editable | the **model**, not a person |

AI attribution to a model rather than a person is deliberate: a suggestion showing
someone's name is exactly the confusion the distinction exists to prevent.

Entry types surfaced: **system event · comment · attachment · status change ·
assignment · QA result · approval · release**.

## System history cannot be edited through SIRE

There is **no update or delete endpoint for an audit entry anywhere in SIRE**. A
history someone can rewrite is not a history.

`<HOST_AUDIT_SERVICE>` snapshots the actor's name and role, so a deleted user does
not blank the trail.

## What gets audited

**Recorded:** every status transition (with from → to), track change, assignee
change, root cause first recorded and confirmed, CAPA created/completed/verified,
approval requested/decided, release approved/shipped/rolled back, **every
emergency override**, AI suggestion decisions, test results, reopen, reject,
mark-duplicate.

**Not recorded:** typo fixes to title, description or location. `updated_at` plus
the notes thread is enough.

That policy is deliberate. The report identifies `audit_logs` as one of the two
highest-churn tables already, on a database shared by two deployments — auditing
every field change would make SIRE the largest contributor to a table that is
already a bottleneck.

## Extending the timeline without coupling

`Contracts\Sire\TimelineContributor` is a **core-owned interface**.
`SireTimelineService` accepts an iterable of them, defaulting to none.

This is what lets AI suggestions appear on a timeline that knows nothing about AI —
core imports the interface, never an implementation. Unbind the contributor and the
timeline is exactly what it was before AI existed.

A contributor that throws is dropped, not allowed to break the page. A timeline is
core; an optional extension must not be able to take it down.
