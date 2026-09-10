# SIRE API reference

<!-- GENERATED from routes/sire.php by tools/generate-api-docs.mjs.
     Do not edit the endpoint tables by hand; edit the routes and re-run.
     The narrative below comes from tools/api-narrative.md. -->

**73 endpoints**, all inside one route group:

```php
Route::middleware([])->prefix('api/sire')->group(function () {
```

> **Both middleware must stay in ONE `->middleware([...])` array.** A second
> chained `->middleware()` call replaces the first and silently drops
> `auth:sanctum`. The discovery report records that exact mistake causing a live
> cross-vendor leak in the first host application.

SIRE is for internal engineering staff. Customer-facing roles must not reach it.

## Endpoints

### Report Issue (Phase 0)

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/reports/{report}/attachments` | ReportController::indexAttachments |
| POST | `/api/sire/reports/{report}/attachments` | ReportController::storeAttachment |

### engineering workflow (Phase 1)

| Method | Endpoint | Handler |
|---|---|---|
| POST | `/api/sire/reports/{report}/transitions` | ReportWorkflowController::transition |
| POST | `/api/sire/reports/{report}/actions` | ReportWorkflowController::action |

### activity

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/reports/{report}/timeline` | SireTimelineController::__invoke |
| GET | `/api/sire/reports/{report}/comments` | SireCommentController::index |
| POST | `/api/sire/reports/{report}/comments` | SireCommentController::store |
| PATCH | `/api/sire/reports/{report}/comments/{note}` | SireCommentController::update |
| DELETE | `/api/sire/reports/{report}/comments/{note}` | SireCommentController::destroy |

### dashboard

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/dashboard` | SireDashboardController::tiles |
| GET | `/api/sire/dashboard/register` | SireDashboardController::register |
| GET | `/api/sire/dashboard/options` | SireDashboardController::options |

### work queues

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/queues/development` | SireQueueController::development |
| GET | `/api/sire/queues/qa` | SireQueueController::qa |

### root cause

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/reports/{report}/root-cause` | SireRootCauseController::show |
| POST | `/api/sire/reports/{report}/root-cause` | SireRootCauseController::store |
| POST | `/api/sire/reports/{report}/root-cause/confirm` | SireRootCauseController::confirm |

### relationships: duplicates, links, regressions

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/reports/{report}/relations` | SireRelationController::index |
| POST | `/api/sire/reports/{report}/links` | SireRelationController::storeLink |
| DELETE | `/api/sire/links/{link}` | SireRelationController::destroyLink |
| POST | `/api/sire/reports/{report}/regression` | SireRelationController::markRegression |
| DELETE | `/api/sire/reports/{report}/regression` | SireRelationController::clearRegression |

### recurrence

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/recurrence-groups` | SireRecurrenceController::index |
| POST | `/api/sire/recurrence-groups` | SireRecurrenceController::store |
| GET | `/api/sire/recurrence-groups/{group}` | SireRecurrenceController::show |
| POST | `/api/sire/recurrence-groups/{group}/occurrences` | SireRecurrenceController::addOccurrence |
| DELETE | `/api/sire/recurrence-groups/{group}/occurrences/{report}` | SireRecurrenceController::removeOccurrence |
| POST | `/api/sire/recurrence-groups/{group}/recompute` | SireRecurrenceController::recompute |

### releases

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/releases` | SireReleaseController::index |
| POST | `/api/sire/releases` | SireReleaseController::store |
| GET | `/api/sire/releases/{release}` | SireReleaseController::show |
| PATCH | `/api/sire/releases/{release}` | SireReleaseController::update |

### release notes

| Method | Endpoint | Handler |
|---|---|---|
| POST | `/api/sire/releases/{release}/notes` | SireReleaseNotesController::generate |
| GET | `/api/sire/release-notes/{note}` | SireReleaseNotesController::show |
| PATCH | `/api/sire/release-notes/{note}` | SireReleaseNotesController::update |
| POST | `/api/sire/release-notes/{note}/regenerate` | SireReleaseNotesController::regenerate |
| POST | `/api/sire/release-notes/{note}/submit` | SireReleaseNotesController::requestApproval |
| POST | `/api/sire/release-notes/{note}/approve` | SireReleaseNotesController::approve |
| POST | `/api/sire/release-notes/{note}/publish` | SireReleaseNotesController::publish |

