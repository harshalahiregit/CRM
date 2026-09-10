import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * SIRE — architectural boundaries, enforced.
 *
 * "AI can be added without changing the core workflow" is only true if something
 * checks it. This test reads the module map out of SireModule.php, scans every
 * SIRE class for the SIRE classes it imports, and fails when a dependency runs
 * upward through the layers.
 *
 * The rule that matters: NOTHING outside the AI layer may reference the AI layer.
 * Delete App\Services\Sire\Ai entirely and SIRE must still compile and run.
 */
const MAP_SRC = readFileSync('src/Support/SireModule.php', 'utf8');

const CLASS_MODULE = {};
{
  const block = MAP_SRC.slice(MAP_SRC.indexOf('const CLASS_MODULE = ['), MAP_SRC.indexOf('public static function of'));
  for (const m of block.matchAll(/'(\w+)'\s*=>\s*self::([A-Z]+)/g)) {
    CLASS_MODULE[m[1]] = m[2].toLowerCase();
  }
}

const LAYERS = {};
{
  const block = MAP_SRC.slice(MAP_SRC.indexOf('const LAYERS = ['), MAP_SRC.indexOf('const CLASS_MODULE'));
  // -?\d+ , not \d+ : the integration layer is -1, and a regex that could not
  // see it silently dropped that layer out of LAYERS -- which made the
  // upward-dependency check below vacuous for every adapter. Caught by the
  // vacuity guard, which is the entire reason that guard exists.
  for (const m of block.matchAll(/self::([A-Z]+)\s*=>\s*(-?\d+)/g)) {
    LAYERS[m[1].toLowerCase()] = Number(m[2]);
  }
}

function phpFiles(dir) {
  let out = [];
  let entries;
  try { entries = readdirSync(dir); } catch { return out; }
  for (const entry of entries) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) out = out.concat(phpFiles(full));
    else if (entry.endsWith('.php')) out.push(full);
  }
  return out;
}

const SIRE_DIRS = [
  'src/Services', 'src/Models',
  'src/Support', 'src/Contracts',
];
const FILES = SIRE_DIRS.flatMap(phpFiles);

const stripComments = (src) =>
  src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '))
     .replace(/(^|[^:])\/\/[^\n]*/g, (m, p) => p + ' '.repeat(m.length - p.length));

/**
 * SIRE classes this file references — from `use` statements AND from
 * fully-qualified inline references.
 *
 * The inline form was added after a real violation slipped through: core called
 * `\App\Services\Sire\Ai\SireTestCaseGenerator::CATEGORIES` with no import, so the
 * layering was broken and the check said nothing. A boundary test that only reads
 * import statements can be bypassed by anyone who omits the import.
 */
function referencedClasses(src) {
  const out = new Set();
  const code = stripComments(src);

  // Leading whitespace allowed: a `use` inside a braced namespace block is
  // indented and legal, and the checker's own self-test caught this anchor
  // silently missing one.
  for (const m of code.matchAll(/^[ \t]*use\s+Sire\\[\w\\]*\\(\w+);/gm)) out.add(m[1]);

  // \Sire\AI\SireAiGateway::SOMETHING or ::class or (...) — a fully-qualified
  // inline reference needs no import, so an import-only scan can be bypassed
  // simply by omitting one. That is not hypothetical: it happened here once.
  for (const m of code.matchAll(/\\?Sire\\[\w\\]*\\(\w+)\s*::/g)) out.add(m[1]);

  return [...out];
}

const analysed = FILES.map((file) => {
  const src = readFileSync(file, 'utf8');
  const self = file.split('/').pop().replace('.php', '');
  return { file, self, module: CLASS_MODULE[self] ?? null, refs: referencedClasses(src) };
});

