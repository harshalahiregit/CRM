# Authorization

## SIRE adds no RBAC system

Per the discovery report that host had no `roles` or `permissions` tables, no
`app/Policies`, zero `Gate::` usage, and no Spatie package. Authorization is a
`role` string column plus one middleware, `EnsureUserHasRole` (alias `role:`).

The `PERMISSION_MODULES` matrix in the staff admin UI **looks** like a permission
system but is a frontend mock — nothing persists or reads it.

SIRE does not become a fourth ad-hoc scheme. Instead:

**Every authorization decision funnels through one class,
`Services\Sire\SireAccessService`.**

Capabilities are named in the `module.action` shape the existing mock already
uses, so if the team later builds a real permission layer it can drive these names
and **only this file changes**.

## Route gating

```php
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('sire')->group(...)
```

> Both middleware in **one array**. A second chained `->middleware()` call replaces
> the first and silently drops `auth:sanctum` — the report records that exact
> mistake causing a live cross-vendor leak.

`client`, `third_party_vendor` and `company` roles have no SIRE access at all.

## The vocabulary — 22 capabilities SIRE owns

SIRE never asks `$user->hasRole('your-qa-lead')`. It asks
`can($user, 'sire.qa.execute')`, and the host decides which of its roles grants
that. The full list is `App\Support\Sire\Sdk\SireCapability::CANONICAL`, and
`tests/capabilities.test.mjs` fails the build if anything enforced is missing
from it.

| Capability | Held by (SIRE default) |
|---|---|
| `sire.report.create`, `sire.report.view_own` | everyone with SIRE access |
| `sire.report.view_global` | admin, leads, developers, QA |
| `sire.report.triage`, `sire.report.assign` | admin, leads |
| `sire.report.develop` | the assignee, developers, leads |
| `sire.report.close`, `sire.report.reopen` | admin, leads |
| `sire.qa.execute` | the QA assignee, QA roster, leads |
| `sire.change.review`, `sire.change.approve` | admin, leads |
| `sire.rca.confirm` | admin, leads |
| `sire.capa.verify` | admin, leads — and never the person who completed the action |
| `sire.kb.author` | admin, leads |
| `sire.release.manage` | admin, leads |
| `sire.release.approve` | admin, leads |
| `sire.release_notes.approve`, `sire.release_notes.publish` | admin, leads |
| `sire.release.override` | **admin, or the `release_managers` roster. Not leads.** |
| `sire.comment.moderate` | admin, leads |
| `sire.masters.manage` | admin |
| `sire.export` | anyone who can view |

### Nine were silently admin-only

Worth recording, because the failure mode is instructive. Nine of these
capabilities were enforced but never declared, and `can()` ends in
`default => false`. An undeclared capability is therefore not an error — it is a
permanent, silent denial that looks exactly like a deliberate one. The buttons
simply did not appear, which reads as "you are not a lead".

Change review, change approval, release approval, root-cause confirmation, CAPA
verification, KB authoring, both release-note capabilities and emergency
override were all affected: Phase 2 and release governance were effectively
unusable by anyone but an administrator. `tests/capabilities.test.mjs` now scans
every SIRE file for enforcement and fails the build on any capability missing
from the vocabulary.

## Coarse grants, for blunter role systems

Nine broader names, each defined in terms of the canonical list — so they cannot
drift apart, and you may mix the two freely.

| Coarse grant | Expands to |
|---|---|
| `sire.view` | viewing and export |
| `sire.create` | filing issues |
| `sire.update` | development and reopening |
| `sire.assign` | triage and assignment |
| `sire.qa` | executing QA, global visibility |
| `sire.approve` | change review and approval, RCA confirmation, CAPA verification |
| `sire.release` | release management, approval, release notes |
| `sire.settings` | masters, KB authoring |
| `sire.manage` | **everything** |

Mapping `sire.manage` to your administrator role is a complete, legitimate
integration. Most hosts start there and refine later, if ever.

**`sire.release` deliberately excludes `sire.release.override`.** Only
`sire.manage` grants it. Granting "this person handles releases" must not
silently grant "this person may bypass the gates" — that is how an emergency
override stops being an emergency.

## Who answers the question

`SireAuthorizationProvider` — see [HOST-INTEGRATION.md](HOST-INTEGRATION.md)
section 3. It answers two narrow questions: has the host granted this capability,
and who is in this role.

It does **not** decide whether a developer may push their own issue to QA. That
is a product rule and lives in `SireAccessService`, which combines the provider's
answers with the state of the report. Keeping that line sharp matters: a provider
that started making product decisions would create two authorization systems that
can disagree, and the one behind the integration seam is the one nobody reads.

Returning false for everything is a valid working state — SIRE falls through to
its own role rosters (`sire.roles.leads` / `.developers` / `.qa` /
`.release_managers`) and stays fully usable while you map permissions.

Three separations are structural, not conventional:

- **Approving a release ≠ overriding its gates.** Different capabilities.
- **Completing a CAPA ≠ verifying it.** The service refuses the same person.
- **Permission failure returns 403, never 401.** The SPA signs out on an
  auth-shaped 401, and the report notes an existing test guarding that distinction.

## Mapping into your application

Open `SireAccessService::can()`. It maps `role` and `internal_role` to the
capability list above.

| Your application has | Do this |
|---|---|
| Only `role` / `internal_role` (as reported) | Tune the map to your role names. Nothing else changes. |
| A real permission system | Replace `can()`'s body with a call into it. The capability names already match the `module.action` shape. |
| Per-tenant role configuration | Read the map through `SireSettingsProvider` inside `can()`. |

The provisional map is **conservative**: anything a staff member cannot do, an
admin can. Tune it with the module owner before rollout — it is a judgement about
your team, not a technical constraint.
