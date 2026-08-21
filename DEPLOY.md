# Deploying to live (app.sangoe.in)

Everything in this file is **verified against the actual server**, not assumed.
`DEPLOYMENT.md` is the generic Laravel/queue reference and describes a
`/var/www/crm` layout that **does not exist here** — trust this file for
anything to do with shipping to live.

Keep it updated: every time a deploy teaches us something (a new trap, a path
that moved, a step that failed), add it here in the same sitting.

---

## 1. The live server, as it actually is

| Thing | Value |
|---|---|
| Host | `srv698171`, Ubuntu 24.04, **Plesk** |
| SSH user | `sangoe.in_ofmk2nob6dm` |
| Home | `/var/www/vhosts/sangoe.in` |
| Laravel app root | `/var/www/vhosts/sangoe.in/app.sangoe.in/backend` |
| **Document root** | `<app root>/public` — the React build is served **by Laravel**, not a separate vhost |
| Deploy folder | `/var/www/vhosts/sangoe.in/app.sangoe.in/deploy-5585379/` |
| PHP (domain) | 8.4 — `deploy.sh` auto-picks `/opt/plesk/php/8.4/bin/php` |
| DB creds | in `<app root>/.env` — never typed on the command line |
| `sudo` | available |
| `supervisorctl` | **NOT installed** — there is no supervised queue worker |
| Git on server | **none for this site.** Only `newsangoecrm.sangoe.in` is a checkout. Deploys are **zip upload + extract**, never `git pull` |

`app.sangoe.in` and `newsangoecrm.sangoe.in` are **two separate deployments of
the same codebase**. Check which one you are touching before every command.

---

## 2. How a deploy works here

Three zips, extracted in order. `deploy.sh` (already on the server, reusable and
parameterised) does all of it.

| Zip | Extracts into | Why |
|---|---|---|
| `1-backend-app.zip` | app root | changed PHP files + migrations |
| `2-frontend-assets.zip` | `public/` | content-hashed assets — inert until referenced |
| `3-frontend-flip.zip` | `public/` | `index.html`, `sw.js`, `registerSW.js` — **the cutover**, goes last |

The split exists so a browser never loads an `index.html` pointing at chunks
that aren't on disk yet. Never reorder them.

`deploy.sh` backs up every file it is about to overwrite into
`<app root>/_deploy_backup/<timestamp>/`, so rollback is one `cp -a`.

---

## 3. Build the package (on your machine)

`build-deploy-package.ps1` on the server is **PowerShell** and useless on Linux.
Use this instead. `BASE` is the SHA currently live — see the log at the bottom.

```bash
set -euo pipefail
cd ~/Desktop/sangoe_crm/CRM

BASE=<last-deployed-sha>          # from the deploy log below
SHA=$(git rev-parse --short HEAD)
OUT=~/Desktop/sangoe_crm/deploy-$SHA
rm -rf "$OUT"; mkdir -p "$OUT"

# frontend must be built from the code you are shipping
(cd frontend && npm run build)

# 1. backend — only what changed; app/ before routes/ so controllers land first
cd backend
git diff --name-only --diff-filter=ACMR $BASE HEAD -- . | sed 's#^backend/##' > /tmp/be.lst
grep -E  '^(app|config|database)/' /tmp/be.lst  > /tmp/be.ord || true
grep -vE '^(app|config|database)/' /tmp/be.lst >> /tmp/be.ord || true
zip -q -X "$OUT/1-backend-app.zip" -@ < /tmp/be.ord
cd ..

# 2. assets (everything except the flip files)
cd frontend/dist
find . -type f ! -name index.html ! -name sw.js ! -name registerSW.js -printf '%P\n' \
  | zip -q -X "$OUT/2-frontend-assets.zip" -@

# 3. the flip
zip -q -X "$OUT/3-frontend-flip.zip" index.html sw.js registerSW.js
cd ../..

{ echo "DEPLOY PACKAGE"; echo "ref  : master ($SHA)"; echo "base : $BASE"; } > "$OUT/MANIFEST.txt"
ls -lh "$OUT"
```

**Always check for deletions before shipping** — `deploy.sh` never deletes, so a
removed file would linger on live and keep being loaded:

```bash
git diff --name-only --diff-filter=D  $BASE HEAD -- backend/
git diff --name-status --diff-filter=R $BASE HEAD -- backend/
```

Anything listed must be deleted by hand on the server after extracting.

---

## 4. Pre-flight (do not skip)

```bash
# a) disk — this box runs close to full; a deploy that fills it can corrupt MySQL
df -h /                       # want >5G free BEFORE starting

# b) how far behind is live
cd ~/app.sangoe.in/backend && php artisan migrate:status | grep -c Pending

# c) are any pending migrations destructive?
php artisan migrate --pretend | grep -iE "drop|modify|change|rename"
```

Live has repeatedly been found with **migrations pending from earlier deploys** —
someone shipped code without running `migrate`. Always check the count and know
what you are about to run, because your deploy will run *those* too.

---

## 5. Deploy

