#!/usr/bin/env bash
#
# Deploy to crm.nexforeconsulting.com.
#
# This script exists because the deploy used to be a hand-typed rsync, and on
# 10 Sep 2026 a flag was improvised at the prompt: `--delete-excluded` was added
# to a command that had run correctly six times that day. That flag INVERTS every
# --exclude. The arguments protecting .env, vendor/, the database and
# storage/app/ became instructions to delete them, and the production box lost
# all four — mid-demo — along with the database backup taken sixty seconds
# earlier, because its filename matched one of the excluded patterns.
#
# Nothing about the operator was careless that day; the six identical deploys
# before it were fine. What was missing was anywhere for the flags to live
# except a human's memory. So they live here now, reviewed once, and the prompt
# has nothing left to improvise.
#
# Three rules this file enforces, each from something that actually happened:
#
#   1. NO --delete, ever, on the backend sync. The server holds four things the
#      repo does not — .env, vendor/, the database, uploaded files — and every
#      one is irreplaceable. A deploy that can delete is a deploy that will.
#
#   2. A backup is pulled OFF the box before anything is written, and it takes
#      the database and the uploads in the SAME pass. An attachment is a row and
#      a file stored apart: restore Tuesday's rows over Friday's files and you
#      get issues whose evidence 404s. A copy left on the server is not a backup
#      — the disk that dies takes both.
#
#   3. A dry run prints every change and waits for a yes. `rsync -n` would have
#      shown four directories queued for deletion in about one second.
#
# Usage:
#   ./deploy.sh              # dry run, shows what would change, then asks
#   ./deploy.sh --yes        # skip the prompt (CI, or a repeat of a run you just saw)
#   ./deploy.sh --no-backup  # skip the pull (only when you have just taken one)
#
set -euo pipefail

SSH_TARGET="nexforeconsulting.co_bhmrselvhng@45.90.220.5"
REMOTE="/var/www/vhosts/nexforeconsulting.com/crm.nexforeconsulting.com"
PHP="/opt/plesk/php/8.3/bin/php"
COMPOSER="/opt/psa/var/modules/composer/composer.phar"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${SANGOE_BACKUP_DIR:-$HOME/sangoe-backups}"

ASSUME_YES=0
DO_BACKUP=1
for arg in "$@"; do
  case "$arg" in
    --yes|-y)     ASSUME_YES=1 ;;
    --no-backup)  DO_BACKUP=0 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
fail() { printf '\n\033[31mFAILED: %s\033[0m\n' "$*" >&2; exit 1; }

# ── The excludes ────────────────────────────────────────────────────────────
#
# Read this as "things the server owns that a deploy must never touch". Without
# --delete they are simply skipped, which is the whole point: the server keeps
# its own copy and we never send ours over it.
#
# bootstrap/cache is here because a local cache lists dev-only packages; copying
# it up makes every artisan command on the server die with
# "Class Laravel\Pail\PailServiceProvider not found".
#
# public/ is excluded because the frontend build lands there in its own pass;
# syncing backend/public over it would delete the SPA's assets.
BACKEND_EXCLUDES=(
  --exclude='.env'
  --exclude='vendor/'
  --exclude='node_modules/'
  --exclude='.git/'
  --exclude='public/'
  --exclude='bootstrap/cache/*'
  --exclude='storage/logs/*'
  --exclude='storage/app/*'
  --exclude='storage/framework/cache/*'
  --exclude='storage/framework/sessions/*'
  --exclude='storage/framework/views/*'
  --exclude='database/*.sqlite*'
)

# Guard against the exact mistake this file was written for. If anyone ever adds
# a delete flag above, or passes one in, stop before touching the server.
for flag in "${BACKEND_EXCLUDES[@]}" "${@:-}"; do
  case "$flag" in
    --delete|--delete-*|--remove-source-files)
      fail "refusing to run: '$flag' can delete server-owned files (.env, vendor, the database, uploads).
      That is what destroyed production on 10 Sep. If you genuinely need it, do it by hand,
      with --dry-run first, and take an off-box backup before you start."
      ;;
  esac
done

# ── 0. Reachable? ───────────────────────────────────────────────────────────
say "Checking the server"
ssh -o BatchMode=yes -o ConnectTimeout=20 "$SSH_TARGET" "echo reachable" >/dev/null \
  || fail "cannot reach $SSH_TARGET over SSH"

