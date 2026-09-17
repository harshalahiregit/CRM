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
minimum — and **`route:clear` is not optional**, because SIRE adds 84 routes:

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
