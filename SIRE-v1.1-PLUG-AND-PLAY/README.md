# SIRE v1.1 — Sangoe Issue & Resolution Engine

**An engineering-quality platform that installs into your Laravel CRM.** An
issue enters as a report and leaves as a verified fix in a released version.

```bash
composer require sangoe/sire
php artisan sire:compatibility   # will this work here?
php artisan sire:discover        # read-only: what does this CRM look like?
php artisan sire:install         # applies what it knows, asks what it doesn't
php artisan sire:doctor          # is it actually connected?
```

New to this? → **[docs/JUNIOR-DEVELOPER-START-HERE.md](docs/JUNIOR-DEVELOPER-START-HERE.md)**

---

## Read this first

> **SIRE runs before you integrate anything.** All thirteen SDK contracts ship
> with a working implementation SIRE owns, so you can walk an issue through the
> entire workflow on day one. Connecting your subsystems is incremental, and six
> of them are permanently optional.

**SIRE has not been installed into a host application.** Nothing here has run
against a real CRM's database or browser. It is verified by **407 executable
checks** over its own source, plus three reference host fixtures that run the
real classification logic against deliberately different CRM shapes.

That distinction matters. A green suite proves SIRE's logic is not written for
one host. It does not prove SIRE runs inside yours, and nobody should read it
as if it did — see [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md) for what is
verified and what is not.

