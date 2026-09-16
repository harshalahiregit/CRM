/**
 * SIRE — the Integration SDK, checked against itself.
 *
 * The package's central promise is that SIRE installs into almost any Laravel
 * multi-tenant CRM by implementing a fixed set of interfaces and editing one
 * config file — never by editing SIRE. These tests are what keep that honest:
 *
 *   - every contract has a shipped implementation, so SIRE runs standalone;
 *   - every implementation implements every method, so "it's bound" cannot mean
 *     "it fatals on the third screen";
 *   - the provider binds all of them, so none is reachable only by accident;
 *   - SIRE core reaches NO host class directly, which is the rule the whole
 *     architecture rests on;
 *   - contracts exchange SIRE-owned value objects, not host models.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const CONTRACTS = join(ROOT, 'src/Contracts');
const IMPLS = join(ROOT, 'src/Adapters/Defaults');
const DTOS = join(ROOT, 'src/Dto');
const PROVIDER = join(ROOT, 'src/SireServiceProvider.php');
const CONFIG = join(ROOT, 'config/sire.php');
const STUBS = join(ROOT, 'examples/host-adapters');

const read = (p) => readFileSync(p, 'utf8');
const strip = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');
/**
 * Top-level .php only. src/Contracts/ also holds Ai/ and Extension/ — internal
 * extension points that are NOT part of the host-facing SDK, and counting them
 * would make "13 contracts" mean something different from what the docs say.
 */
const php = (dir) => readdirSync(dir, { withFileTypes: true })
  .filter((e) => e.isFile() && e.name.endsWith('.php'))
  .map((e) => e.name);

const contracts = php(CONTRACTS);
const impls = php(IMPLS);
const dtos = php(DTOS);

const methodsOf = (file) =>
  [...strip(read(file)).matchAll(/public function (\w+)\s*\(/g)].map((m) => m[1]);

/** SireTenantProvider -> SireLocalTenantProvider */
const localFor = (contract) => contract.replace(/^Sire/, 'SireLocal');

/**
 * 14 since SireCustomerProvider joined them: "which customers are hitting this"
 * is the question that turns a backlog into a priority order, and SIRE had no
 * seam to ask it through.
 *
 * The number is asserted rather than counted from the directory on purpose --
 * this test exists to fail when a contract is ADDED without a shipped
 * implementation, a host stub and a binding, which is exactly what it caught.
 */
test('the SDK exists and the scan found it (guards against a vacuous check)', () => {
  assert.equal(contracts.length, 14, `expected 14 contracts, found ${contracts.length}`);
  assert.equal(impls.length, 14, `expected 14 implementations, found ${impls.length}`);
  assert.ok(dtos.length >= 9, `expected the value objects, found ${dtos.length}`);
});

test('every contract has a shipped implementation, so SIRE runs standalone', () => {
  const missing = contracts.map(localFor).filter((f) => !impls.includes(f));
  assert.deepEqual(missing, [], `contracts with no SireLocal implementation: ${missing.join(', ')}`);
});

test('every shipped implementation implements every method its contract declares', () => {
  const gaps = [];

  for (const contract of contracts) {
    const impl = localFor(contract);
    if (!impls.includes(impl)) continue;

    const declared = methodsOf(join(CONTRACTS, contract));
    const provided = methodsOf(join(IMPLS, impl));

    assert.ok(declared.length > 0, `${contract} declares no methods — the parser is broken`);

    for (const method of declared) {
      if (!provided.includes(method)) gaps.push(`${impl} is missing ${method}()`);
    }
  }

  assert.deepEqual(gaps, [], `\n${gaps.join('\n')}\n`);
});

test('every host stub implements every method too', () => {
  /**
   * A stub that silently omits a method is worse than a missing stub: it looks
   * finished, binds cleanly, and fatals the first time that method is reached.
   */
  const gaps = [];

  for (const contract of contracts) {
    const stub = contract.replace(/^Sire/, 'Host');
    if (!php(STUBS).includes(stub)) {
      gaps.push(`no host stub for ${contract}`);
      continue;
    }

    const declared = methodsOf(join(CONTRACTS, contract));
    const provided = methodsOf(join(STUBS, stub));

    for (const method of declared) {
      if (!provided.includes(method)) gaps.push(`${stub} is missing ${method}()`);
    }
  }

  assert.deepEqual(gaps, [], `\n${gaps.join('\n')}\n`);
});

test('the service provider binds every contract', () => {
  const provider = read(PROVIDER);
  const unbound = contracts
    .map((f) => f.replace('.php', ''))
    .filter((name) => !provider.includes(`Contract\\${name}::class`));

  assert.deepEqual(unbound, [], `contracts the provider never binds: ${unbound.join(', ')}`);
});

test('config names an implementation for every binding the provider expects', () => {
  const keys = [...read(PROVIDER).matchAll(/Contract\\\w+::class\s*=>\s*'(\w+)'/g)].map((m) => m[1]);
  const config = read(CONFIG);

  assert.equal(keys.length, 14, `parsed ${keys.length} binding keys from the provider`);

  const missing = keys.filter((key) => !new RegExp(`'${key}'\\s*=>`).test(config));
  assert.deepEqual(missing, [], `config/sire.php has no entry for: ${missing.join(', ')}`);
});

test('SIRE core reaches no host class directly', () => {
  /**
   * The rule the whole architecture rests on. There is now exactly ONE
   * permitted host symbol — the framework's base Controller — because SIRE owns
   * its tenancy, audit, notes, settings, numbering, identity and API envelope.
   *
   * App\Models\User is deliberately NOT on this list any more: SIRE core sees
   * SireUserIdentity and nothing else.
   */
  const ALLOWED = ['Sire\\Http\\Controllers\\SireController'];
  const violations = [];

  const walk = (dir) => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = join(dir, entry.name);
      if (entry.isDirectory()) walk(full);
      else if (entry.name.endsWith('.php')) {
        for (const m of read(full).matchAll(/^use (App\\[\w\\]+);/gm)) {
          const symbol = m[1];
          if (symbol.includes('\\Sire') || ALLOWED.includes(symbol)) continue;
          violations.push(`${entry.name} imports ${symbol}`);
        }
      }
    }
  };

  walk(join(ROOT, 'src'));

  assert.deepEqual(violations, [], `\n${violations.join('\n')}\n`);
});

