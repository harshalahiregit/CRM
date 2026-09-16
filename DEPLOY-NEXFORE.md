# Deploying to `crm.nexforeconsulting.com`

The Nexfore test box. **This is not the same server as `app.sangoe.in`** — every path
in `DEPLOY.md` points at `/var/www/vhosts/sangoe.in/` and is wrong here. Use this file.

First set up 8 Sep 2026. Everything below was actually run, not planned.

---

## 1. The environment

| | |
|---|---|
| URL | https://crm.nexforeconsulting.com |
| Server | `srv407120.hstgr.cloud` · Hostinger VPS · `45.90.220.5` |
| OS / panel | Ubuntu 22.04.5 · Plesk Obsidian 18.0.80 |
| SSH user | `nexforeconsulting.co_bhmrselvhng` (shared with the parent domain) |
| App root | `/var/www/vhosts/nexforeconsulting.com/crm.nexforeconsulting.com/` |
| Document root | `<app root>/public` — Laravel serves the React build; **one vhost, not two** |
| PHP | `/opt/plesk/php/8.3/bin/php` — **8.3, not 8.2** (see §6) |
| Composer | `/opt/psa/var/modules/composer/composer.phar` (see §6) |
| Database | **SQLite** — `database/database.sqlite` (see §5) |
| SSL | Let's Encrypt, auto-renewing |
| DNS | **Cloudflare** — `crm` A record → `45.90.220.5`, grey cloud |
| Node | **not installed** — the frontend must be built locally |
| Disk | 387 GB, ~33% used. Not a constraint here, unlike the sangoe.in box |

Seeded logins: `admin@mlacrm.com` / `Admin@12345` (also vendor@, tpv@, client@,
and three staff accounts — see `database/seeders/DatabaseSeeder.php`).

---

## 2. Deploying an update

```bash
cd ~/Desktop/sangoe_crm/CRM
./deploy.sh
```

That is the whole deploy. It shows you a dry run, asks before writing anything,
and refuses to continue if the site is unhealthy afterwards.

**Do not deploy by typing rsync at the prompt.** That is how production was
destroyed on 10 Sep 2026: the same command had run correctly six times that day,
then `--delete-excluded` was added to it on the seventh. That flag **inverts
every `--exclude`** — the arguments protecting `.env`, `vendor/`, the database
and `storage/app/` became instructions to delete them, and all four went, along
with the database backup taken sixty seconds earlier because its filename
matched `*.sqlite*`. The site returned 500 mid-demo, and a day of data was
unrecoverable.

The script exists so those flags live in a reviewed file instead of a person's
memory. It will refuse to run if `--delete`, `--delete-excluded` or
`--remove-source-files` appears anywhere — passed in, or edited into the file.

### What it does, in order

| | |
|---|---|
| 1 | Checks the server is reachable |
| 2 | **Pulls a backup onto your machine** — database and uploads in one pass |
| 3 | Builds the frontend (no Node on the server, so it must happen locally) |
| 4 | Stamps both halves with the same commit, for `sire:doctor`'s parity check |
| 5 | **Dry run**, then waits for a yes |
| 6 | Syncs backend, then frontend — in that order, see §6 |
| 7 | composer · migrate · storage:link · caches |
| 8 | Verifies the live site, and fails loudly if it is not healthy |

### Options

```bash
./deploy.sh              # dry run, then asks
./deploy.sh --yes        # skip the prompt
./deploy.sh --no-backup  # skip the pull, only if you just took one
```

Backups land in `~/sangoe-backups/<timestamp>/`, or wherever
`SANGOE_BACKUP_DIR` points.

### Why the backup takes both halves together

An attachment is a database row **and** a file on disk, stored apart. Restore
Tuesday's rows over Friday's files and you get issues whose evidence 404s and
files no issue references. Pulling them in one pass is the only way the two
always match.

And it pulls them **off the box**. A copy left on the server shares the disk
that would lose it — which is exactly what happened to that sixty-second-old
database backup.

### Verify

