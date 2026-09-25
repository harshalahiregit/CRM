# Deployment

Written for the deployment process the discovery report describes: **zip upload and
extract through the Plesk file manager**. No CI, no pipeline, no git checkout on
the live server.

**Nothing here has been run.** Every command is for a human to execute
deliberately.

---

## 1. Prerequisites

| | |
|---|---|
| PHP | ^8.2 — **no new extensions** |
| MySQL | the existing database |
| Composer packages | **none added** |
| npm packages | **none added** |
| Redis / Horizon | **not required, not introduced** |
| Queue worker | **not required** — SIRE dispatches nothing to a queue |
| Disk | ~1 MB schema + screenshot evidence |

### Read this before running anything

> **`php artisan migrate` runs every pending migration in the codebase, not just
> SIRE's.** The report records live repeatedly being found with migrations pending
> from earlier deploys. Running migrate for SIRE will run those too.

```bash
php artisan migrate:status | grep -i pending
```

**If anything non-SIRE is pending, stop.** Resolve it as its own change. Do not
discover it midway through a SIRE deploy.

Two more standing facts:

- **One database may serve two deployments.** A migration run from either affects
  both.
- **The server runs close to full**, and a deploy that fills the disk can corrupt
  MySQL. Check `df -h`.

### Back up first

```bash
mysqldump -u <user> -p <database> > ~/backups/pre-sire-$(date +%F).sql
df -h
```

---

## 2. Migrations

```bash
php artisan migrate --pretend      # inspect; changes nothing
php artisan migrate --force
php artisan sire:doctor            # read-only; writes nothing
```

**28 migrations, 22 tables, every one prefixed `sire_`.** SIRE creates and alters
no table it does not own — so Helpdesk, Tasks, notifications and attachments cannot
be affected. Verified by `tests/schema-completeness.test.mjs`.

All reversible, all idempotent (creates guard on `hasTable`, alters on
`hasColumn`), longest index identifier **28 characters** against MySQL's 64-cap.

> **Why `sire:doctor` matters.** Those guards are right for a database with pending
> migrations, but they make a *missing* migration silent: `migrate` reports
> success, the ALTERs no-op, and SIRE is broken with nothing in any log. The doctor
> checks every table **and the columns later migrations add** — the only way to
> tell "did not run" from "ran and did nothing".

---

## 3. Frontend build

```bash
cd frontend && npm ci
VITE_APP_VERSION="$(date +%Y.%m.%d)" npm run build
```

`VITE_APP_VERSION` is optional; without it the diagnostic field reads `unknown`.

Then once, on the server:

```bash
php artisan sire:export-workflow
```

---

## 4. Cache

Follow the existing deploy's cache steps. If none are documented, this is the
minimum — and **`route:clear` is not optional**, because SIRE adds 85 routes:

```bash
php artisan route:clear
php artisan config:clear
php artisan view:clear
php artisan cache:clear
```

Only add `route:cache` / `config:cache` if the current deploy already uses them.

---

## 5. Order

```
1  Back up; confirm free disk
2  migrate:status  →  resolve anything non-SIRE pending BEFORE going further
3  Build the frontend locally
4  Upload + extract via Plesk
5  php artisan migrate --pretend
6  php artisan migrate --force
7  php artisan sire:export-workflow
8  Cache commands
9  php artisan sire:doctor
10 Smoke tests (§7)
11 Add cron entries — LAST
```

**Code before schema, cron last.** Uploading first means the migration files exist
to run; cron last means no scheduled command fires against a half-deployed system.

---

## 6. Scheduled tasks

Both **optional and fail-safe**:

```php
Schedule::command('sire:run-schedule')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('sire:index-issues')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();
```

| Omitting | Costs |
|---|---|
| `sire:run-schedule` | SLA notifications; the overdue tile lags. Workflow unaffected. |
| `sire:index-issues` | AI duplicate detection and classification. Nothing else. |

**Verify cron actually calls `schedule:run`** — if it does not, neither fires and
nothing tells you:

```bash
crontab -l | grep schedule:run
php artisan schedule:list
```

---

## 6b. Closing issues on deploy (optional)

`sire:close-from-commits` closes an issue when the commit that fixes it **reaches
production** — not when it is written, not when it is merged. "Fixed" and "fixed
for the people who reported it" are different days, and a register that conflates
them tells everyone the backlog is cleaner than it is.

A commit claims a fix by naming the issue with a closing verb:

```
fix: guard the null customer on the invoice builder (fixes SIR-000013)
```

`see SIR-000013` or `same root cause as SIR-000014` close nothing. People
reference issue numbers constantly while discussing them, and a register that
closes on a mention is worse than one that closes on nothing.

### Where it has to run

The deploy rsyncs with `--exclude='.git/'`, so **production has no git history**
and can never work this out for itself. Run it from the machine doing the
deploying, against the live database, using the commit already on the server as
the starting point:

```bash
PREVIOUS="$(ssh "$SSH_TARGET" "cat $REMOTE/build-id.txt")"

php artisan sire:close-from-commits   --since="$PREVIOUS" --tenant=1 --user=<id> --apply
```

Without `--apply` it prints what it would close and changes nothing. Without
`--tenant` and `--user` it refuses to run at all: closing an issue is an act and
the audit trail has to name who performed it, so there is no default actor to
fall back on.

Every issue still runs the same capability check, the same guard and the same
required fields as the button, in its own transaction, audited on its own.

### The manual alternative

Developers who would rather not rely on commit discipline can tick the boxes in
an exported brief and upload the file on the issue register — same result, same
guards, and it previews before it closes anything. See `docs/EXPORT.md`.

## 7. Smoke tests

Stop at the first failure.

**The CRM is unharmed** — log in; open a Helpdesk ticket and reply; change a task
status; trigger a notification; upload an attachment. These are the modules SIRE
shares engines with.

**SIRE loads**

| Check | Expected |
|---|---|
| `curl -o /dev/null -w '%{http_code}' <host>/api/sire/dashboard` | **401** |
| Same as a `client` user | **403**, not 200 |
| Open `/app/sire` | The register renders |
| `php artisan sire:doctor` | All checks pass |

**Report Issue, end to end**

```
Click Report Issue → Module / Section / Screen / Record shown
→ two fields → Submit → a SIRE number comes back
```

Then confirm the issue carries the right module and route, and that reporter and
tenant were stamped from the **token**, not the payload.

**Tenant isolation** — as tenant A:

```
GET /api/sire/reports/{a tenant B id}   → 404, not 200
GET /api/sire/dashboard                 → counts exclude tenant B
```

```sql
SELECT COUNT(*) FROM sire_reports WHERE tenant_id IS NULL;   -- must be 0
```

---

## 8. Environment

| Variable | Required | Notes |
|---|---|---|
| `VITE_APP_VERSION` | No | Build-time only |

**No other environment variable is introduced.** No API key, no service URL, no
secret — there is no AI vendor and no external service.

Every SIRE setting lives in `<HOST_SETTINGS_SERVICE>` with a safe default. **Nothing
needs configuring for SIRE to work.** `sire.ai.enabled` defaults to `false`.

### Storage

The existing private `attachments` disk. No new disk. Estimate screenshot volume
before enabling widely:

```
issues/month × screenshots/issue × ~150 KB
```

---

## Not done here

No migration has been run, no file uploaded, no cron entry added, and no production
system touched.
