# Tests

**Executable specifications for SIRE's critical invariants.** Deliberately not a
test suite — the UI-polish checks from development were dropped, and what remains
proves things that would be expensive to discover in production.

```bash
node --test tests/*.test.mjs      # from the package root
```

**407 checks across 28 suites. No database, no browser, no framework, no
`npm install`.** They read the source in `` and `resources/js/` directly, so
they work from this ZIP as-is.

Run them from the package root — several suites resolve paths relative to the
working directory.

## What each one protects

| Suite | Invariant |
|---|---|
| `tenant-scoping` | **Every SIRE query is tenant-scoped.** Scoping here is opt-in with no safety net; a missing `forTenant()` returns every tenant's rows with no error. Self-tested, and audits that its own model list is complete. |
| `module-boundaries` | Nothing outside the AI layer references it, core depends on nothing, and **the AI layer has no write path to core data.** Detects fully-qualified inline references, not just imports. |
| `schema-completeness` | Every altered table has a create migration. **Catches the silent failure**: guarded ALTERs no-op, so a missing create means `migrate` reports success and does nothing. |
| `workflow` · `workflow-parity` | Every state reachable, no dead ends, illegal transitions rejected — and **the PHP definition and its JS export cannot diverge**. |
| `releaseLifecycle` | Nothing transitions *to* ready or blocked (they are derived from gates); a blocked release cannot be approved. |
| `sla` | 15 decision-table cases. Caught two real bugs on first run. |
| `releaseGates` | An unknown gate key **blocks**; an empty gate list reports `ungoverned`, not all-clear. |
| `riskEngines` | One banding scale across three engines; an empty input is unevidenced, not "low risk". |
| `aiRedaction` | **What may leave the tenant.** No allowlist admits a secret, PII or identity field. |
| `duplicates` | Chain resolution, loop refusal, flattening — and that nothing is deleted. |
| `similarity` · `classification` · `quality` | The duplicate, classification and recurrence engines, to 4 decimal places. |
| `testCaseGeneration` | **A generated test never arrives with a result.** Asserted across every fixture case. |
| `resolveRouteContext` · `collector` · `redact` | Context capture across 12 modules; no tenant or user identity in the payload; credentials scrubbed. |
| `api-routes` | Every endpoint the React client calls exists in the route file. |
| `integration-adapters` | **Every adapter has a working implementation, the provider binds all twelve, and no SIRE service imports a host class.** The rule the whole integration model rests on. |
| `routes-resolve` | Every route points at a controller method that exists, no verb+path is registered twice, and all routes sit in **one** middleware group. Caught a route naming a method that had never been written. |
| `frontend-imports` | Every relative import resolves; SIRE depends on exactly nine host frontend files; no page is unrouted and no link is dangling. |
| `route-map-sync` | The generated PHP route map matches its JavaScript source — two copies of 70 routes would drift silently. |
| `api-docs-sync` · `docs-consistency` | The API reference matches the routes; the schema doc matches the migrations; the AI catalogue matches the code; no document names a class or file that does not exist. |

## Executable specifications

`fixtures/*.json` are decision tables run by **both** the JS references in
`reference/` and the PHPUnit tests in `phpunit/`. If the two implementations
disagree, the fixture says which is wrong.

That pattern caught bugs review did not: the SLA matcher never seeing severity, an
open pause discarded on a stopped clock, a risk engine announcing a finding from an
empty input.

To change behaviour: **change the fixture first, watch it fail, then change the
code.**

## PHPUnit

`phpunit/` needs the host's test harness — factories, `RefreshDatabase`, Sanctum.
Copy it into `tests/` and the fixtures into `fixtures/`.

**These have never run.** They are written against the host's testing conventions as
described in the discovery report.

## What was dropped

UI-polish checks (design tokens, responsive behaviour, saved views, query states)
and a packaging smoke check. They served development; they would only be noise in
an integration package.

## Why so many of these test the tests

Several suites assert that they are **not vacuous** — that the scan found files,
that the parser matched something, that the checker would catch a real violation
if one existed.

That is not defensiveness for its own sake. Every one of those guards was added
after a check silently stopped working: a boundary scanner anchored to column zero
that could not see an indented `use`; a layer regex matching `\d+` that could not
see the integration layer's `-1` and quietly excluded it from every comparison. A
green check that examines nothing is worse than no check, because it is trusted.