### knowledge base linkage

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/reports/{report}/kb-links` | SireKbLinkController::index |
| POST | `/api/sire/reports/{report}/kb-links` | SireKbLinkController::store |
| POST | `/api/sire/reports/{report}/kb-article` | SireKbLinkController::createArticle |
| DELETE | `/api/sire/kb-links/{link}` | SireKbLinkController::destroy |

### CAPA

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/capa` | SireCapaController::index |
| POST | `/api/sire/reports/{report}/capa` | SireCapaController::storeForReport |
| POST | `/api/sire/recurrence-groups/{group}/capa` | SireCapaController::storeForGroup |
| POST | `/api/sire/capa/{action}/start` | SireCapaController::start |
| POST | `/api/sire/capa/{action}/complete` | SireCapaController::complete |
| POST | `/api/sire/capa/{action}/verify` | SireCapaController::verify |
| POST | `/api/sire/capa/{action}/cancel` | SireCapaController::cancel |

### quality dashboard

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/quality` | SireQualityController::__invoke |

### the release board

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/release-board` | SireReleaseGovernanceController::board |
| GET | `/api/sire/release-gates` | SireReleaseGovernanceController::gateConfig |
| GET | `/api/sire/releases/{release}/governance` | SireReleaseGovernanceController::show |
| POST | `/api/sire/releases/{release}/gates/evaluate` | SireReleaseGovernanceController::evaluate |

### governed lifecycle: approve / release / cancel / roll back

| Method | Endpoint | Handler |
|---|---|---|
| POST | `/api/sire/releases/{release}/transitions` | SireReleaseGovernanceController::transition |

### emergency override, and the register of them

| Method | Endpoint | Handler |
|---|---|---|
| POST | `/api/sire/releases/{release}/override` | SireReleaseGovernanceController::override |
| POST | `/api/sire/release-overrides/{override}/revoke` | SireReleaseGovernanceController::revokeOverride |
| GET | `/api/sire/release-overrides` | SireReleaseGovernanceController::overrides |

### suggest returns `unavailable` because the only provider declines everything.

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/ai/status` | SireAiController::status |
| GET | `/api/sire/ai/capabilities` | SireAiController::capabilities |
| POST | `/api/sire/ai/reports/{report}/suggest` | SireAiController::suggestForReport |
| GET | `/api/sire/ai/reports/{report}/suggestions` | SireAiController::forReport |
| POST | `/api/sire/ai/suggestions/{suggestion}/decide` | SireAiController::decide |

### AI: classification and duplicate detection (computed locally)

| Method | Endpoint | Handler |
|---|---|---|
| POST | `/api/sire/ai/reports/{report}/insights` | SireAiController::insights |

### test cases (CORE — works with or without any AI)

| Method | Endpoint | Handler |
|---|---|---|
| GET | `/api/sire/reports/{report}/test-cases` | SireTestCaseController::index |
| POST | `/api/sire/reports/{report}/test-cases` | SireTestCaseController::store |
| PATCH | `/api/sire/test-cases/{testCase}` | SireTestCaseController::update |
| DELETE | `/api/sire/test-cases/{testCase}` | SireTestCaseController::destroy |
| POST | `/api/sire/test-cases/{testCase}/result` | SireTestCaseController::result |
| DELETE | `/api/sire/test-cases/{testCase}/result` | SireTestCaseController::resetResult |

### AI: the remaining assistance (all computed locally)

| Method | Endpoint | Handler |
|---|---|---|
| POST | `/api/sire/ai/releases/{release}/insights` | SireAiController::releaseInsights |
| GET | `/api/sire/ai/engineering-insights` | SireAiController::engineeringInsights |

## Conventions

| | |
|---|---|
| Auth | Sanctum bearer token, like the rest of the host |
| Tenant | Server-side from the token. **Never** read from the body — requests are stripped of `tenant_id` before validation |
| Not yours | **404**, not 403 — existence hiding |
| No permission | **403**, never 401. The SPA signs out on an auth-shaped 401 |
| Errors | `SireApiResponse` — `{ message, context }`. `SireException` renders 409 with a user-safe message |


## Shapes worth knowing

**Create an issue** — `POST /api/sire/reports`

```json
{
  "title": "Saving a lead fails with a 500",
  "description": "The spinner runs and then nothing happens.",
  "submit": true,
  "context": { "module": "sales", "screen": "lead-details", "...": "" }
}
```

`tenant_id` and `reporter_id` are **never** accepted — they come from the token.
The `context` bag passes a server-side allowlist and is re-redacted on receipt.

**Move an issue** — `POST /api/sire/reports/{report}/transitions`

```json
{ "action": "mark_ready_for_qa", "fix_summary": "Added the missing null check." }
```

One endpoint for all transitions. The state machine already knows what is legal
from where; twenty near-identical endpoints would be twenty places for the rules to
drift. `status` is stripped from the payload — the machine decides.

**Record a test result** — `POST /api/sire/test-cases/{testCase}/result`

The **only** path that writes a result. One person, one test, one result. No bulk
pass, no automatic pass.
