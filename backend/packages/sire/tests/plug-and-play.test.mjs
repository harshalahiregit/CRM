/**
 * SIRE — the plug-and-play claims, checked.
 *
 * "Installs into almost any compatible Laravel CRM" is a marketing sentence
 * until something enforces it. These tests are the enforcement: they run the
 * real classification logic against three deliberately different host shapes,
 * assert the installer refuses to guess where guessing is dangerous, and check
 * that uninstall can only ever touch tables SIRE owns.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => readFileSync(join(ROOT, p), 'utf8');

// ---------------------------------------------------------------- fixtures

test('the reference host fixtures pass, using SIRE’s own classes', () => {
  /**
   * Runs tools/run-fixture-checks.php, which loads RoleClassifier,
   * SireLoginType and Compatibility — the same classes the installer uses. A
   * fixture suite that reimplemented the rules would prove only that the
   * reimplementation agrees with itself.
   */
  const output = execFileSync('php', [join(ROOT, 'tools/run-fixture-checks.php'), '--json'], {
    encoding: 'utf8',
  });

  const result = JSON.parse(output);

  assert.deepEqual(result.failures, [], `\n${result.failures.join('\n\n')}\n`);
  assert.ok(result.passes >= 40, `only ${result.passes} fixture checks ran`);
});

test('the fixtures cover genuinely different host shapes', () => {
  /**
   * Three fixtures that all looked like the first host would prove nothing.
   * Each axis below is one SIRE used to hardcode.
   */
  const fixtures = readdirSync(join(ROOT, 'fixtures/hosts'))
    .filter((f) => f.endsWith('.json'))
    .map((f) => JSON.parse(read(`fixtures/hosts/${f}`)));

  assert.ok(fixtures.length >= 3, `only ${fixtures.length} fixtures`);

  const distinct = (fn) => new Set(fixtures.map(fn).map((v) => JSON.stringify(v))).size;

  assert.ok(distinct((f) => f.expected['tenant.attribute']) >= 3, 'tenant columns must differ');
  assert.ok(distinct((f) => f.expected['host.auth_middleware']) >= 3, 'auth middleware must differ');
  assert.ok(distinct((f) => f.discovered['roles.source']) >= 2, 'role sources must differ');
  assert.ok(distinct((f) => f.expected['frontend.type']) >= 3, 'frontends must differ');

  // At least one host with NO tenancy at all — the case that must not be
  // papered over with a guess.
  assert.ok(
    fixtures.some((f) => f.expected['tenant.strategy'] === null),
    'one fixture must have no detectable tenancy',
  );
});

// ---------------------------------------------------------------- refusing to guess

test('discovery never proposes a tenant strategy with HIGH confidence', () => {
  /**
   * The one failure in SIRE with no symptoms. A wrong tenant source returns
   * another customer's data on a page that renders normally, so discovery is
   * deliberately pessimistic: one tenant-shaped column is MEDIUM at best,
   * because `company_id` might identify the tenant or the customer a user works
   * for, and the schema cannot tell those apart.
   */
  const detector = read('src/Discovery/Detectors/TenancyDetector.php');

  assert.doesNotMatch(
    detector.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, ''),
    /'tenant\.attribute'\s*=>\s*Finding::found\([^)]*Finding::HIGH/,
    'a tenant column must never be reported as HIGH confidence',
  );

  assert.match(detector, /count\(\$matches\) === 1 \? Finding::MEDIUM : Finding::LOW/);
});

test('every security-sensitive setting requires confirmation', () => {
  const profile = read('src/Discovery/HostProfile.php');

  for (const key of [
    'tenant.strategy', 'tenant.attribute',
    'auth.guard', 'auth.middleware',
    'roles.admin', 'roles.internal_user', 'roles.customer', 'roles.vendor',
    'permissions.provider',
  ]) {
    assert.ok(profile.includes(`'${key}'`), `${key} must be listed as security-sensitive`);
  }
});

test('non-interactive install refuses rather than guessing', () => {
  /**
   * The dangerous mode. A CI pipeline running `sire:install --non-interactive`
   * with an incomplete answer file must STOP, not fall back to a default — the
   * whole point of the confirmation gate is that it cannot be skipped by
   * automation.
   */
  const installer = read('src/Console/Commands/SireInstall.php');

  assert.match(installer, /if \(\$this->option\('non-interactive'\)\)/);
  assert.match(installer, /security-sensitive, so SIRE will not guess/);
  assert.match(installer, /return self::ABORT/);
});

// ---------------------------------------------------------------- uninstall safety

