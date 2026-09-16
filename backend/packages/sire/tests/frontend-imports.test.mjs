/**
 * SIRE — every relative import inside the SIRE frontend must resolve.
 *
 * The package ships source files, not a built bundle, so nothing in this repo
 * would otherwise notice a typo in an import path or a component that was
 * renamed without its callers. The developer would find out at `npm run build`,
 * in someone else's codebase, with SIRE to blame.
 *
 * Imports that leave SIRE are checked differently. SIRE used to import nine host
 * files directly — a UI kit, a toast hook, an auth context, an axios instance —
 * and this test enumerated them, so that a tenth dependency showed up as a new
 * integration requirement.
 *
 * The host bridge removed all nine, so the assertion inverted: SIRE must now
 * reach for NONE of them, and must ship a fallback for everything it renders.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, existsSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SRC = join(ROOT, 'resources/js');

const walk = (dir) => {
  let out = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) out = out.concat(walk(full));
    else if (/\.(js|jsx)$/.test(entry.name)) out.push(full);
  }
  return out;
};

/** Only SIRE's own files — the CRM files around them are not in this package. */
const files = walk(SRC).filter((f) => /[\\/]sire[\\/]|Sire[A-Z]|sireApi/.test(f));

const importsIn = (file) => {
  const src = readFileSync(file, 'utf8');
  return [...src.matchAll(/^\s*import\s+(?:[\s\S]*?\s+from\s+)?['"]([^'"]+)['"]/gm)].map((m) => m[1]);
};

const resolves = (from, spec) => {
  const base = resolve(dirname(from), spec);
  for (const candidate of [base, `${base}.js`, `${base}.jsx`, join(base, 'index.js'), join(base, 'index.jsx')]) {
    if (existsSync(candidate) && statSync(candidate).isFile()) return true;
  }
  return false;
};

test('the scan found the SIRE frontend (guards against a vacuous check)', () => {
  assert.ok(files.length >= 40, `scanned only ${files.length} files`);
  const totalImports = files.reduce((n, f) => n + importsIn(f).length, 0);
  assert.ok(totalImports >= 100, `parsed only ${totalImports} imports`);
});

test('every relative import inside SIRE resolves to a file in the package', () => {
  const broken = [];

  for (const file of files) {
    for (const spec of importsIn(file)) {
      if (!spec.startsWith('.')) continue;

      // Files belonging to the CRM, not to SIRE: they are not shipped in this
      // package, so they cannot be resolved here. The enumeration test below is
      // what guards them instead.
      if (/\/(ui|hooks|context\/Auth|lib\/api)/.test(spec) && !/sire/i.test(spec)) continue;

      if (!resolves(file, spec)) {
        broken.push(`${file.replace(ROOT + '/', '')} → ${spec}`);
      }
    }
  }

  assert.deepEqual(broken, [], `\n${broken.join('\n')}\n`);
});

test('SIRE requires NOTHING from the host frontend — everything goes through the bridge', () => {
  /**
   * This test used to enumerate nine host files SIRE imported directly:
   * six UI components, a toast hook, an auth context and an axios instance.
   * Each was a hard requirement that a host own a file with that exact name at
   * that exact depth, and a CRM using MUI, or Chakra, or its own kit, could not
   * mount a single SIRE screen without editing SIRE.
   *
   * The bridge replaced all nine. SIRE now imports from lib/sire/host, which
   * resolves to whatever the host registered and falls back to SIRE's own
   * components when it registered nothing.
   *
   * So the assertion inverted: instead of "these nine files must exist", it is
   * now "SIRE reaches for none of them".
   */
  const reaching = [];

  for (const file of files) {
    if (file.includes('/lib/sire/host/')) continue;   // the bridge documents what it replaced

    for (const spec of importsIn(file)) {
      if (!spec.startsWith('.')) continue;
      if (/sire/i.test(spec)) continue;

      // A relative import that names a host-shaped file is exactly what the
      // bridge exists to remove.
      if (/\/(ui|hooks|context|lib)\//.test(spec)) {
        reaching.push(`${file.replace(ROOT + '/', '')} → ${spec}`);
      }
    }
  }

  assert.deepEqual(reaching, [], `\n${reaching.join('\n')}\n`);
});

test('the bridge offers a fallback for every component SIRE renders', () => {
  /**
   * The other half of the promise. Requiring nothing from the host is only
   * useful if SIRE can still draw itself — otherwise "no dependencies" means
   * "no interface".
   *
   * So every component name SIRE asks the bridge for must have a shipped
   * fallback. A missing one would render as `Unstyled`, which silently drops
   * every prop and looks like an empty div.
   */
  const asked = new Set();

  for (const file of files) {
    for (const m of readFileSync(file, 'utf8').matchAll(/sireHostUi\('(\w+)'\)/g)) {
      asked.add(m[1]);
    }
  }

  assert.ok(asked.size >= 5, `parsed only ${asked.size} bridge component requests`);

  const fallbacks = readFileSync(join(ROOT, 'resources/js/lib/sire/host/defaultUi.jsx'), 'utf8');
  const missing = [...asked].filter((name) => !new RegExp(`export const ${name}\\b`).test(fallbacks));

  assert.deepEqual(missing, [], `bridge components with no fallback: ${missing.join(', ')}`);
});

test('every page component is reachable from a documented route', () => {
  /**
   * A page nobody routes to is dead code that still has to be reviewed and
   * maintained. This caught MyWorkPage, ReleaseDetailPage and
   * RecurrenceDetailPage being absent from the install guide's route table
   * while other components linked straight at them.
   */
  const pages = readdirSync(join(SRC, 'modules/sire/pages')).map((f) => f.replace(/\.jsx?$/, ''));
  const install = readFileSync(join(ROOT, 'docs/INSTALLATION.md'), 'utf8');

  const unrouted = pages.filter((page) => !install.includes(page));

  assert.deepEqual(unrouted, [], `pages with no documented route: ${unrouted.join(', ')}`);
});

test('every internal SIRE link points at a documented route', () => {
  /**
   * The other direction. A <Link to="/app/sire/releases/7"> with no route behind
   * it renders a blank page rather than erroring, so nothing catches it until a
   * user clicks.
   */
  const install = readFileSync(join(ROOT, 'docs/INSTALLATION.md'), 'utf8');
  const documented = new Set(
    [...install.matchAll(/path:\s*'([^']+)'/g)].map((m) => m[1].replace(/\/:\w+$/, '')),
  );

  const dangling = new Set();

  for (const file of files) {
    for (const m of readFileSync(file, 'utf8').matchAll(/to=\{?[`'"]\/app\/(sire[^`'"$}]*)/g)) {
      // Trim the interpolated id segment: /app/sire/cases/${id} -> sire/cases
      const path = m[1].replace(/\/$/, '');
      if (!documented.has(path)) dangling.add(`${path} (in ${file.split('/').pop()})`);
    }
  }

  assert.deepEqual([...dangling], [], `\n${[...dangling].join('\n')}\n`);
});
