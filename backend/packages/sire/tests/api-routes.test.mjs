import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/**
 * Every SIRE endpoint the SPA calls must exist in routes/sire.php.
 *
 * This caught `regenerateReleaseNote` pointing at a route that was never written —
 * a 404 the user would only have discovered by clicking the button. Cheap check,
 * and it runs without a database or a browser.
 */
const api = readFileSync('resources/js/services/sireApi.js', 'utf8');
const routes = readFileSync('routes/sire.php', 'utf8');

/** `/sire/reports/${id}/root-cause` -> ['reports', '{}', 'root-cause'] */
const normalise = (path) =>
  path.replace(/^\/sire\//, '')
    .split('?')[0]
    .split('/')
    .filter(Boolean)
    .map((seg) => (seg.startsWith('${') || seg.startsWith('{') ? '{}' : seg));

const declared = new Set();
for (const m of routes.matchAll(/Route::(get|post|patch|put|delete)\(\s*'([^']+)'/g)) {
  declared.add(`${m[1].toUpperCase()} ${normalise('/sire/' + m[2]).join('/')}`);
}

const called = [];
for (const m of api.matchAll(/api\.(get|post|patch|put|delete)\(\s*[`']([^`']+)[`']/g)) {
  called.push({ method: m[1].toUpperCase(), path: m[2], key: `${m[1].toUpperCase()} ${normalise(m[2]).join('/')}` });
}

test('the scan found both sides (guards against a vacuous check)', () => {
  assert.ok(declared.size >= 30, `expected routes, found ${declared.size}`);
  assert.ok(called.length >= 30, `expected api calls, found ${called.length}`);
});

test('every Phase 2 API call maps to a declared route', () => {
  // Phase 0/1 routes live in the main sire.php, which is not in this tree; only
  // check the paths this file is responsible for.
  const phase2Prefixes = ['quality', 'reports/{}/root-cause', 'reports/{}/relations', 'reports/{}/links',
    'links/{}', 'reports/{}/regression', 'recurrence-groups', 'releases', 'release-notes',
    'reports/{}/kb-links', 'reports/{}/kb-article', 'kb-links/{}', 'capa', 'reports/{}/capa', 'dashboard',
    // Phase 3
    'release-board', 'release-gates', 'release-overrides', 'ai',
    'reports/{}/test-cases', 'test-cases/{}'];

  // Compare the FULL normalised prefix. Matching on the first segment alone
  // swept in `GET /sire/reports`, a Phase 1 route that lives in the main
  // routes/sire.php and is not part of this file's responsibility.
  const missing = called
    .filter((c) => phase2Prefixes.some((prefix) => normalise(c.path).join('/').startsWith(prefix)))
    .filter((c) => !declared.has(c.key));

  assert.deepEqual(missing.map((m) => `${m.method} ${m.path}`), [],
    'frontend calls an endpoint that does not exist');
});