test('uninstall can only ever drop tables SIRE owns', () => {
  const uninstall = read('src/Console/Commands/SireUninstall.php');

  const listed = [...uninstall.matchAll(/'(sire_\w+)'/g)].map((m) => m[1]);
  const created = new Set();

  for (const file of readdirSync(join(ROOT, 'database/migrations'))) {
    for (const m of read(`database/migrations/${file}`).matchAll(/Schema::create\('(\w+)'/g)) {
      created.add(m[1]);
    }
  }

  // Exact correspondence in both directions: a table SIRE creates but never
  // drops leaks on uninstall; one it drops but never creates is a host table.
  assert.deepEqual(
    [...new Set(listed)].sort(),
    [...created].sort(),
    'the purge list and the migrations must name exactly the same tables',
  );

  // Belt and braces, in the code itself.
  assert.match(uninstall, /str_starts_with\(\$table, 'sire_'\)/,
    'the drop loop must re-check the prefix at runtime');

  // Data deletion must never be reachable from a flag alone. --force skips the
  // ordinary confirmations; the typed phrase is checked AFTER it, so a runbook
  // that copied --force still cannot destroy a year of engineering history.
  assert.match(uninstall, /DELETE SIRE DATA/, 'purge must require a typed confirmation');

  const typedGate = uninstall.indexOf("DELETE SIRE DATA");
  const forceGate = uninstall.indexOf("! $this->option('force')");

  assert.ok(forceGate !== -1 && typedGate > forceGate,
    'the typed confirmation must come after --force can take effect, so --force cannot bypass it');
});

test('disable keeps data; only --purge deletes it', () => {
  const uninstall = read('src/Console/Commands/SireUninstall.php');

  assert.match(uninstall, /if \(! \$this->option\('purge'\)\)/);
  assert.match(uninstall, /All SIRE data was kept/);
});

// ---------------------------------------------------------------- config

test('host configuration merges over defaults without losing them', () => {
  const config = read('config/sire.php');

  assert.match(config, /sire-host\.php/, 'the generated host config must be merged');
  assert.match(config, /array_is_list/, 'lists must replace rather than merge');

  // The reason lists replace: if they merged, a role removed from
  // login_types.customer would still be granted by the default beneath it.
  assert.match(config, /LISTS REPLACE/);
});

test('every provider key in config has a contract and a default', () => {
  const config = read('config/sire.php');
  const keys = [...config.matchAll(/^\s{8}'(\w+)'\s*=>\s*\\Sire\\Adapters\\Defaults\\(\w+)::class,/gm)];

  assert.equal(keys.length, 13, `expected 13 provider bindings, found ${keys.length}`);

  for (const [, key, impl] of keys) {
    assert.ok(existsSync(join(ROOT, `src/Adapters/Defaults/${impl}.php`)), `${impl} does not exist`);
  }
});

// ---------------------------------------------------------------- commands

test('every documented command exists and is registered', () => {
  const provider = read('src/SireServiceProvider.php');

  const commands = [
    'SireDiscover', 'SireCompatibility', 'SireInstall', 'SireDoctor',
    'SireArchitecture', 'SireHostProfile', 'SireUninstall',
    'RunSireSchedule', 'IndexSireIssues', 'ExportSireWorkflow',
  ];

  for (const command of commands) {
    assert.ok(existsSync(join(ROOT, `src/Console/Commands/${command}.php`)), `${command}.php missing`);
    assert.ok(provider.includes(`Console\\Commands\\${command}::class`), `${command} not registered`);
  }
});

test('discovery is read-only', () => {
  /**
   * Discovery runs against production databases. It reads the schema, the
   * container, config and the filesystem — and writes exactly one file, in
   * storage/app. Anything else would make it the sort of command people are
   * afraid to run, which defeats the point.
   */
  const detectors = readdirSync(join(ROOT, 'src/Discovery/Detectors'))
    .filter((f) => f.endsWith('.php'))
    .map((f) => read(`src/Discovery/Detectors/${f}`));

  const forbidden = /->(insert|update|delete|truncate|drop|save|create)\s*\(|DB::statement|Schema::(create|drop|table)/;

  for (const src of detectors) {
    const code = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');
    assert.doesNotMatch(code, forbidden, 'a detector must never write');
  }
});

// ---------------------------------------------------------------- report issue

test('Report Issue has exactly two required fields (D45)', () => {
  /**
   * The invariant three documents claim, so it had better be enforced somewhere.
   *
   * Every extra required field is a person deciding not to report the bug, and
   * the additions are always well-meant — someone reasonably wants severity, or
   * a module, or a repro step. This makes the next one fail the build rather
   * than the review.
   */
  const rules = read('src/Http/Requests/StoreReportRequest.php');

  const required = [...rules.matchAll(/'(\w+)'\s*=>\s*\[\s*'required'/g)].map((m) => m[1]);

  assert.deepEqual(required.sort(), ['description', 'title'],
    `submission must require exactly title and description, got: ${required.join(', ')}`);

  // The fields people keep proposing. Each must be optional — 'sometimes',
  // 'nullable', or absent entirely.
  for (const field of [
    'severity', 'category', 'priority', 'module',
    'screenshot', 'assignee', 'assigned_to', 'environment',
  ]) {
    const rule = rules.match(new RegExp(`'${field}'\\s*=>\\s*\\[([^\\]]*)\\]`));
    if (rule) {
      assert.doesNotMatch(rule[1], /'required'/, `${field} must never gate submission`);
    }
  }

  // And the client must not gate on more than the server does.
  const modal = read('resources/js/components/sire/ReportIssueModal.jsx');
  const gate = modal.match(/const canSubmit = ([^;]+);/);

  assert.ok(gate, 'the submit gate must be a single readable expression');
  for (const field of ['severity', 'category', 'priority', 'screenshot']) {
    assert.ok(!gate[1].includes(field), `${field} must not gate the submit button`);
  }
});