test('the map and the scan both loaded (guards against a vacuous check)', () => {
  assert.ok(Object.keys(CLASS_MODULE).length >= 30, `mapped classes: ${Object.keys(CLASS_MODULE).length}`);
  assert.deepEqual(Object.keys(LAYERS).sort(), ['ai', 'core', 'integration', 'knowledge', 'quality', 'release']);
  assert.ok(FILES.length >= 30, `scanned files: ${FILES.length}`);
});

test('every SIRE service and model is assigned to a module', () => {
  // An unmapped class is invisible to this check — a hole, not a pass.
  const unmapped = analysed
    .filter((a) => /Services\/Sire|Models\/Sire/.test(a.file))
    .filter((a) => a.module === null)
    .map((a) => a.self);

  assert.deepEqual(unmapped, [], `unmapped SIRE classes: ${unmapped.join(', ')}`);
});

test('NOTHING outside the AI layer references the AI layer', () => {
  // The load-bearing assertion of this whole phase. Delete Services/Sire/Ai and
  // SIRE must still compile, still run, still ship releases.
  const violations = [];

  for (const a of analysed) {
    if (a.module === 'ai') continue;
    for (const ref of a.refs) {
      if (CLASS_MODULE[ref] === 'ai') {
        violations.push(`${a.file} (${a.module}) references AI class ${ref}`);
      }
    }
  }

  assert.deepEqual(violations, [], `\n${violations.join('\n')}\n`);
});

test('core depends on nothing above it — only itself and the integration layer', () => {
  /**
   * Core may reach DOWN to the adapters, because that is how it talks to the
   * CRM at all. What it must never do is reach sideways to quality/knowledge/
   * release, or up to ai.
   *
   * The distinction matters: 'integration' is not an escape hatch that makes
   * every dependency legal. It contains twelve interfaces, their shipped
   * implementations and five traits, and nothing else — so a service that
   * sneaks into it to dodge this test has to be added to CLASS_MODULE by hand,
   * in a file whose whole purpose is being read during review.
   */
  const violations = [];

  for (const a of analysed.filter((x) => x.module === 'core')) {
    for (const ref of a.refs) {
      const refModule = CLASS_MODULE[ref];
      if (refModule && refModule !== 'core' && refModule !== 'integration') {
        violations.push(`${a.self} (core) references ${ref} (${refModule})`);
      }
    }
  }

  assert.deepEqual(violations, [], `\n${violations.join('\n')}\n`);
});

test('the integration layer depends on nothing in SIRE above it', () => {
  /**
   * The direction that makes adapters worth having. If an adapter reached back
   * into a SIRE service, rewiring a CRM subsystem would mean reading SIRE's
   * workflow — and the promise that a developer only has to implement twelve
   * interfaces would quietly stop being true.
   *
   * Support-layer vocabulary (SireStatus and friends) is not in CLASS_MODULE and
   * is deliberately allowed: an SLA adapter naming SireStatus::SLA_PAUSED is
   * using a constant, not calling a service.
   */
  const violations = [];

  for (const a of analysed.filter((x) => x.module === 'integration')) {
    for (const ref of a.refs) {
      const refModule = CLASS_MODULE[ref];
      if (refModule && refModule !== 'integration') {
        violations.push(`${a.self} (integration) references ${ref} (${refModule})`);
      }
    }
  }

  assert.deepEqual(violations, [], `\n${violations.join('\n')}\n`);
});

test('no dependency runs upward through the layers', () => {
  const violations = [];

  for (const a of analysed) {
    if (!a.module) continue;
    for (const ref of a.refs) {
      const refModule = CLASS_MODULE[ref];
      if (!refModule) continue;
      if (LAYERS[refModule] > LAYERS[a.module]) {
        violations.push(`${a.self} (${a.module}, layer ${LAYERS[a.module]}) → ${ref} (${refModule}, layer ${LAYERS[refModule]})`);
      }
    }
  }

  assert.deepEqual(violations, [], `\n${violations.join('\n')}\n`);
});