```bash
curl -sS -o /dev/null -w "%{http_code}\n" https://crm.nexforeconsulting.com/          # 200
curl -sS -o /dev/null -w "%{http_code}\n" https://crm.nexforeconsulting.com/api/hr/payroll/runs  # 401
curl -sS -o /dev/null -w "%{http_code}\n" https://crm.nexforeconsulting.com/.env      # 403
```

`401` on the API is the RIGHT answer — it means routing and auth both work. A `500`
means look at `storage/logs/laravel.log`. A `404` means `route:cache` needs re-running.

**Do not** grep the main JS bundle for UI text to prove a frontend deploy landed.
Lazy-loaded routes are code-split into their own chunks, so the grep returns nothing
on a perfectly good deploy. Fetch the specific chunk, or check a file that is new in
this build returns 200.

---

## 3. `.env` never travels

It is excluded from every rsync, deliberately — it holds credentials. Anything added
locally (a new integration's keys, a mail server) **will not reach the server** and
the feature will fail with an unhelpful error. Check `.env` on both sides before
debugging anything config-shaped.

Current server `.env` deliberately keeps outbound things off, so a test box cannot
email or message real people:

```
MAIL_MAILER=log            # writes to storage/logs, sends nothing
WHATSAPP_ENABLED=false
SANGOETRACK_ENABLED=false
MEETING_PROVIDER=stub
```

### `APP_DEBUG`

Currently `true`, on purpose, so errors are readable while the box is private.

**Set it to `false` before sharing the URL with anyone.** The debug page lists the
whole environment — including `APP_KEY`, which signs session cookies. Someone with it
can forge an admin session without a password. Any request that errors renders that
page; no login needed.

```bash
sed -i 's/^APP_DEBUG=true/APP_DEBUG=false/' .env
/opt/plesk/php/8.3/bin/php artisan config:cache     # NOT optional — see §6
```

---

## 4. Scheduled tasks

**Two cron entries, not 24.** `schedule:run` reads all 24 definitions in
`routes/console.php` and decides internally what is due.

Plesk → crm.nexforeconsulting.com → Dashboard → **Scheduled Tasks** → Add Task:

| | Command | Cron |
|---|---|---|
| Scheduler | `/opt/plesk/php/8.3/bin/php /var/www/vhosts/nexforeconsulting.com/crm.nexforeconsulting.com/artisan schedule:run` | `* * * * *` |
| Queue | `/opt/plesk/php/8.3/bin/php /var/www/vhosts/nexforeconsulting.com/crm.nexforeconsulting.com/artisan queue:work --stop-when-empty --tries=3 --max-time=3600` | `*/5 * * * *` |

Set both to **"Do not notify"** — the default emails on every run, which is 1,440
messages a day from the first one alone.

`--stop-when-empty` lets the worker exit when the queue drains, so cron restarts it
and there is no daemon to supervise.

---

## 5. Why SQLite, and what that costs

**The CRM runs on SQLite, here and in production.** This box was set up on MySQL
first, and the migrations did not survive it. Three distinct classes of failure, all
of which only MySQL enforces:

1. **Index names over 64 characters.** MySQL caps identifiers; SQLite does not.
   13 migrations hit this. All are now fixed with explicit names.
2. **Dropping an index a foreign key depends on.** `tax_rates` swapped its unique
   constraint by dropping before creating; MySQL refuses. Fixed by reordering.
3. **`TIMESTAMP NOT NULL` with no default.** MySQL gives the first such column in a
   table an implicit `CURRENT_TIMESTAMP` and every later one an invalid zero-date.
   Fixed by switching `approval_delegations` to `dateTime`.

Then a fourth appeared — `add_app_login_to_hr_employees` uses
`->after('sangoetrack_synced_at')`, a column a **later** migration creates. SQLite
ignores `->after()` entirely; MySQL enforces it. That is a whole class of ordering
bug, and finding them all is a project rather than a deploy step.

**So: those 15 fixes are committed and worth keeping, but the codebase is NOT
MySQL-ready.** Do not read them as "MySQL works now". If you ever move, budget real
time and expect more of category 4.

A `crm_test` MySQL database exists in Plesk and is unused. Harmless.

### SQLite housekeeping

The database is one file. It is excluded from rsync (`--exclude='database/*.sqlite*'`)
so a deploy can never overwrite live data with your local copy.

```bash
# Back it up before anything risky
cp database/database.sqlite database/database.sqlite.$(date +%F)

# Permissions, if you ever see "readonly database"
chmod 664 database/database.sqlite && chmod 775 database
```

---

## 6. Traps this deploy actually hit

**`--delete-excluded` destroyed production.** 10 Sep 2026. The deploy rsync had
run correctly six times that day; on the seventh this flag was added at the
prompt, in the belief it meant "also clean up stale files" — that is `--delete`.
What it actually does is **delete the paths you excluded**, so every `--exclude`
guarding a server-owned file became an order to remove it:

```
--exclude='.env'                →  deleted
--exclude='vendor/'             →  deleted
--exclude='database/*.sqlite*'  →  deleted, INCLUDING the backup taken 60s earlier
--exclude='storage/app/*'       →  deleted (the only copy of the SIRE evidence)
--exclude='public/'             →  deleted (public/index.php, so the site 500'd)
```

The application code was untouched — git had all of it — and that is the point:
the only things lost were the four categories git deliberately does not track.
Recovery took ~20 minutes and cost a day of test data. The same command against
real customer data would have been unrecoverable.

Three things would each have prevented it, and none was in place:

- **a script instead of a typed command** — now `deploy.sh`, which refuses any
  delete flag
- **`rsync -n` first** — one second, and it lists every deletion before it happens
- **an off-box backup** — the one that existed was on the same server, matched an
  excluded pattern, and died in the same pass

Use `./deploy.sh`. If you ever genuinely need a delete flag, run it by hand with
`--dry-run` first and take a backup onto a different machine before you start.

**`rsync` line continuations get mangled on paste.** A multi-line command with
trailing `\` came through with the last excludes folded into the destination
argument. It did not error — it printed `sent 128,831 bytes` and moved on. **Run
rsync as a single line.** And check the `sent` figure: a real backend upload is
~20 MB, a real frontend one ~11 MB. Anything in the KB range means nothing moved.
(`sent 19 bytes` is the same failure from the sangoe.in deploys.)

**Backend rsync must run BEFORE frontend.** Backend `public/` does not carry the
built assets, so running frontend first and backend second overwrites them.

**`/usr/local/bin/composer` is a shell script, not a phar.** Running it with `php`
prints its source and exits 0, looking like a no-op success. Use
`/opt/psa/var/modules/composer/composer.phar`.

**PHP is not on the system PATH.** `php -v` as root says "command not found" even
though 8.2 and 8.3 are both installed under `/opt/plesk/php/`.

**PHP must be 8.3.** `composer.json` claims `"php": "^8.2"` but `openspout/openspout
^5.3` needs 8.3+, so `composer install` refuses on 8.2. The Plesk **web** PHP version
must match the one `vendor/` was built with, or the site 500s the moment openspout is
touched. Worth tightening `composer.json` to `^8.3`.

**A fresh database exposes migrations an existing one never re-runs.** All of §5 was
invisible until tonight because Laravel skips anything already in the `migrations`
table. Every one of those bugs had been sitting there for months.

**`config:cache` makes `.env` edits invisible.** Laravel reads the cached copy. Edit
`.env`, then always re-run `config:cache`, or the change silently does nothing.

**Multi-line pastes into a fresh SSH login get eaten by the banner.** Paste one
command at a time, or the first few silently vanish.

**Shell variables do not survive a new terminal window.** `$SRV`/`$APP` set in one
window are empty in the next, and `rsync ... $SRV:$APP/` with both empty becomes a
local copy. This file uses full paths throughout for that reason.

---

## 7. First-time setup (already done — for reference)

1. Cloudflare: `crm` A record → `45.90.220.5`, **grey cloud** (orange breaks
   Let's Encrypt HTTP-01 validation).
2. Plesk → **+ Add Domain** → Subdomain. Document root
   **`crm.nexforeconsulting.com/public`** — not `httpdocs`. Getting this wrong makes
   `.env` downloadable over the web.
3. Dashboard → **PHP** → 8.3, FPM served by Apache, `memory_limit` 512M,
   `max_execution_time` 300.
4. Dashboard → **SSL/TLS Certificates** → Let's Encrypt. Untick the www subdomain.
   Then Hosting Settings → permanent 301 HTTP→HTTPS.
5. Hosting & DNS → Hosting → **Webspace settings → SSH access** → `/bin/bash`.
   Leave the password field **blank** — it is shared with the live
   `nexforeconsulting.com` WordPress site and changing it breaks any existing FTP.
   Add an SSH key instead.
6. First deploy: §2, then `key:generate`, `migrate:fresh --force`, `db:seed --force`,
   `storage:link`.

`db:seed` partially fails on `HelpdeskSeeder` because `fake()` is a dev dependency and
we install `--no-dev`. The users and tenant are created before that point, so it is
harmless — the demo data simply is not generated.

---

## 8. Security notes

- Root's `authorized_keys` on this box carries **two other people's keys**, one
  labelled `Testing` with a placeholder email. Anything on this server — `.env`,
  the SQLite file, deploy keys — is readable by them. Worth raising with whoever
  administers the box.
- ModSecurity and fail2ban are both **on**. ModSecurity was *not* blocking API POSTs
  as of 8 Sep. If a login starts returning 403 with nothing in `laravel.log`, that is
  where to look: Dashboard → Security → **Web Application Firewall** → Detection only.
- The repo is private and should stay that way. If server-side `git clone` is ever
  wanted, use a **read-only deploy key** scoped to the one repo — not a public repo,
  and not a personal access token on a shared box.

---

## 9. SIRE (Issues & Quality)

SIRE is copy-installed at `backend/packages/sire`, so it travels with the normal
`backend/` rsync. No Composer step, no npm package, no queue worker, no Redis.

### After every deploy

```bash
php artisan migrate --force
php artisan route:clear && php artisan config:clear
php artisan sire:doctor          # read-only; says what is still wrong
```

`route:clear` and `config:clear` are **not optional**. SIRE adds 76 routes, and
its host configuration is read out of `config/sire-host.php` — a cached config
from before a deploy is why `sire:doctor` reports *"13 bound, 13 on SIRE
defaults, 0 connected to the host"* when the host provider is in fact present.

### Once per workspace

```bash
php artisan sire:seed-defaults
```

Creates the four severities, the eight report categories, and puts every admin
and internal user on the engineering rosters. **Nothing else creates them.**
Without it the `triage` transition has no severity to pick, so the Category and
Severity dropdowns are empty and no issue can leave `new`. It is idempotent and
never overwrites a roster somebody has already narrowed.

### Evidence files are not backed up by anything

Report Issue screenshots are written to:

```
storage/app/private/sire/{tenant}/Report/{id}/
```

The deploy `rsync` excludes `storage/app/*`, which is correct — it stops a deploy
flattening uploads — but it also means **nothing in this document backs them up**.
Two consequences worth stating:

- a database copied from another environment brings the attachment ROWS and not
  the files, so every thumbnail on those issues answers 404 ("Could not load");
- these files are the only copy that exists. Include the directory in whatever
  takes the SQLite backup, or accept that they are lost with the box.

Roughly `issues/month × screenshots/issue × ~40 KB` (WebP, capped at 1600px).

### The backend and the SPA must ship together

§2 deploys in two independent rsyncs — `backend/`, then `frontend/dist/` into
`public/`. Nothing forces them to happen together, and a backend-only deploy
leaves the browser running an older bundle against newer PHP.

**That failure is invisible from every angle except the missing feature.** The
API is healthy, the tables are there, the routes resolve, `sire:doctor` is
green — and a field the backend offers simply never renders, because the
JavaScript that draws it was built before the field existed. It has already
happened once: triage had no Category picker for an afternoon while
`/api/sire/dashboard/options` was returning all eight of them.

So stamp both halves with the same commit and let the doctor compare them. Add
these two lines to the deploy, each immediately before its own rsync:

```bash
# before the backend rsync
git rev-parse --short HEAD > backend/build-id.txt

# after `npm run build`, before the frontend rsync
git rev-parse --short HEAD > frontend/dist/build-id.txt
```

`sire:doctor` then reports one of three things:

| | |
|---|---|
| `PASS  Build parity — backend and SPA are both a1b2c3d` | they shipped together |
| `WARN  Build parity — backend is a1b2c3d, the SPA is 9f8e7d6` | **one rsync was skipped** |
| `INFO  Build parity — not stamped` | nobody wrote the files; the check stays quiet |

It never fails the run. A skew is something to act on, not a reason to refuse to
report everything else.
### Backing the evidence up — documentation is not a backup

Those screenshots exist in exactly one place: `storage/app/private/sire/` on the
server. The rsync excludes `storage/app/*`, which is correct — it stops a deploy
flattening uploads — but it also means nothing copies them anywhere.

**An on-box copy does not count.** §5 already backs the database up with a `cp`
next to the original; that protects against a bad migration and against nothing
else. This box runs close to full, and a disk that fills can corrupt MySQL and
take both copies with it.

So pull them off the box. From a machine that is not the server:

```bash
rsync -avz --delete \
  nexforeconsulting.co_bhmrselvhng@45.90.220.5:/var/www/vhosts/nexforeconsulting.com/crm.nexforeconsulting.com/storage/app/private/sire/ \
  ./backups/sire-evidence/
```

**Take the database in the same pass.** An attachment is a row *and* a file, and
they are stored apart. Restore a database from Tuesday over files from Friday and
you get issues whose thumbnails 404 and files no issue references — which is
exactly the state `/app/sire/cases/2` was in after a database arrived from
another environment without its uploads.

Size it before deciding how often: roughly
`issues/month × screenshots/issue × ~40 KB` (WebP, capped at 1600px). At ten
issues a week with one screenshot each that is under 20 MB a year, so frequency
is a choice about how much re-reporting is acceptable, not a storage problem.
### Two warnings that are EXPECTED here, and two commands not to run

`sire:doctor` reports these on this box and both are artefacts of how we deploy —
only `backend/` is rsynced, so `frontend/` does not exist on the server at all:

| Warning | Why it is fine |
|---|---|
| `Frontend — SIRE's SPA has not been published` | Our SPA is built from `frontend/` and shipped prebuilt into `backend/public`. The published copy would live in `backend/resources/js/sire`, which nothing on this box reads. |
| `Workflow — frontend mirror not found` | The mirror is `frontend/src/lib/sire/workflow.generated.js`, generated in development and committed. It is inside the built bundle already. |

> **Do not run `php artisan vendor:publish --tag=sire-frontend` or
> `php artisan sire:export-workflow` on the server.**
> The first writes package sources into a directory this deployment never
> compiles. The second writes to `../frontend/src/...`, which is outside the
> deployed tree — it fails, and if the path ever did exist it would overwrite
> the committed mirror with one generated from whatever code the server happens
> to be running. `sire:export-workflow` belongs in development, before a build,
> and its output is committed.

### `Queue — queue.default is 'database'` is also fine

SIRE dispatches nothing to a queue. Its notifications write the in-app row
directly and send mail inline through `TenantMailer`; neither implements
`ShouldQueue`. Nothing waits on a worker.

### Notifications send real mail

Once `HostNotificationProvider` is live, assigning an issue writes a bell
notification **and** emails the assignee through the tenant's own SMTP. Events
that earn an email: assigned, reassigned, ready-for-QA, QA-failed, reopened, and
the two SLA notices. Everything else is in-app only.
