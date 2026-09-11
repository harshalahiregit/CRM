# These docs describe the package. The code beside them is what runs.

Moved here from the original `SIRE-v1.1-PLUG-AND-PLAY/` distributable when that
folder was deleted. The architecture, the tenancy strategies, the thirteen SDK
seams, the compatibility matrix and the deployment rules are unchanged.

**What changed is the code.** Installing SIRE into this CRM was the first time it
had ever been installed into a real host, and a real host finds things that 407
self-tests against three fake fixtures cannot.

## One structural difference

SIRE shipped to be copied file-by-file into `app/`, under `App\Services\Sire\…`.
It is installed here as a **package** at `backend/packages/sire/`, everything
under the `Sire\` namespace. Read `App\Services\Sire\Foo` in these pages as
`Sire\Services\Foo`; `BACKEND.md` has the full map.

## Found by installing it

- `ReportController` had no `show()`, and `routes/sire.php` registered neither
  `POST reports` nor `GET reports/{report}` — Report Issue posted to a 404.
- `store()` called `SireWorkflowService::submit()`, which does not exist, so
  every report filed from the button returned a 500.
- The attachment provider read `$owner->tenantId` on a model whose attribute is
  `tenant_id`, so every upload landed in `sire/0/`.
- There was no route to download an attachment at all.
- `SireDoctor` used uppercase statuses in one check, which fataled the doctor.

## Found by restoring the test suite

`tests/`, `tools/`, `workflow/`, `fixtures/` and `ai/` were left behind when the
package was copied in, so the checks the docs cite as evidence could not run. They
are back, they run from this directory, and they found real defects:

- `SireQualityMetricsService::rate()` compared `floor($percent) === $percent`.
  `floor()` returns a float and `$percent` is an int whenever the division is
  exact, so **every whole percentage rendered as `100.0%`**.
- `SireSlaService` took `diffInMinutes()` at face value. Carbon 3 returns a float
  where Carbon 2 returned an int, so a paused clock reported `30.0` minutes.
- `SireTextAnalyzer::tokenize()` returned `array_keys()` of a set, and PHP casts a
  numeric key to int — a token of `"500"` came back as `int(500)` and stopped
  matching the strings the rest of the engine compares against.
- `SireDuplicateService::resolveTargetFor()` was **dead code**. Nothing called it,
  so an issue could be marked a duplicate of itself, loops were accepted, and
  chains were never flattened — all three of PHASE-2's structural guarantees.
- `sire_releases.status` defaulted to `'planned'`, which is not in
  `SireReleaseStatus::ALL`. No governed transition names it as a `from`, so a
  release created through `POST /sire/releases` could never be approved, shipped
  or cancelled. Migration `000026` repairs the rows and the default.
- `StoreRootCauseRequest` and `StoreCapaRequest` validated before checking
  ownership, so another tenant's id answered 422 where every other route answers
  404.

## Found by reading the docs against the code

- `recordFailedRequest()` existed, the collector read it and two panels rendered
  it, but **nothing ever called it** — the "recent failed requests" on every
  captured context was permanently empty. Now wired into the axios error branch.
- `SireContextProvider::resolve()` was never called from anywhere.
  `SireContextService::capture()` wrote the client's module, section and screen
  straight onto the row, so "the server is the authority" in REPORT-ISSUE.md was
  simply untrue. The server re-resolves the submitted path now, and keeps the
  client's answer only for a declared context or a hand-typed correction, which
  it has no way to reproduce.
- The route map shipped 70 seeded patterns covering a fictional host. Regenerated
  from `frontend/src/app/routes.jsx`: **359 patterns across 27 modules**.
- `/ai/capabilities` returned `implemented: []` and *"Phase 3 is foundation
  only"*. That had been false since `SireLocalInsights` shipped. It reads
  `AiCapability::IMPLEMENTED_LOCALLY` now.
- `severity_recommendation` and `priority_recommendation` were catalogue entries
  with a context schema and no engine, so "all 13 are implemented" was wrong for
  two of them. Both are implemented, each behind its own capability flag.

## Added, because the package did not have them

- **Build parity** and a **production warning when no seam is host-connected**,
  both in `sire:doctor`.
- `sire:seed-defaults`.
- Nine model factories, so the shipped PHPUnit tests can run.

## Where to read what

Read these pages for intent and design. Read `../src` for behaviour. Where they
disagree, the code is right — and `tests/docs-consistency.test.mjs` now fails the
build when a headline count in here stops matching the code.
