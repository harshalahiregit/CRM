/**
 * SIRE — every capability the workflow asks about must be declared.
 *
 * WHY THIS TEST EXISTS
 *
 * SireAccessService::can() ends in `default => false`. An undeclared capability
 * is therefore not an error — it is a silent denial, and a silent denial looks
 * exactly like a deliberate one.
 *
 * Three capabilities were undeclared for the entire life of the package:
 * sire.change.review, sire.change.approve and sire.release.approve. The
 * consequence was that the whole change-approval track and release approval were
 * admin-only by accident. Nobody could see it, because the UI simply did not
 * offer the buttons, which is indistinguishable from "you are not a lead".
 *
 * A vocabulary that can drift from its own workflow is not a vocabulary.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => readFileSync(join(ROOT, p), 'utf8');
const strip = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');

const VOCAB = strip(read('src/Support/SireCapability.php'));
const WORKFLOW = strip(read('src/Support/SireWorkflow.php'));
const RELEASES = strip(read('src/Support/SireReleaseStatus.php'));
const ACCESS = strip(read('src/Services/SireAccessService.php'));

/**
 * EVERY SIRE file, not just the access service.
 *
 * An earlier version of this test read only SireAccessService, and passed while
 * six enforced capabilities were undeclared — sire.release.override,
 * sire.rca.confirm, sire.capa.verify and the two release-note ones among them.
 * They are asserted in the SERVICES that own those features, which the test was
 * not looking at. A check scoped more narrowly than the thing it checks is not
 * a check.
 */
const walk = (dir) =>
  readdirSync(join(ROOT, dir), { withFileTypes: true })
    .flatMap((e) => (e.isDirectory() ? walk(join(dir, e.name)) : [join(dir, e.name)]))
    .filter((f) => f.endsWith('.php'));

const ALL_SOURCE = walk('src').map((f) => strip(read(f))).join('\n');

const declared = new Set([...VOCAB.matchAll(/public const [A-Z_]+\s*=\s*'(sire\.[a-z._]+)'/g)].map((m) => m[1]));
const coarse = new Set([...VOCAB.matchAll(/'(sire\.[a-z_]+)'\s*=>\s*\[/g)].map((m) => m[1]));

test('the vocabulary parsed (guards against a vacuous check)', () => {
  assert.ok(declared.size >= 14, `parsed only ${declared.size} canonical capabilities`);
  assert.ok(coarse.size >= 8, `parsed only ${coarse.size} coarse grants`);
});

test('every capability a workflow transition names is declared', () => {
  const used = new Set(
    [...WORKFLOW.matchAll(/'capability'\s*=>\s*'(sire\.[a-z._]+)'/g)].map((m) => m[1]),
  );

  assert.ok(used.size > 0, 'parsed no capabilities from the workflow');

  const undeclared = [...used].filter((c) => !declared.has(c));
  assert.deepEqual(undeclared, [], `workflow transitions name undeclared capabilities: ${undeclared.join(', ')}`);
});

test('every capability a release transition names is declared', () => {
  const used = new Set(
    [...RELEASES.matchAll(/'capability'\s*=>\s*'(sire\.[a-z._]+)'/g)].map((m) => m[1]),
  );

  assert.ok(used.size > 0, 'parsed no capabilities from the release lifecycle');

  const undeclared = [...used].filter((c) => !declared.has(c));
  assert.deepEqual(undeclared, [], `release transitions name undeclared capabilities: ${undeclared.join(', ')}`);
});

test('every capability enforced ANYWHERE in SIRE is declared', () => {
  /**
   * The check that matters. can() and assert() end in a denial, so an
   * undeclared capability is not an error — it is a silent, permanent 403 that
   * looks exactly like a deliberate one.
   */
  const enforced = new Set(
    [...ALL_SOURCE.matchAll(/access->(?:can|assert)\([^;]*?'(sire\.[a-z._]+)'/g)].map((m) => m[1]),
  );

  // 11 today. The rest are enforced through the workflow and release maps'
  // 'capability' entries, which the two tests above cover — between them the
  // three checks see every enforcement path in SIRE.
  assert.ok(enforced.size >= 10, `parsed only ${enforced.size} enforcement sites`);

  const undeclared = [...enforced].filter((c) => !declared.has(c) && !coarse.has(c));
  assert.deepEqual(undeclared, [], `enforced but undeclared: ${undeclared.join(', ')}`);
});

test('emergency override is not granted by any coarse role but sire.manage', () => {
  /**
   * Approving a release and overriding its gates are different acts. Approval
   * says the gates passed; an override says they did not and we are shipping
   * anyway. If `sire.release` granted both, "we override sometimes" quietly
   * becomes "we override by default".
   */
  for (const [, grant] of VOCAB.matchAll(/'(sire\.(?!manage)[a-z_]+)'\s*=>\s*\[([^\]]*)\]/g)) {
    assert.ok(
      !grant.includes('RELEASE_OVERRIDE'),
      'only sire.manage may grant RELEASE_OVERRIDE',
    );
  }

  assert.doesNotMatch(
    ACCESS,
    /LEAD = \[[\s\S]*?RELEASE_OVERRIDE[\s\S]*?\];/,
    'leads must not hold the override capability by default',
  );
});

test('every coarse grant expands only to canonical capabilities', () => {
  /**
   * A coarse grant naming a capability that does not exist grants nothing, and
   * grants it silently — the same failure this file exists to prevent, one level
   * up.
   */
  const bad = [];

  for (const m of VOCAB.matchAll(/'(sire\.[a-z_]+)'\s*=>\s*\[([^\]]*)\]/g)) {
    if (m[2].includes('self::CANONICAL')) continue;
    for (const ref of m[2].matchAll(/self::([A-Z_]+)/g)) {
      if (!new RegExp(`public const ${ref[1]}\\s*=`).test(VOCAB)) {
        bad.push(`${m[1]} references undefined ${ref[1]}`);
      }
    }
  }

  assert.deepEqual(bad, [], `\n${bad.join('\n')}\n`);
});

test('leads can approve changes and releases', () => {
  /**
   * The specific regression. Restricting change review, change approval and
   * release approval to administrators means an admin must be fetched every time
   * a change request moves — which in practice means the track goes unused.
   */
  for (const capability of ['CHANGE_REVIEW', 'CHANGE_APPROVE', 'RELEASE_APPROVE']) {
    assert.match(
      ACCESS,
      new RegExp(`LEAD = \\[[\\s\\S]*?SireCapability::${capability}[\\s\\S]*?\\];`),
      `leads must hold ${capability}`,
    );
  }
});

test('the capability list and the access service cannot drift apart', () => {
  // One source: the service reads the SDK vocabulary rather than repeating it.
  assert.match(ACCESS, /const CAPABILITIES = SireCapability::CANONICAL;/);
});
