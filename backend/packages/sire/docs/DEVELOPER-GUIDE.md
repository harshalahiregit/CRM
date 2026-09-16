# What is verified, and what is assumed

The honest accounting. Read it before trusting any statement in this package.

## How SIRE was built

From a **35-question discovery report** describing the CRM — not from the host
source. The repository was never available. Every claim about how the CRM works is
a quotation from that report, and every one of them is an assumption until you
check it.

---

## Verified — actually executed

These ran on real tooling and passed.

| What | How | Result |
|---|---|---|
| PHP syntax | `php -l` on every PHP file in the package | **210 / 210 clean** |
| SIRE logic | `node --test tests/*.test.mjs` | **407 / 407 pass**, 28 suites |
| Class autoloading | class↔filename, namespace↔directory, no duplicate methods | clean |
| Imports resolve | every SIRE-namespace PHP import, every relative JS import | clean |
| Tenant scoping | static scan of every SIRE query for `->forTenant()` | **zero unscoped** |
| Module layering | nothing outside the AI layer references it; AI has no write path to core | clean |
| Schema completeness | every altered table has a create; every model's table exists | clean |
| Migration hygiene | all reversible, all guarded, longest index **28 chars** (cap 64) | clean |
| No foreign tables | SIRE creates and alters **no table it does not own** | clean |
| No secrets | scan for keys, tokens, credentials across all sources | clean |
| Environment | exactly one variable, `VITE_APP_VERSION`, optional | clean |
| Workflow parity | PHP definition parsed and compared to the JS export | consistent |
| SDK completeness | 13 contracts implemented, bound and stubbed; no host class imported; no host model in a contract | clean |
| Route integrity | every route resolves to an existing controller method; one middleware group | clean |
| Doc/code agreement | API reference, schema doc, AI catalogue and every documented class checked against the source | clean |

The executable specifications in `tests/fixtures/` are run by **both** the JS
reference implementations and the PHPUnit tests, so SLA, similarity,
classification, risk and redaction behave identically in both languages — or the
build fails.

---

## Assumed — never verified

Everything requiring a running application. No composer, vendor, artisan, MySQL,
browser or host repository was available.

### What used to be here, and why it is not

Earlier drafts of this document listed eleven assumed host signatures —
`NotificationEngine::dispatch()`, `AttachmentService::store()`,
`Auditable::recordAudit()` and so on — each a guess at a method in a codebase
nobody could open.

**Those assumptions were removed rather than documented better.** SIRE defines
fourteen contracts and ships an implementation of each. There is no signature to
guess, because SIRE owns every signature it calls.

The list then shrank again. `App\Models\User` was the last host MODEL in SIRE's
signatures — 188 references across 46 files — and it is now `SireUserIdentity`,
four readonly fields SIRE owns. Settings, notes and audit became SIRE tables, so
a host missing any of them is no longer a prerequisite failure.

### Blocking if wrong

| Assumption | Where it bites |
|---|---|
| The tenant provider returns the right tenant | **Silently returns another tenant's data.** The only assumption of this kind left, which is why [INSTALLATION](INSTALLATION.md) step 20 is a manual two-tenant check |
| `App\Http\Controllers\Controller` exists to extend | All 17 controllers |
| `SireServiceProvider` is registered | **Zero SIRE routes register** |

Three, and two of them fail loudly on the first request.

### Degrades one feature

| Assumption | What is lost |
|---|---|
| Nine host frontend files exist (six UI components, `useToast`, `AuthContext`, `lib/api`) | Components render wrong or not at all — enumerated and build-enforced by `tests/frontend-imports.test.mjs` |
| `config('sire.tables.users')` names a readable users table | Names on timelines fall back to snapshots; assignment pickers empty. Implement `SireUserProvider` instead |
| Tailwind palette shades | Chips may not match surrounding UI |

Everything else that used to sit in this table is now an SDK contract with a
shipped implementation, so being wrong about it means "SIRE runs on its own
instead of yours" rather than "SIRE breaks".

### Environment

| Assumption | Check by |
|---|---|
| Cron actually calls `schedule:run` | `crontab -l \| grep schedule:run` |
| MySQL version and `sql_mode` | Read off the server |
| Migrations run cleanly on MySQL | `php artisan migrate --pretend` |
| Free disk for screenshot evidence | `df -h` before enabling |
| Model factories exist for PHPUnit | Run the suite |

---

## Two defects that were only found by building a check for them

Worth knowing, because the same shape may exist elsewhere.

**Missing base migrations.** Numbering started at `000007`; `sire_reports` itself
had no create migration. Every ALTER is guarded with `hasTable()` — correct for a
database with pending migrations — so `migrate` would have reported **success**
while creating nothing. Found by writing `tests/schema-completeness.test.mjs`.

**Missing `SireReportService`.** Two controllers injected it; it did not exist.
Every request creating or reading an issue would have died with a class-not-found
fatal. Found by adding an import-resolution check once PHP became available.

Both were specified in design documents and never written. **If a third exists,
expect it at a CRM boundary** — which is precisely where this package cannot look.

---

## What to do with this

1. Work `HOST-INTEGRATION.md` **Tier 1 first**. Those six either block or
   fail silently.
2. Run `php artisan sire:doctor` after migrating. It is built to catch the silent
   failure mode described above.
3. Treat every `<PLACEHOLDER>` as an open question, not a filename.