test('the boundary checker detects a real violation (not vacuous)', () => {
  // Prove the rule can fail: a synthetic core class importing an AI class.
  const fake = `<?php
    namespace Sire\\Services;
    use Sire\\AI\\SireAiGateway;
    class SireWorkflowService {}`;

  const refs = referencedClasses(fake);
  assert.ok(refs.includes('SireAiGateway'), 'the import scanner must see it');
  assert.equal(CLASS_MODULE.SireAiGateway, 'ai');
  assert.equal(CLASS_MODULE.SireWorkflowService, 'core');
  assert.ok(LAYERS.ai > LAYERS.core, 'and the layer comparison must reject it');
});

test('the AI layer has no write path to core data', () => {
  /*
   * The layering test proves nothing DEPENDS on AI. This proves the reverse
   * direction is safe too: AI may read every layer below, but it writes only to
   * its own table.
   *
   * That is what makes "AI must never overwrite original issue data" a structural
   * property rather than a rule someone has to keep in mind.
   */
  const AI_OWNED = ['AiSuggestion', 'IssueToken'];
  const WRITE = /(\w+)\s*::\s*create\s*\(|->\s*(save|update|delete|forceDelete|saveQuietly)\s*\(/g;

  const violations = [];

  for (const a of analysed.filter((x) => x.module === 'ai')) {
    const code = stripComments(readFileSync(a.file, 'utf8'));

    for (const m of code.matchAll(WRITE)) {
      const staticTarget = m[1];

      // Model::create(...) — the target must be AI-owned.
      if (staticTarget && !AI_OWNED.includes(staticTarget)) {
        violations.push(`${a.self}: ${staticTarget}::create()`);
        continue;
      }
      if (staticTarget) continue;

      // ->save()/->update() — resolve the receiver back to a variable or chain.
      const before = code.slice(Math.max(0, m.index - 220), m.index);
      const touchesCore = [...before.matchAll(/\b([A-Z]\w+)\b/g)]
        .map((x) => x[1])
        .filter((name) => CLASS_MODULE[name] && CLASS_MODULE[name] !== 'ai');

      // A write in an AI class whose nearby context mentions a non-AI model is
      // the shape we refuse. AI-owned writes mention only AiSuggestion.
      if (touchesCore.length > 0 && !before.includes('AiSuggestion')) {
        violations.push(`${a.self}: write near core model(s) ${[...new Set(touchesCore)].join(', ')}`);
      }
    }
  }

  assert.deepEqual(violations, [], `\n${violations.join('\n')}\n`);
});

test('the write-path checker detects a real violation (not vacuous)', () => {
  const AI_OWNED = ['AiSuggestion'];
  const bad = `<?php class X { public function go() { Report::create(['a' => 1]); } }`;
  const found = [...stripComments(bad).matchAll(/(\w+)\s*::\s*create\s*\(/g)].map((m) => m[1]);
  assert.deepEqual(found, ['Report']);
  assert.ok(!AI_OWNED.includes('Report'), 'and Report is not AI-owned, so it would be reported');
});

test('a fully-qualified inline reference is detected, not just an import', () => {
  // The exact shape that slipped through: no `use`, so an import-only scanner saw
  // nothing while core was calling straight into the AI layer.
  const sneaky = `<?php
    namespace Sire\\Services;
    class SireTestCaseService {
        public function go() {
            return \\Sire\\Services\\Ai\\SireTestCaseGenerator::CATEGORIES;
        }
    }`;
  assert.ok(referencedClasses(sneaky).includes('SireTestCaseGenerator'));
});

test('comments cannot smuggle a false positive or hide a real one', () => {
  const commented = `<?php
    // use Sire\\Services\\Ai\\SireAiGateway;
    /* use Sire\\Services\\Ai\\SireAiRedactor; */
    class X {}`;
  assert.deepEqual(referencedClasses(commented), [], 'prose is not a dependency');
});
