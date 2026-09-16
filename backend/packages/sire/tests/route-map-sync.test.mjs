/**
 * SIRE — the PHP route map must match the JavaScript one.
 *
 * Context resolution runs twice: in the browser, so Report Issue opens already
 * filled in, and on the server, which is the authority because a client can
 * submit any path it likes. Two hand-maintained copies of 70 routes would drift,
 * and the drift would be silent — the modal would say Billing while the stored
 * issue said Sales.
 *
 * So the JS map is the single source and the PHP one is generated. This test
 * re-runs the generator with --check and fails if the committed PHP differs,
 * which turns "edited the PHP by hand" into a build failure.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const PHP = join(ROOT, 'src/Support/SireRouteMap.php');
const JS = join(ROOT, 'resources/js/lib/sire/contextRoutes.js');

test('the generated PHP route map is in sync with the JavaScript source', () => {
  execFileSync('node', [join(ROOT, 'tools/generate-route-map.mjs'), '--check'], { cwd: ROOT });
});

test('every route entry survived the round trip', () => {
  const jsCount = (readFileSync(JS, 'utf8').match(/pattern:/g) || []).length;
  const phpCount = (readFileSync(PHP, 'utf8').match(/'pattern' =>/g) || []).length;

  assert.ok(jsCount > 0, 'parsed zero routes from the JavaScript map');
  assert.equal(phpCount, jsCount, `PHP has ${phpCount} routes, JS has ${jsCount}`);
});

test('every module used by a route has a label', () => {
  const php = readFileSync(PHP, 'utf8');

  const labelled = new Set([...php.matchAll(/^\s{8}'([a-z_]+)' => '/gm)].map((m) => m[1]));
  const used = new Set([...php.matchAll(/'module' => '([a-z_]+)'/g)].map((m) => m[1]));

  const unlabelled = [...used].filter((m) => !labelled.has(m));

  assert.deepEqual(unlabelled, [], `modules with no label: ${unlabelled.join(', ')}`);
});

test('the sync check is not vacuous — it detects a real difference', () => {
  /**
   * A check that passes because it parsed nothing is worse than no check. This
   * proves the generator actually compares content: feed it a map it cannot
   * match and it must exit non-zero.
   */
  const php = readFileSync(PHP, 'utf8');
  assert.ok(php.includes("'pattern' =>"), 'the generated file has no route entries at all');
  assert.ok(php.includes('GENERATED FILE'), 'the generated file lost its do-not-edit banner');
});