test('the host-import check is not vacuous — it would catch a real one', () => {
  const fake = 'use App\\Services\\Notifications\\NotificationEngine;';
  const symbol = fake.match(/^use (App\\[\w\\]+);/)[1];

  assert.ok(!symbol.includes('\\Sire'), 'the detector would have skipped a genuine host import');
  assert.ok(!['Sire\\Http\\Controllers\\SireController'].includes(symbol), 'the allowlist would have swallowed it');
});

test('contracts exchange SIRE value objects, never host models', () => {
  /**
   * The other half of independence. Binding to interfaces means nothing if those
   * interfaces pass `\App\Models\User` across the boundary — the host model is
   * still in SIRE's signatures, and everything it carries is still reachable.
   */
  const leaks = [];

  for (const contract of contracts) {
    const src = strip(read(join(CONTRACTS, contract)));
    for (const m of src.matchAll(/\\?App\\Models\\(\w+)/g)) {
      leaks.push(`${contract} references App\\Models\\${m[1]}`);
    }
  }

  assert.deepEqual(leaks, [], `\n${leaks.join('\n')}\n`);
});

test('the tenant contract cannot be satisfied by guessing', () => {
  /**
   * The one failure in SIRE that is silent rather than loud. Every other
   * provider throws or logs when it is wrong; a tenant provider that returns a
   * fallback returns another tenant's data and looks entirely normal doing it.
   */
  const contract = read(join(CONTRACTS, 'SireTenantProvider.php'));
  const impl = read(join(IMPLS, 'SireLocalTenantProvider.php'));

  assert.match(contract, /currentTenant\(\): SireTenantIdentity/, 'the return must not be nullable');
  assert.doesNotMatch(contract, /currentTenant\(\): \?/, 'a nullable tenant invites a fallback');

  assert.match(impl, /throw new RuntimeException/, 'it must throw when no tenant is in scope');
  assert.match(impl, /abort\(404\)/, 'ownership failures must 404, never 403');
  assert.doesNotMatch(
    strip(impl),
    /return\s+0\s*;|\?\?\s*0\s*;|'default'/,
    'no fallback tenant may appear in the tenant provider',
  );
});

test('the audit contract has no way to rewrite history', () => {
  /**
   * Release approvals and emergency overrides are defended by this trail. The
   * absence of update() and delete() IS the feature, so it is asserted rather
   * than trusted to survive the next person who finds it inconvenient.
   */
  const methods = methodsOf(join(CONTRACTS, 'SireAuditProvider.php'));

  assert.deepEqual(methods.sort(), ['for', 'record', 'recordMany']);

  const model = read(join(ROOT, 'src/Models/AuditEvent.php'));
  assert.match(model, /static::updating/, 'the model must refuse updates');
  assert.match(model, /static::deleting/, 'the model must refuse deletes');
});

test('no contract or shipped implementation reaches the AI layer', () => {
  const leaks = [];

  for (const file of [...contracts.map((f) => join(CONTRACTS, f)), ...impls.map((f) => join(IMPLS, f))]) {
    if (/\\Ai\\|Ai[A-Z]/.test(strip(read(file)))) leaks.push(file.split('/').pop());
  }

  assert.deepEqual(leaks, [], `SDK files referencing the AI layer: ${leaks.join(', ')}`);
});
