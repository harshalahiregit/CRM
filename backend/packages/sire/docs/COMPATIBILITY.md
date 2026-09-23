# Compatibility

**Designed for compatible Laravel/PHP CRM systems.** Not "works with every CRM"
— that claim would be false, and you would discover it at the worst moment.

Run this first:

```bash
php artisan sire:compatibility
```

## Four verdicts

| | Meaning |
|---|---|
| **SUPPORTED** | Works. Covered by the reference fixtures or the test suite. |
| **CONDITIONAL** | Works, with a caveat that is stated rather than implied. |
| **MANUAL** | Works once you write one small adapter. **Not a failure.** |
| **UNSUPPORTED** | Will not work. Said plainly, with the reason. |

Only **UNSUPPORTED** blocks installation, and it is reserved for things that
genuinely cannot work — a PHP below SIRE's floor, an unreachable database.

**MANUAL is the normal outcome for at least one row.** A CRM resolving tenancy
from a subdomain, or keeping users in an identity service, is not incompatible;
it is one where SIRE needs twenty lines of adapter.

---

## Platform

| | Verdict | Note |
|---|---|---|
| **PHP 8.2** | SUPPORTED | Floor. Tested. |
| **PHP 8.3** | SUPPORTED | Tested. |
| **PHP 8.4** | SUPPORTED | Tested — this package was linted on 8.3.x/8.4.x. |
| PHP 8.1 and below | **UNSUPPORTED** | SIRE uses readonly promoted properties and DNF types. |
| **Laravel 10** | SUPPORTED | Floor. |
| **Laravel 11** | SUPPORTED | |
| **Laravel 12** | SUPPORTED | |
| Laravel 9 and below | **UNSUPPORTED** | The provider and migrations use Laravel 10 APIs. |
| Laravel 13+ | CONDITIONAL | Untested. Likely fine; run the suite. |

**Why these floors.** They were read off the code, not chosen for tidiness. PHP
8.2 is the first version accepting every construct SIRE's DTOs and `match`
expressions use; Laravel 10 is where the migration and provider APIs SIRE calls
settled.

## Database

| | Verdict | Note |
|---|---|---|
| **MySQL 8.0+** | SUPPORTED | Primary target. |
| **MariaDB 10.6+** | SUPPORTED | Index identifiers stay under 64 chars; longest is 28. |
| MySQL 5.7 | CONDITIONAL | Works, but `json` columns are weaker. Test the AI tables. |
| SQLite | CONDITIONAL | Fine for development and the test suite. Not for production. |
| PostgreSQL | CONDITIONAL | Migrations avoid MySQL-only syntax, but no test coverage. |
| SQL Server | **MANUAL** | Untested. Review all 23 migrations first. |

## Authentication

| | Verdict | Set `auth_middleware` to |
|---|---|---|
| **Sanctum** | SUPPORTED | `['auth:sanctum']` |
| **Session / web** | SUPPORTED | `['auth:web']` |
| **Passport** | SUPPORTED | `['auth:api']` |
| **JWT** (tymon) | SUPPORTED | `['jwt.auth']` |
| Custom guard | SUPPORTED | `['auth:your-guard']` |
| Custom middleware | SUPPORTED | whatever you name |

SIRE hardcodes none of these. It composes the stack from config, and **fails
closed**: with `auth_middleware` empty, every SIRE route returns 503 rather than
serving an engineering backlog to anonymous traffic.

## Tenancy

| | Verdict | Strategy |
|---|---|---|
| **Column on the users table** | SUPPORTED | `user_attribute` |
| **Relationship to a tenant model** | SUPPORTED | `relationship` |
| **A resolver service** | SUPPORTED | `resolver` |
| **Subdomain / header / middleware** | SUPPORTED | `callable` |
| **Not multi-tenant at all** | SUPPORTED | `single_tenant` |
| `stancl/tenancy` | **MANUAL** | Point `callable` at its resolver. |
| `spatie/laravel-multitenancy` | **MANUAL** | Same. |

Any column name works — `tenant_id`, `organization_id`, `company_id`,
`workspace_id`, `account_id`. It is a config value.

## Roles and permissions

| | Verdict | Note |
|---|---|---|
| **A role column** | SUPPORTED | Any column name. |
| **A roles table** | SUPPORTED | Any table name. |
| **spatie/laravel-permission** | SUPPORTED | Map its abilities, or use coarse grants. |
| **Laravel Gate** | SUPPORTED | Define abilities named as SIRE capabilities. |
| Laratrust and similar | CONDITIONAL | Works through Gate or the rosters. |
| **No permission system** | SUPPORTED | SIRE's own rosters cover it. |

You map **your** role names to four categories. SIRE ships none.

## Frontend

| | Verdict | What you get |
|---|---|---|
| **React + Vite** | SUPPORTED | Full experience. |
| **React + Inertia** | SUPPORTED | Full experience. |
| **React + Mix** | CONDITIONAL | Works; Vite is what was tested. |
| Vue / Inertia-Vue | CONDITIONAL | Blade widget, or call the API yourself. |
| Livewire | CONDITIONAL | Blade widget. |
| Blade only | CONDITIONAL | Blade widget. Full API. |
| **No frontend** | SUPPORTED | The API is the product. |

SIRE requires **no UI library**. It ships accessible fallbacks for the six
components it renders, and uses yours when you register them.

## Optional integrations

All six are permanently optional. SIRE owns tables and engines for every one.

| | If you have it | If you do not |
|---|---|---|
| Audit | Write `SireAuditProvider` | `sire_audit_events` |
| Notes | Write `SireNotesProvider` | `sire_notes` |
| Settings | Write `SireSettingsProvider` | `sire_settings` |
| Numbering | Write `SireNumberingProvider` | `SIR-000412` |
| Attachments | Write `SireAttachmentProvider` | A Laravel disk |
| Knowledge base | Write `SireKnowledgeProvider` | Links only, no search |

## Infrastructure SIRE does **not** need

Redis · queue workers · Horizon · a vector database · an AI vendor · Elasticsearch
· a search service · websockets · any npm package · any Composer package beyond
Illuminate.

Cron is optional: without it SLA state is still computed on read, and only
proactive warning/breach notices are lost.

---

## Known unsupported

| | Why |
|---|---|
| Non-Laravel PHP (Symfony, CodeIgniter, bespoke) | The provider, migrations and routing are Laravel's. The **API** is reachable from anything. |
| Lumen | No `vendor:publish`, no scheduler, partial container. |
| PHP 8.1 and below | Language features SIRE uses. |
| Laravel 9 and below | Framework APIs SIRE calls. |
| Multi-database tenancy (a database per tenant) | SIRE assumes one connection with `tenant_id`. A `callable` strategy plus a connection-switching middleware may work; untested. |

---

## What "verified" means here

| | Status |
|---|---|
| SIRE's own logic | **VERIFIED** — 407 automated checks |
| PHP syntax, all files | **VERIFIED** — 274 files lint clean |
| Role classification across 3 host shapes | **VERIFIED** — reference fixtures |
| Architecture is CRM-agnostic | **VERIFIED** — `sire:architecture` |
| Installing into a real CRM | **NOT VERIFIED** — needs a real application |
| Tenant isolation in your CRM | **NOT VERIFIED** — [check it by hand](JUNIOR-DEVELOPER-START-HERE.md#11-prove-tenant-isolation--do-not-skip-this) |

The fixtures prove the logic is not written for one shape. They do not prove
SIRE runs inside your CRM, and nobody should read a green suite as if they did.
