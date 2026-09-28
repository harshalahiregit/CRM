/**
 * SIRE — every route must point at a controller method that exists.
 *
 * A route naming a missing method is invisible until someone hits that URL, and
 * then it is a 500 in production rather than a build failure. This package
 * shipped one such route: a GET on reports/{report}/attachments pointing at
 * ReportController::indexAttachments, which had never been written. Nothing
 * caught it, because nothing was looking.
 *
 * Now something is looking.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const ROUTES = join(ROOT, 'routes/sire.php');
const CONTROLLERS = join(ROOT, 'src/Http/Controllers');

const src = readFileSync(ROUTES, 'utf8');

/** [{ verb, path, controller, method }] — invokable routes carry method '__invoke'. */
const routes = [
  ...[...src.matchAll(/Route::(\w+)\(\s*'([^']+)'\s*,\s*\[(\w+)::class,\s*'(\w+)'\]/g)]
    .map((m) => ({ verb: m[1], path: m[2], controller: m[3], method: m[4] })),
  ...[...src.matchAll(/Route::(\w+)\(\s*'([^']+)'\s*,\s*(\w+Controller)::class\s*\)/g)]
    .map((m) => ({ verb: m[1], path: m[2], controller: m[3], method: '__invoke' })),
];

const methodsOf = (controller) => {
  const file = join(CONTROLLERS, `${controller}.php`);
  if (!existsSync(file)) return null;
  const body = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');
  return [...body.matchAll(/public function (\w+)\s*\(/g)].map((m) => m[1]);
};

test('the route file parsed (guards against a vacuous check)', () => {
  assert.ok(routes.length >= 70, `parsed only ${routes.length} routes`);
  // Count only verb calls: Route::middleware() opens the group and registers
  // nothing, so including it would make this assertion permanently off by one.
  const declared = (src.match(/Route::(get|post|put|patch|delete|any|match)\(/g) || []).length;
  assert.equal(routes.length, declared, `parsed ${routes.length} of ${declared} route registrations`);
});

test('every route names a controller that exists', () => {
  const missing = [...new Set(routes.map((r) => r.controller))].filter((c) => methodsOf(c) === null);
  assert.deepEqual(missing, [], `controllers referenced but absent: ${missing.join(', ')}`);
});

test('every route names a method that exists on that controller', () => {
  const broken = [];

  for (const route of routes) {
    const methods = methodsOf(route.controller);
    if (methods === null) continue;
    if (!methods.includes(route.method)) {
      broken.push(`${route.verb.toUpperCase()} ${route.path} → ${route.controller}::${route.method}()`);
    }
  }

  assert.deepEqual(broken, [], `\n${broken.join('\n')}\n`);
});

test('no verb+path pair is registered twice', () => {
  const seen = new Map();
  const dupes = [];

  for (const route of routes) {
    const key = `${route.verb} ${route.path}`;
    if (seen.has(key)) dupes.push(`${key} → ${seen.get(key)} and ${route.controller}::${route.method}`);
    seen.set(key, `${route.controller}::${route.method}`);
  }

  assert.deepEqual(dupes, [], `\n${dupes.join('\n')}\n`);
});

test('all routes sit inside one middleware group, composed from config', () => {
  /**
   * The rule with teeth. A second chained ->middleware() call REPLACES the first
   * instead of adding to it, silently dropping authentication — the discovery
   * report for the first host attributes a live cross-vendor leak to exactly
   * that.
   *
   * The stack is no longer a literal: it comes from SireRouteMiddleware::stack(),
   * because SIRE cannot know whether a host authenticates with Sanctum, a
   * session, Passport or something bespoke. So this asserts the composition
   * instead — one group, built from config, with nothing hardcoded.
   */
  const groups = (src.match(/Route::middleware\(/g) || []).length;
  assert.equal(groups, 1, `expected exactly one middleware group, found ${groups}`);

  assert.match(src, /Route::middleware\(\\Sire\\Http\\SireRouteMiddleware::stack\(\)\)/,
    'the group must use the composed stack');

  assert.match(src, /->prefix\(\(string\) config\('sire\.host\.route_prefix'/,
    'the prefix must come from config, so a host can mount SIRE anywhere');
});

test('the route file hardcodes no guard, role or prefix', () => {
  /**
   * What "CRM-agnostic" means at the routing layer. A literal 'auth:sanctum' or
   * 'role:admin,staff' here would be wrong in most installations — and wrong in
   * a way that either locks everyone out or lets everyone in.
   */
  const code = src
    .split('\n')
    .filter((line) => {
      const t = line.trim();
      return t !== '' && !t.startsWith('*') && !t.startsWith('//') && !t.startsWith('/*');
    })
    .join('\n');

  assert.doesNotMatch(code, /'auth:[\w-]+'/, 'no hardcoded guard');
  assert.doesNotMatch(code, /'role:[\w,-]+'/, 'no hardcoded role middleware');

  // 'api/sire' DOES appear — as the second argument to config(), which is a
  // documented fallback rather than a hardcode. A default is what makes SIRE
  // work before installation; the test is that the value is READ from config,
  // not that the string is absent.
  assert.doesNotMatch(code, /->prefix\(\s*'/, 'the prefix must not be a literal');
  assert.match(code, /config\('sire\.host\.route_prefix', 'api\/sire'\)/,
    'the prefix comes from config, with a documented default');
});

test('the middleware stack fails closed when nothing is configured', () => {
  /**
   * The case that matters most, because it is the one someone reaches by
   * accident: an emptied auth_middleware while debugging, never restored.
   *
   * SIRE substitutes a deny-all guard rather than serving an engineering backlog
   * to anonymous traffic. That behaviour lives in SireRouteMiddleware, and it is
   * asserted here because "we fail closed" is a claim, not a fact, until
   * something checks it.
   */
  const stack = readFileSync(join(ROOT, 'src/Http/SireRouteMiddleware.php'), 'utf8');

  assert.match(stack, /if \(\$auth === \[\]\)/, 'empty auth middleware must be handled explicitly');
  assert.match(stack, /SireDenyAll::class/, 'and must substitute a deny-all guard');

  const deny = readFileSync(join(ROOT, 'src/Http/Middleware/SireDenyAll.php'), 'utf8');
  assert.match(deny, /abort\(503/, 'deny-all must refuse the request');

  // The engineering-login gate is always appended, never optional: customers
  // and vendors must not become reachable by editing a config file.
  assert.match(stack, /SireRequireEngineeringLogin::class/);
});

test('there is no route that edits or deletes a system event', () => {
  /**
   * The absence IS the feature. Audit entries are the evidence behind release
   * approvals and emergency overrides; a history with an edit endpoint proves
   * nothing about what happened.
   */
  const forbidden = routes.filter(
    (r) => /audit|timeline/i.test(r.path) && ['put', 'patch', 'delete'].includes(r.verb),
  );

  assert.deepEqual(forbidden.map((r) => `${r.verb} ${r.path}`), []);
});