**The one thing SIRE cannot verify for you is tenancy.** It is the only
integration point whose failure is silent, so the installer refuses to guess it
and [the install guide](docs/JUNIOR-DEVELOPER-START-HERE.md#11-prove-tenant-isolation--do-not-skip-this)
ends with a manual two-tenant check.

---

## Designed for compatible Laravel CRMs

Not "works with every CRM" — that claim would be false, and you would find out
at the worst moment.

| | |
|---|---|
| **PHP** | 8.2 · 8.3 · 8.4 |
| **Laravel** | 10 · 11 · 12 |
| **Database** | MySQL 8+ / MariaDB 10.6+ (SQLite and Postgres conditional) |
| **Auth** | Sanctum · session · Passport · JWT · custom guard — composed from config |
| **Tenancy** | column · relationship · resolver · callable · single-tenant |
| **Roles** | a column · a table · spatie/permission · Gate · none at all |
| **Frontend** | React (full) · Vue, Livewire, Blade (widget + API) · none (API) |

`php artisan sire:compatibility` gives you a verdict per row: **SUPPORTED**,
**CONDITIONAL**, **MANUAL** (write one small adapter — not a failure), or
**UNSUPPORTED**. Full matrix: [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

**SIRE needs no Redis, no queue worker, no vector database, no AI vendor, no
search service, no npm package, and no Composer package beyond Illuminate.**

---

## What SIRE is not

**Not a second Helpdesk.** The boundary, in one line:

> Someone asking us to **do** something for them → **Helpdesk ticket**
> Someone recording that something **broke** → **SIRE case**

A ticket's terminal state is a satisfied requester. A SIRE case's terminal state
is a verified fix in a shipped release. Different objects, different clocks.

SIRE therefore ships **no** public widget, **no** inbound-email intake, **no**
customer-facing cases, **no** customer-facing SLA, and **no** second knowledge
base. It links to a Helpdesk ticket through `related_type` / `related_id` and
**touches no Helpdesk table**.

That boundary is decision **D1**, and it is the one most likely to erode.
See [docs/DECISIONS.md](docs/DECISIONS.md).

---

## How it stays CRM-agnostic

SIRE core imports nothing from your application. Everything host-shaped goes
through one of thirteen contracts, and every contract has a default.

```
     your CRM                SDK contracts              SIRE core
  ┌──────────────┐      ┌────────────────────┐     ┌──────────────┐
  │ users        │      │ SireUserProvider   │     │              │
  │ tenants      │─────▶│ SireTenantProvider │────▶│  186 classes │
  │ roles        │      │ SireAuthorization… │     │  20 tables   │
  │ audit / KB   │      │ …10 more           │     │  0 host refs │
  └──────────────┘      └────────────────────┘     └──────────────┘
                         defaults ship for all 13
```

```bash
php artisan sire:architecture
```

fails the build if SIRE core grows a host namespace, a hardcoded guard, a
hardcoded role name or a hardcoded tenant column. It is the check that keeps
this true as the code changes.

**Six integrations are permanently optional** — audit, notes, settings,
numbering, SLA, knowledge base. SIRE owns tables and engines for all six, and
never connecting them is a supported end state.

---

## The workflow

```
REPORT → TRIAGE → ANALYSIS → DEVELOPMENT → QA → RELEASE → VERIFIED
```

with root-cause capture, recurrence detection, release gates, emergency
overrides (audited, never silent), and SLA that is computed on read — so a
missing cron costs you proactive warnings, not correctness.

---

## Report Issue

One click from any screen. **Two required fields**: a title and what happened.

SIRE already knows the module, section, screen, entity, app version,
environment, browser and the last few failed requests. A test asserts that
severity, category, priority, module, screenshot, assignee and environment can
**never** become required — every extra required field is a person deciding not
to report the bug.

[docs/REPORT-ISSUE.md](docs/REPORT-ISSUE.md)

---

## AI

**Off by default.** Thirteen capabilities, all computing locally with no vendor:
classification, duplicate detection, root-cause clustering, risk scoring, test
generation, release-note drafting and more. Every one degrades to a documented
deterministic behaviour.

When you do enable a provider, `AiContextSchema` is an **allowlist** — only
named fields can leave the application, and providers resolve through a
hardcoded allowlist rather than a class name read from a tenant setting.

[docs/AI.md](docs/AI.md)

---

## Documentation

**Start:** [JUNIOR-DEVELOPER-START-HERE](docs/JUNIOR-DEVELOPER-START-HERE.md) ·
[COMPATIBILITY](docs/COMPATIBILITY.md) ·
[INSTALLATION](docs/INSTALLATION.md) ·
[TROUBLESHOOTING](docs/TROUBLESHOOTING.md) ·
[UNINSTALL](docs/UNINSTALL.md)

**Integrate:** [ADAPTERS](docs/ADAPTERS.md) ·
[HOST-INTEGRATION](docs/HOST-INTEGRATION.md) ·
[TENANCY](docs/TENANCY.md) ·
[AUTHORIZATION](docs/AUTHORIZATION.md) ·
[FRONTEND-INTEGRATION](docs/FRONTEND-INTEGRATION.md) ·
[DISCOVERY](docs/DISCOVERY.md)

**Understand:** [ARCHITECTURE](docs/ARCHITECTURE.md) ·
[SECURITY](docs/SECURITY.md) ·
[BACKEND](docs/BACKEND.md) ·
[DATABASE](docs/DATABASE.md) ·
[WORKFLOW](docs/WORKFLOW.md) ·
[PHASE-2](docs/PHASE-2.md) ·
[API](docs/API.md) ·
[DECISIONS](docs/DECISIONS.md)

**Features:** [REPORT-ISSUE](docs/REPORT-ISSUE.md) ·
[SLA](docs/SLA.md) ·
[NOTIFICATIONS](docs/NOTIFICATIONS.md) ·
[ATTACHMENTS](docs/ATTACHMENTS.md) ·
[AUDIT](docs/AUDIT.md) ·
[AI](docs/AI.md)

**Operate:** [DEPLOYMENT](docs/DEPLOYMENT.md) ·
[ROLLBACK](docs/ROLLBACK.md) ·
[DEVELOPER-GUIDE](docs/DEVELOPER-GUIDE.md)

---

## Commands

| | |
|---|---|
| `sire:compatibility` | Will SIRE work here? Four verdicts, per area. |
| `sire:discover` | Read-only inspection. Writes one file to `storage/app`. |
| `sire:host-profile` | What discovery found, redacted and readable. |
| `sire:install` | Applies what it knows; asks about what it must not guess. |
| `sire:doctor` | What is broken, why it matters, how to fix it. |
| `sire:architecture` | Is SIRE core still CRM-agnostic? |
| `sire:uninstall` | Disables by default. Deletes only with `--purge` and a typed phrase. |

All support `--dry-run`. Discovery is read-only. Uninstall cannot touch a table
without the `sire_` prefix.

---

## Verification

```bash
node --test tests/*.test.mjs      # 407 checks, no database, no npm install
php tools/run-fixture-checks.php  # 3 reference hosts, real SIRE classes
php artisan sire:architecture     # CRM-agnosticism
```

| | |
|---|---|
| SIRE's own logic | **VERIFIED** — 407 checks |
| PHP syntax, 240 files | **VERIFIED** — lint clean |
| Not written for one host | **VERIFIED** — 3 divergent fixtures |
| Installing into a real CRM | **NOT VERIFIED** — needs a real application |
| Tenant isolation in your CRM | **NOT VERIFIED** — [check it by hand](docs/JUNIOR-DEVELOPER-START-HERE.md#11-prove-tenant-isolation--do-not-skip-this) |
