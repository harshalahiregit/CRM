import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { auditRoutes, isReportable } from '../tools/sire-route-audit.mjs';
import { resolveRouteContext } from '../resources/js/lib/sire/resolveRouteContext.js';

/**
 * SIRE — every page Report Issue can be opened from must be on the map.
 *
 * WHY THIS IS A TEST AND NOT A CHORE. The failure mode is silent. A developer
 * adds a screen, nobody adds a line to contextRoutes.js, and Report Issue still
 * opens and still submits -- it just shows a blank SECTION and RECORD. The
 * reporter cannot tell that the form was supposed to fill those in, so nobody
 * reports the reporting tool, and the issue arrives with no idea which screen
 * it came from. That is the single most useful fact on a bug report.
 *
 * It had already happened at scale: the map was seeded from a module inventory
 * rather than read out of the router, and by the time anyone checked, the whole
 * Transport module and fourteen record-bearing screens were missing.
 *
 * ONLY /app IS CHECKED. Report Issue is mounted in AppShell. The public portals
 * cannot open it, so entries for them would be data nothing reads.
 */

const ROUTES = new URL('../../../../frontend/src/app/routes.jsx', import.meta.url);
const HOST_LIB = new URL('../../../../frontend/src/lib/sire/', import.meta.url);
const PKG_LIB = new URL('../resources/js/lib/sire/', import.meta.url);

/**
 * The package ships standalone; a host's router is not always present. Skipping
 * is honest -- there is nothing to check -- and inside this repo the file is
 * always there, so the check really runs where it matters.
 */
const hostPresent = existsSync(ROUTES);

test('every reportable page resolves to a mapped screen', { skip: !hostPresent && 'no host router' }, () => {
  const pages = auditRoutes(readFileSync(ROUTES, 'utf8')).filter((p) => isReportable(p.path));

  assert.ok(pages.length > 100, `expected the whole app, found ${pages.length} pages`);

  const unmapped = pages.filter((p) => p.source !== 'route').map((p) => p.path);

  assert.deepEqual(
    unmapped,
    [],
    `${unmapped.length} page(s) have no entry in contextRoutes.js, so Report Issue `
      + 'cannot name the screen they were filed from. Add one line each:\n  '
      + unmapped.join('\n  '),
  );
});

test('every page whose URL names a record captures it', { skip: !hostPresent && 'no host router' }, () => {
  const pages = auditRoutes(readFileSync(ROUTES, 'utf8')).filter((p) => isReportable(p.path));

  // The senior's actual complaint: the screen is found, the RECORD is blank.
  // It happened wherever a detail page carried entityType and its own tabs or
  // /edit child did not -- '/app/contracts/5' showed the contract, and
  // '/app/contracts/5/edit' showed nothing.
  const missed = pages.filter((p) => p.recordMissed).map((p) => p.path);

  assert.deepEqual(
    missed,
    [],
    `${missed.length} page(s) have an id in the URL that the map does not capture. `
      + 'Add entityType (and entityParam when the param is not :id):\n  '
      + missed.join('\n  '),
  );
});

test('a mapped record actually yields an id', { skip: !hostPresent && 'no host router' }, () => {
  const pages = auditRoutes(readFileSync(ROUTES, 'utf8')).filter((p) => isReportable(p.path));

  // entityParam pointing at a param the route does not have is the quiet
  // version of the same bug: the map claims a record and hands back null.
  const empty = pages
    .filter((p) => p.entityType && !p.entityId)
    .map((p) => `${p.path} (entityType: ${p.entityType})`);

  assert.deepEqual(empty, [], `entityParam names a param these routes do not have:\n  ${empty.join('\n  ')}`);
});

test('a workspace whose tabs are generated still reports its record', () => {
  // The purchase vendor workspace renders its own <Routes> from a data-driven
  // tab list (BUILT_NAV_ITEMS), so those ~37 paths exist in no routes.jsx and
  // the scanner above cannot see them. Hand-listing them would go stale the
  // first time a tab is added.
  //
  // They are covered instead by the resolver's prefix pass, which inherits the
  // parent's module, section and RECORD and derives the screen from the tail.
  // That is the behaviour this pins: the tab is not the record, the vendor is,
  // and a reporter on any tab must still file against that vendor.
  for (const tab of ['overview', 'ncr', 'documents', 'a-tab-nobody-has-written-yet']) {
    const ctx = resolveRouteContext(`/app/purchase/vendors/88/${tab}`);

    assert.equal(ctx.entityType, 'purchase_vendor', `${tab}: lost the record`);
    assert.equal(ctx.entityId, '88', `${tab}: lost the id`);
    assert.equal(ctx.module, 'purchase');
    assert.ok(ctx.screen, `${tab}: no screen key, so the export cannot group it`);
  }
});

test('no shared client file has drifted between the package and the host', {
  skip: !existsSync(HOST_LIB) && 'no host copy',
}, () => {
  /*
   * Every file here exists twice: the package ships it, the host runs it. The
   * app loads the HOST copy while this suite imports the PACKAGE one, so
   * without this check the suite can pass green against code the application
   * never loads.
   *
   * That is not hypothetical. resolveRouteContext.js had drifted badly: the
   * host copy carried the prefix pass that gives a workspace tab its parent's
   * record (the SIR-000011 fix) and the package copy had never received it.
   * Anything testing against the package was measuring a resolver two fixes
   * behind the one users actually had.
   */
  const drifted = [];
  let compared = 0;

  for (const name of readdirSync(PKG_LIB)) {
    if (!name.endsWith('.js')) continue;

    const hostFile = new URL(`${HOST_LIB}${name}`);
    if (!existsSync(hostFile)) continue;

    const host = readFileSync(hostFile, 'utf8').replace(/\r\n/g, '\n');
    const pkg = readFileSync(new URL(`${PKG_LIB}${name}`), 'utf8').replace(/\r\n/g, '\n');

    compared += 1;
    if (host !== pkg) drifted.push(name);
  }

  // Without this, a wrong path makes the whole check vacuously green: it would
  // compare nothing, find no drift, and report success.
  assert.ok(compared > 5, `expected to compare the shared lib, compared ${compared} files`);

  assert.deepEqual(
    drifted,
    [],
    `these files differ between frontend/src/lib/sire/ and the package copy:\n  ${drifted.join('\n  ')}`,
  );
});
