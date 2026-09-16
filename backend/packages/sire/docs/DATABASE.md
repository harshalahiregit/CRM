# Database

**28 migrations. 22 tables, every one prefixed `sire_`.**

## The two rules

**1. `tenant_id` is NOT NULL on every tenant-owned table.**

`BelongsToSireTenant`'s auto-stamp is guarded by an authenticated tenant, which is absent in a
scheduled command, a queued job or any token-resolved route. A nullable column
silently absorbs that mistake and produces rows that belong to nobody and match no
`forTenant()` query. NOT NULL makes the database refuse it.

**2. SIRE creates and alters no table it does not own.**

Verified by `tests/schema-completeness.test.mjs`. Helpdesk, Tasks, notifications
and attachments cannot be affected by these migrations, because none of them names
those tables.

## The three SDK fallback tables

`sire_settings`, `sire_notes` and `sire_audit_events` exist so SIRE can install
into a host with no settings, notes or audit system of its own. They are what
turns "SIRE needs these four subsystems before it runs" into "SIRE runs, and you
connect subsystems when you want to".

**They are not a second system.** Exactly one implementation of each provider is
ever bound: bind a host provider and the corresponding table simply stays empty.
`php artisan sire:doctor` prints which is which, so a half-migrated state is
visible rather than discovered.

`sire_audit_events` has **`created_at` and no `updated_at`**, deliberately. There
is no update path, no delete path, and no SIRE endpoint that edits a row — the
model throws on both, and a test asserts no such route exists. Release approvals
and emergency overrides are defended by this trail, and a history that can be
rewritten afterwards proves nothing.

## Index naming

Every composite index is named explicitly and kept short. MySQL caps identifiers at
64 characters; Laravel's generated names exceed it; and the test suite runs on
SQLite, which does not care — so an overrun fails in production and nowhere else.
The discovery report records a Customer 360 migration failing on live for exactly
this. **Longest SIRE identifier: 28 characters.**

## Migrations

All **DIRECTLY USABLE** — every table is SIRE-owned, so nothing here needs adapting
to your schema. Rename `2026_MM_DD_` to real dates, keeping the order.

| # | Migration | Creates / alters |
|---|---|---|
| 000001 | create_sire_report_categories_table | creates `sire_report_categories` |
| 000002 | create_sire_severities_table | creates `sire_severities` |
| 000003 | create_sire_reports_table | creates `sire_reports` |
| 000004 | create_sire_approvals_table | creates `sire_approvals` |
| 000007 | create_sire_report_contexts_table | creates `sire_report_contexts` |
| 000008 | add_engineering_workflow_to_sire_reports | alters `sire_reports` |
| 000009 | create_sire_work_cycles_table | creates `sire_work_cycles` |
| 000010 | add_sla_tracking_to_sire_reports | alters `sire_reports` |
| 000011 | create_sire_releases_table | creates `sire_releases` |
| 000012 | create_sire_root_causes_table | creates `sire_root_causes` |
| 000013 | create_sire_recurrence_groups_table | creates `sire_recurrence_groups` |
| 000014 | create_sire_report_links_table | creates `sire_report_links` |
| 000015 | create_sire_kb_links_table | creates `sire_kb_links` |
| 000016 | create_sire_release_notes_table | creates `sire_release_notes` |
| 000017 | create_sire_actions_table | creates `sire_actions` |
| 000018 | add_quality_governance_to_sire_reports | alters `sire_reports` |
| 000019 | add_release_governance | creates `sire_release_overrides`, alters `sire_releases`, alters `sire_reports`, alters `sire_report_categories` |
| 000020 | create_sire_ai_suggestions_table | creates `sire_ai_suggestions` |
| 000021 | create_sire_issue_tokens_table | creates `sire_issue_tokens` |
| 000022 | create_sire_test_cases_table | creates `sire_test_cases` |
| 000023 | create_sire_settings_table | creates `sire_settings` |
| 000024 | create_sire_notes_table | creates `sire_notes` |
| 000025 | create_sire_audit_events_table | creates `sire_audit_events` |
| 000026 | fix_sire_release_default_status | alters `sire_releases` — the column defaulted to `planned`, which is not a release status |
| 000027 | create_sire_report_watchers_table | creates `sire_report_watchers` |
| 000028 | add_method_to_sire_root_causes | alters `sire_root_causes` — which RCA technique was used, and its working |
| 000029 | add_customer_to_sire_reports | alters `sire_reports` — the customer a defect affects |
| 000030 | create_sire_report_assignees_table | creates `sire_report_assignees` |

## Tables

