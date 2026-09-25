#!/usr/bin/env bash
#
# BR-P0-003 under a real race — D-136.
#
# A guard against a race is not proven by a test that does not race, and the
# suite runs on in-memory SQLite with one connection. This runs two PHP
# processes against MySQL, lined up on the same instant, both allocating one
# vehicle to two different trips.
#
# It builds its OWN database in a throwaway container and destroys it. The
# first version of this harness ran against the shared dev database with raw
# INSERT/UPDATE and left a truck reading AVAILABLE while an assignment held
# it — D-137. Nothing here touches dev.
#
# Usage:  tests/concurrency/race-allocation.sh
set -euo pipefail

BACKEND="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
NAME=stos-race-$$
PORT=33072

cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "→ throwaway mysql:8.0 on :$PORT"
docker run -d --name "$NAME" -e MYSQL_ROOT_PASSWORD=race -e MYSQL_DATABASE=race \
  -p "$PORT:3306" mysql:8.0 >/dev/null

until docker exec "$NAME" mysqladmin -uroot -prace ping >/dev/null 2>&1; do sleep 2; done

export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$PORT" \
       DB_DATABASE=race DB_USERNAME=root DB_PASSWORD=race

cd "$BACKEND"
# The schema comes from a STRUCTURE-ONLY dump of dev, not from `migrate`.
# A from-scratch MySQL build does not currently complete — D-139, an HR
# migration references a column added by a later one — and that is not this
# harness's problem to solve. Structure only: no dev data is copied in.
echo "→ loading schema (structure only, no data)"
DEV_PW=$(grep '^DB_PASSWORD=' "$BACKEND/.env" | cut -d= -f2-)
mysqldump -h127.0.0.1 -usangoe_crm -p"$DEV_PW" --no-data --single-transaction \
  --set-gtid-purged=OFF --routines sangoe_crm 2>/dev/null \
  | docker exec -i "$NAME" mysql -uroot -prace race

echo "→ seeding through the real services"
FIX=$(php tests/concurrency/seed.php | tail -1)
TENANT=$(echo "$FIX" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["tenant"];')
ACTOR=$(echo  "$FIX" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["actor"];')
VEH=$(echo    "$FIX" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["vehicle"];')
T1=$(echo     "$FIX" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["trips"][0];')
T2=$(echo     "$FIX" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["trips"][1];')

echo "→ racing trips $T1 and $T2 for vehicle $VEH"
START=$(php -r 'echo microtime(true)+4;')
php tests/concurrency/allocate.php "$T1" "$VEH" "$START" "$TENANT" "$ACTOR" > /tmp/race1.$$ 2>&1 &
php tests/concurrency/allocate.php "$T2" "$VEH" "$START" "$TENANT" "$ACTOR" > /tmp/race2.$$ 2>&1 &
wait

echo
echo "  runner 1: $(tail -1 /tmp/race1.$$)"
echo "  runner 2: $(tail -1 /tmp/race2.$$)"

LIVE=$(docker exec "$NAME" mysql -uroot -prace race -N -e \
  "SELECT COUNT(*) FROM trip_assignments WHERE vehicle_id=$VEH AND status IN ('assigned','confirmed','active');" 2>/dev/null)
echo "  active assignments on vehicle $VEH: $LIVE"
echo

rm -f /tmp/race1.$$ /tmp/race2.$$

if [ "$LIVE" != "1" ]; then
  echo "FAIL — $LIVE vehicles double-booked. BR-P0-003 did not hold."
  exit 1
fi

# One success and one REFUSAL is the pass. WHICH refusal is the point of
# D-136: a BusinessException naming the trip is the designed one; a
# QueryException about a deadlock means the lock serialised nothing and the
# unique index caught it instead.
echo "PASS — exactly one allocation succeeded."