# ── 1. Backup, off the box, database and files together ─────────────────────
if [[ "$DO_BACKUP" == "1" ]]; then
  say "Backing up (database + uploads, one pass, onto this machine)"
  STAMP="$(date +%Y%m%d-%H%M%S)"
  DEST="$BACKUP_DIR/$STAMP"
  mkdir -p "$DEST"

  # Snapshot the database ON the server first, so the copy we pull is a single
  # consistent moment rather than a file being written while it travels.
  ssh -o BatchMode=yes "$SSH_TARGET" \
    "cd $REMOTE && cp database/database.sqlite /tmp/db-$STAMP.sqlite" \
    || fail "could not snapshot the database"

  rsync -az "$SSH_TARGET:/tmp/db-$STAMP.sqlite" "$DEST/database.sqlite" \
    || fail "could not pull the database"
  ssh -o BatchMode=yes "$SSH_TARGET" "rm -f /tmp/db-$STAMP.sqlite"

  # The uploads, in the same run, so the two halves always match.
  rsync -az "$SSH_TARGET:$REMOTE/storage/app/" "$DEST/storage-app/" \
    || fail "could not pull the uploads"

  echo "   saved to $DEST"
  du -sh "$DEST" | sed 's/^/   /'
fi

# ── 2. Build the frontend ───────────────────────────────────────────────────
say "Building the frontend"
( cd "$HERE/frontend" && npm run build ) || fail "the frontend build failed"

# ── 3. Stamp both halves with the same commit ───────────────────────────────
# sire:doctor compares these and warns when only one half was deployed — which
# is how a backend-only sync once left the SPA a version behind, with the API
# offering a field the UI had no code to render.
COMMIT="$(git -C "$HERE" rev-parse --short HEAD)"
say "Stamping both halves as $COMMIT"
echo "$COMMIT" > "$HERE/backend/build-id.txt"
echo "$COMMIT" > "$HERE/frontend/dist/build-id.txt"

# ── 4. Dry run, then confirm ────────────────────────────────────────────────
if [[ "$ASSUME_YES" != "1" ]]; then
  say "Dry run — nothing has been written yet"
  rsync -az --dry-run --itemize-changes "${BACKEND_EXCLUDES[@]}" \
    "$HERE/backend/" "$SSH_TARGET:$REMOTE/" | tail -30
  rsync -az --dry-run --itemize-changes \
    "$HERE/frontend/dist/" "$SSH_TARGET:$REMOTE/public/" | tail -15

  printf '\nDeploy %s to production? [y/N] ' "$COMMIT"
  read -r reply
  [[ "$reply" =~ ^[Yy]$ ]] || { echo "stopped — nothing was written."; exit 0; }
fi

# ── 5. Ship it ──────────────────────────────────────────────────────────────
say "Syncing the backend"
rsync -az "${BACKEND_EXCLUDES[@]}" "$HERE/backend/" "$SSH_TARGET:$REMOTE/" \
  || fail "the backend sync failed"

# Always after the backend — see the build-parity note above.
say "Syncing the frontend"
rsync -az "$HERE/frontend/dist/" "$SSH_TARGET:$REMOTE/public/" \
  || fail "the frontend sync failed"

# ── 6. Server side ──────────────────────────────────────────────────────────
# storage:link is re-run every time because the backend sync carries this
# machine's symlink, which points at a path that does not exist on the server.
say "Installing, migrating and caching"
ssh -o BatchMode=yes "$SSH_TARGET" "set -e
  cd $REMOTE
  $PHP $COMPOSER install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -2
  $PHP -d memory_limit=-1 artisan migrate --force 2>&1 | tail -5
  rm -f public/storage && $PHP artisan storage:link >/dev/null
  $PHP artisan config:cache >/dev/null
  $PHP artisan route:cache  >/dev/null
  $PHP artisan view:cache   >/dev/null
  echo '   caches rebuilt'
" || fail "the server-side step failed — the site may be mid-deploy, check it now"

# ── 7. Prove it works ───────────────────────────────────────────────────────
# A deploy that ends without checking is a deploy that ends with hope.
say "Verifying"
check() {
  local path="$1" want="$2"
  local got
  got="$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "https://crm.nexforeconsulting.com$path")"
  printf '   %-26s %s' "$path" "$got"
  if [[ "$got" == "$want" ]]; then echo "  ok"; else echo "  EXPECTED $want"; return 1; fi
}

ok=0
check "/"                  200 || ok=1
check "/api/hr/employees"  401 || ok=1   # 401 is right: routing and auth both alive
check "/.env"              403 || ok=1
ssh -o BatchMode=yes "$SSH_TARGET" "cd $REMOTE && test -f public/index.php && test -e public/storage" \
  || { echo "   entry files MISSING"; ok=1; }

[[ "$ok" == "0" ]] || fail "the site is not healthy after deploying — check it before walking away"

say "Deployed $COMMIT"
[[ "$DO_BACKUP" == "1" ]] && echo "   backup: $DEST"
echo