| Table | Purpose | Tenant column |
|---|---|---|
| `sire_report_categories` | Per-tenant issue type master. Also maps a type to its release-notes class. | `tenant_id` NOT NULL |
| `sire_severities` | Per-tenant severity with SLA targets. `level` is the sort key, never `code`. | `tenant_id` NOT NULL |
| `sire_reports` | The register. The central table every other SIRE table points at. | `tenant_id` NOT NULL |
| `sire_approvals` | Approval register, copying the purchase/tpv shape. Append-only. | `tenant_id` NOT NULL |
| `sire_report_contexts` | Diagnostic sidecar for issues filed through Report Issue. | `tenant_id` NOT NULL |
| `sire_work_cycles` | One row per development pickup and per QA run. Makes a fail→fix→retest round trip legible. | `tenant_id` NOT NULL |
| `sire_releases` | The release register. Issues reference it in four version roles. | `tenant_id` NOT NULL |
| `sire_root_causes` | Root cause analysis, one per issue. Human-confirmed. | `tenant_id` NOT NULL |
| `sire_recurrence_groups` | A defect that keeps coming back. Every statistic is derived, never entered. | `tenant_id` NOT NULL |
| `sire_report_links` | Many-to-many issue relationships. Duplicates live on the row, not here. | `tenant_id` NOT NULL |
| `sire_kb_links` | Join to the EXISTING knowledge base. SIRE has no KB of its own. | `tenant_id` NOT NULL |
| `sire_release_notes` | Generated notes per release and audience. Frozen once published. | `tenant_id` NOT NULL |
| `sire_actions` | Corrective and preventive actions. Attaches to an issue OR a recurrence group. | `tenant_id` NOT NULL |
| `sire_release_overrides` | Emergency gate overrides. A queryable register, not just an audit line. | `tenant_id` NOT NULL |
| `sire_ai_suggestions` | The ONLY table the AI layer writes to. | `tenant_id` NOT NULL |
| `sire_issue_tokens` | Relational inverted index for duplicate detection. Derived; rebuildable. | `tenant_id` NOT NULL |
| `sire_test_cases` | Test cases against an issue. `result` is written in exactly one place, by a human. | `tenant_id` NOT NULL |
| `sire_settings` | SDK fallback: per-tenant `sire.*` configuration. Empty when a host settings provider is bound. | `tenant_id` NOT NULL |
| `sire_notes` | SDK fallback: comments. Empty when a host notes provider is bound. | `tenant_id` NOT NULL |
| `sire_report_assignees` | Everyone working an issue besides its owner. `sire_reports.assignee_id` stays the owner; every workflow guard is written against it. | `tenant_id` NOT NULL |
| `sire_report_watchers` | Who else is told about an issue. Subscription only — it never widens what anybody may read. | `tenant_id` NOT NULL |
| `sire_audit_events` | SDK fallback: the system-event trail. **Write-once — no `updated_at`, no delete path.** Empty when a host audit provider is bound. | `tenant_id` NOT NULL |

## Relationships

All cross-table links inside SIRE are **logical** — an indexed
`unsignedBigInteger` with no foreign-key constraint — following the host's
row-level multi-tenancy convention. Real FK constraints exist only to
`<HOST_USERS_TABLE>` and `<HOST_TENANTS_TABLE>`, and only where the host already does so.

```
sire_reports ──┬── category_id ──────────▶ sire_report_categories
               ├── severity_id ──────────▶ sire_severities
               ├── duplicate_of_id ───────▶ sire_reports        (1:1, on the row)
               ├── recurrence_group_id ──▶ sire_recurrence_groups
               ├── detected/fixed/released_version_id ─▶ sire_releases
               ├── caused_by_release_id ─▶ sire_releases
               └── related_type/id ──────▶ ANY CRM record (ticket, task, client…)

sire_reports ◀─┬── sire_root_causes        (1:1)
               ├── sire_report_contexts    (1:1)
               ├── sire_work_cycles        (1:N)
               ├── sire_test_cases         (1:N)
               ├── sire_report_links       (N:N)
               ├── sire_kb_links           (N:N → kb_articles, logical)
               ├── sire_actions            (1:N, or to a recurrence group)
               └── sire_ai_suggestions     (1:N, polymorphic within SIRE)
```

## Duplicate, recurring, regression — three different things

The distinction the schema exists to preserve:

| | Meaning | Where it lives |
|---|---|---|
| **Duplicate** | The SAME occurrence reported twice. One is real work; the other closes with a pointer. | `sire_reports.duplicate_of_id` |
| **Recurring** | The same defect happening AGAIN, later. Every occurrence is real, separate work. | `sire_recurrence_groups` |
| **Regression** | A defect a change RE-INTRODUCED. | `is_regression` + `caused_by_release_id` |

A duplicate can never join a recurrence group — it is not an occurrence, and
counting it would inflate the number the whole recurrence engine exists to report.
Enforced in `SireRecurrenceService`.
