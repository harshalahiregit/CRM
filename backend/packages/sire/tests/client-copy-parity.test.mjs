/**
 * SIRE — the package copy and the running copy must be the same file.
 *
 * WHY THIS EXISTS. SIRE is a copy-install package: every client file lives
 * twice, once at packages/sire/resources/js (what ships) and once at
 * frontend/src (what actually runs). Nothing kept them together, so they drifted
 * — and drift in this direction is the quiet kind. The app keeps working,
 * because the app runs the host copy; it is the PACKAGE that rots, and nobody
 * finds out until it is installed somewhere else and a feature is simply absent.
 *
 * That is not hypothetical. The "Send it back" upload — the whole return half of
 * the developer brief, a component, a prop and a cache invalidation — was
 * written into the host copy of DeveloperExport.jsx and DashboardPage.jsx and
 * never into the package's. Both files still parsed, both still passed every
 * other test in this suite, and the shipped package quietly could not receive a
 * brief back.
 *
 * So: byte-for-byte, ignoring line endings. The repo is checked out with
 * core.autocrlf=true and the two trees do not agree about newlines, which is a
 * checkout artefact and not drift.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative, sep } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const PKG = join(ROOT, 'resources/js');
const HOST = join(ROOT, '../../../frontend/src');

const walk = (dir) => {
  let out = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) out = out.concat(walk(full));
    else if (/\.(js|jsx)$/.test(entry.name)) out.push(full);
  }
  return out;
};

/** Newlines are a checkout artefact here, and a trailing one is not content. */
const normalise = (file) => readFileSync(file, 'utf8').replace(/\r\n/g, '\n').replace(/\s+$/, '');

test('every shipped client file has an identical twin in the running app', (t) => {
  if (!existsSync(HOST)) {
    // The package is being tested outside this CRM checkout — there is no host
    // copy to compare against, and that is not a failure.
    t.skip('no frontend/src alongside the package');
    return;
  }

  const missing = [];
  const different = [];

  for (const file of walk(PKG)) {
    const rel = relative(PKG, file);
    const twin = join(HOST, rel);

    if (!existsSync(twin)) {
      missing.push(rel.split(sep).join('/'));
      continue;
    }

    if (normalise(file) !== normalise(twin)) {
      different.push(rel.split(sep).join('/'));
    }
  }

  assert.deepEqual(
    missing,
    [],
    'shipped by the package but not installed in the app:\n  ' + missing.join('\n  '),
  );

  assert.deepEqual(
    different,
    [],
    'the package copy and the running copy have drifted apart. Whichever one was\n'
    + 'edited, copy it over the other — a difference here means the package ships\n'
    + 'something other than what this repo runs:\n  ' + different.join('\n  '),
  );
});