```bash
# from your machine
scp -r ~/Desktop/sangoe_crm/deploy-<sha> \
  sangoe.in_ofmk2nob6dm@app.sangoe.in:~/app.sangoe.in/

# on the server
cd ~/app.sangoe.in/deploy-<sha>
cp ~/app.sangoe.in/deploy-5585379/deploy.sh .        # reuse the existing script

# BACK UP FIRST
cd ~/app.sangoe.in/backend
DB=$(grep -m1 '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"'"'"' ')
DU=$(grep -m1 '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"'"'"' ')
DP=$(grep -m1 '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"'"'"' ')
MYSQL_PWD="$DP" mysqldump --single-transaction --quick --routines -u"$DU" "$DB" \
  | gzip > ~/backup-$(date +%F-%H%M).sql.gz
ls -lh ~/backup-*.sql.gz

# GO — the APP override is what points it at app.sangoe.in
cd ~/app.sangoe.in/deploy-<sha>
APP=/var/www/vhosts/sangoe.in/app.sangoe.in/backend bash deploy.sh
```

Without `APP=`, `deploy.sh` defaults to **`newsangoecrm.sangoe.in`** and you will
deploy to the wrong site. It aborts if the path has no `artisan`, and prints the
resolved path in its banner — read that banner.

---

## 6. Verify

```bash
cd ~/app.sangoe.in/backend
php artisan migrate:status | grep -c Pending        # want 0
tail -50 storage/logs/laravel.log                   # want no new traces
```

```bash
curl -s -o /dev/null -w "site %{http_code}\n" https://app.sangoe.in/
curl -s -o /dev/null -w "api  %{http_code}  (401 = good, 404 = stale route cache)\n" \
  https://app.sangoe.in/api/customers
```

Then in a browser, **hard-refresh twice** (`Ctrl+Shift+R`). The service worker is
`autoUpdate`, so the first reload registers the new build and the second serves
it. A single refresh showing old UI is not a failed deploy.

---

## 7. Rollback

`deploy.sh` prints the exact commands when it finishes. They are:

```bash
BAK=~/app.sangoe.in/backend/_deploy_backup/<timestamp>
cp -a $BAK/backend/. ~/app.sangoe.in/backend/
cp -a $BAK/public/.  ~/app.sangoe.in/backend/public/
cd ~/app.sangoe.in/backend && php artisan route:clear && php artisan route:cache
```

**Leave new tables alone.** Every migration we have shipped is additive, and old
code simply ignores tables it does not know about. There is no DB rollback and
no data loss either way.

Full restore fallback: Plesk → Websites & Domains → Backup & Restore.

---

## 8. Traps this project has actually hit

Each of these cost real time. Read before deploying.

**`config:cache` must NOT be run.** This codebase calls `env()` outside
`config/` (`FRONTEND_URL` in `ContractController`, `ProposalController`;
`TicketSummaryService`). Caching config stops `.env` loading, those fall back to
defaults, and portal links start pointing at `http://localhost:5173`.
`deploy.sh` deliberately runs `route:cache` but **not** `config:cache`.

**`route:cache` is the API cutover.** New endpoints 404 until it runs. A 404 on a
brand-new route after deploy almost always means a stale route cache.

**A restrictive `umask` causes a site-wide 403.** Files extracted with `umask
077` are unreadable by the web server. Always `umask 022` before extracting or
chmod-ing. This took down the whole site once.

**Disk fills up.** The box has sat at 98% of 96G. Biggest reclaimables:
`httpdocs_backup_20260710.tar.gz` (2.1G), `~/.trash` (217M), stale
`backend_backup_*` folders under `newsangoecrm.sangoe.in`. Keep the newest
`app.sangoe.in_backup_*` — that is a rollback point.

**fail2ban locks you out, not the deploy.** If the site is unreachable *for you*
but fine for colleagues, it is your IP:
```bash
curl -s ifconfig.me                                   # your CURRENT ip
sudo fail2ban-client set sshd unbanip $(curl -s ifconfig.me)
```
Unban the IP you are actually on, not one you unbanned previously.

**Migrations get left pending.** Code has been shipped without `migrate` more
than once, so live can be several releases behind on schema while the UI looks
fine — until a query hits a missing column. Always check
`migrate:status | grep -c Pending` before AND after.

**A column your code needs may be in someone else's pending migration.**
Example: `CustomerLinkedRecordsController::meetings()` selects
`kickoff_meetings.mom_status`, added by `2026_10_13_000005`, which sat pending on
live for weeks. Grep new queries for columns and confirm their migration has run.

**No queue worker runs here.** `supervisorctl` is not installed. `queue:restart`
is harmless but signals nothing. If mail must send, `QUEUE_CONNECTION` has to be
`sync` or someone must run a worker.

**`build-deploy-package.ps1` is PowerShell.** Use the bash version in §3.

---

## 9. Deploy log

Record the SHA after every successful deploy — it is the next `BASE`.

| Date | SHA | What shipped | Notes |
|---|---|---|---|
| 2026-08-17 | `968b291` | — | earlier baseline |
| 2026-08-17 | `5585379` | vendor detail workspaces (TPV + Purchase) | |
| _pending_ | `8f6f6a7` | Customer 360 complete; draft-invoice fix; 46 commits of TPV/Purchase compliance | 14 migrations (7 long-pending + 7 new), all additive |
