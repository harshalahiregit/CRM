/**
 * SIRE — docs/API.md must match the route file.
 *
 * A hand-maintained endpoint list is wrong within a fortnight, and a wrong one
 * is worse than none: a developer trusts it, calls something that no longer
 * exists, and blames their own wiring. So the reference is generated, and this
 * test fails the build when the committed file and the routes disagree.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const DOC = readFileSync(join(ROOT, 'docs/API.md'), 'utf8');
const ROUTES = readFileSync(join(ROOT, 'routes/sire.php'), 'utf8');

test('the generated API reference is in sync with the route file', () => {
  execFileSync('node', [join(ROOT, 'tools/generate-api-docs.mjs'), '--check'], { cwd: ROOT });
});

test('every route appears in the reference', () => {
  const missing = [];

  for (const m of ROUTES.matchAll(
    /Route::(\w+)\(\s*'([^']+)'\s*,\s*(?:\[\w+::class,\s*'\w+'\]|\w+Controller::class)/g,
  )) {
    const row = `| ${m[1].toUpperCase()} | \`/api/sire/${m[2]}\` |`;
    if (!DOC.includes(row)) missing.push(`${m[1].toUpperCase()} ${m[2]}`);
  }

  assert.deepEqual(missing, [], `\n${missing.join('\n')}\n`);
});

test('the stated endpoint count matches the tables', () => {
  const claimed = Number((DOC.match(/\*\*(\d+) endpoints\*\*/) || [, 0])[1]);
  const rows = (DOC.match(/^\| (GET|POST|PUT|PATCH|DELETE) \| `/gm) || []).length;

  assert.ok(claimed > 0, 'the reference states no endpoint count');
  assert.equal(rows, claimed, `header claims ${claimed}, tables list ${rows}`);
});

test('the hand-written narrative survived generation', () => {
  /**
   * The generator rewrites the tables and re-emits tools/api-narrative.md
   * underneath. If a future change to the generator drops that concatenation,
   * the conventions and request shapes would vanish silently — the file would
   * still look complete, just thinner.
   */
  assert.match(DOC, /## Conventions/);
  assert.match(DOC, /## Shapes worth knowing/);
  assert.match(DOC, /tenant_id` and `reporter_id` are \*\*never\*\* accepted/);
});
