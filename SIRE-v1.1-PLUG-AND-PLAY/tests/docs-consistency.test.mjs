/**
 * SIRE — the documentation must agree with the code.
 *
 * Not a style check. Each of these guards a specific way the package can become
 * misleading while still looking complete:
 *
 *   - a table added by a migration but never described, so nobody knows what it
 *     is for;
 *   - an AI capability implemented but absent from the catalogue that is
 *     supposed to be exhaustive;
 *   - a class named in a document that no longer exists, which sends a reader
 *     hunting through a codebase for a file that was renamed.
 *
 * Documentation that is merely stale is a nuisance. Documentation that is stale
 * and authoritative-looking is worse than none, because it is believed.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => readFileSync(join(ROOT, p), 'utf8');
const strip = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');

const walk = (dir) =>
  readdirSync(join(ROOT, dir), { withFileTypes: true })
    .flatMap((e) => (e.isDirectory() ? walk(join(dir, e.name)) : [join(dir, e.name)]));

const allFiles = walk('.');
const docs = allFiles.filter((f) => f.endsWith('.md'));

test('the scan found the package (guards against a vacuous check)', () => {
  assert.ok(docs.length >= 25, `found only ${docs.length} markdown files`);
});

test('every table a migration creates is described in DATABASE.md', () => {
  const dbDoc = read('docs/DATABASE.md');
  const tables = new Set();

  for (const file of readdirSync(join(ROOT, 'database/migrations'))) {
    for (const m of read(`database/migrations/${file}`).matchAll(/Schema::create\('(\w+)'/g)) {
      tables.add(m[1]);
    }
  }

  assert.ok(tables.size >= 15, `parsed only ${tables.size} tables`);

  const undocumented = [...tables].filter((t) => !dbDoc.includes(t));
  assert.deepEqual(undocumented, [], `tables with no entry in DATABASE.md: ${undocumented.join(', ')}`);
});

test('DATABASE.md states the real migration count', () => {
  const actual = readdirSync(join(ROOT, 'database/migrations')).length;
  const claimed = Number((read('docs/DATABASE.md').match(/(\d+)\s+migrations/) || [, 0])[1]);

  assert.equal(claimed, actual);
});

test('the AI catalogue lists every declared capability, and claims the right number', () => {
  const src = strip(read('src/Support/Ai/AiCapability.php'));

  // SUBJECT_* is the subject vocabulary; ALL and CATALOGUE are arrays over the rest.
  const declared = (src.match(/public const (?!SUBJECT_|ALL|CATALOGUE)[A-Z_]+\s*=\s*'/g) || []).length;

  const doc = read('ai/AI-CAPABILITIES.md');
  const rows = (doc.match(/^\| [A-Z][^|]*\|/gm) || []).length - 1;   // minus the header
  const claimed = Number((doc.match(/\*\*(\d+) declared capabilities/) || [, 0])[1]);

  assert.ok(declared >= 10, `parsed only ${declared} capabilities`);
  assert.equal(rows, declared, `${declared} capabilities declared, ${rows} tabulated`);
  assert.equal(claimed, declared, `catalogue header claims ${claimed}, code declares ${declared}`);
});

test('every SIRE class named in a document exists in the package', () => {
  const classes = new Set(
    allFiles.filter((f) => f.endsWith('.php')).map((f) => f.split('/').pop().replace('.php', '')),
  );

  const missing = new Set();

  for (const doc of docs) {
    for (const m of read(doc).matchAll(/`(?:App\\[\w\\]*\\)?((?:Sire|Local|Crm)[A-Z]\w+)(?:::\w+\(?\)?)?`/g)) {
      if (!classes.has(m[1])) missing.add(`${doc} → ${m[1]}`);
    }
  }

  assert.deepEqual([...missing], [], `\n${[...missing].join('\n')}\n`);
});

test('no document promises a file that is not in the package', () => {
  const present = new Set(allFiles.map((f) => f.replace(/^\.\//, '')));
  const broken = [];

  for (const doc of docs) {
    // Anchors are stripped, not skipped. An earlier version of this regex
    // required the link to end at the extension, so `](08-spec.md#section)`
    // matched nothing at all — and a link to a file that had been removed from
    // the package sat there passing for weeks.
    for (const m of read(doc).matchAll(/\]\(([^)\s]+\.(?:md|php|jsx?|mjs|json))(?:#[^)\s]*)?\)/g)) {
      if (/^[a-z]+:\/\//.test(m[1])) continue;   // external URLs are not our business

      const target = join(dirname(doc), m[1]).replace(/^\.\//, '').replace(/\/$/, '');

      if (present.has(target)) continue;

      // Directories are legitimate link targets and never appear in a file list.
      const isDir = allFiles.some((f) => f.startsWith(`${target}/`));
      if (!isDir) broken.push(`${doc} → ${m[1]}`);
    }
  }

  assert.deepEqual(broken, [], `\n${broken.join('\n')}\n`);
});

test('the package contains no credentials, junk or OS files', () => {
  const junk = allFiles.filter((f) =>
    /\.DS_Store|node_modules|\/vendor\/|\.env$|\.log$|~$|\.swp$|\.orig$|\.bak$/.test(f),
  );
  assert.deepEqual(junk, [], `junk files: ${junk.join(', ')}`);

  const patterns = [
    [/\b(sk|pk)[-_](live|test)[-_][A-Za-z0-9]{8,}/, 'vendor API key'],
    [/\bAKIA[0-9A-Z]{16}\b/, 'AWS access key'],
    [/-----BEGIN [A-Z ]*PRIVATE KEY-----/, 'private key'],
  ];

  const leaks = [];
  for (const file of allFiles.filter((f) => /\.(php|js|jsx|mjs|json|md)$/.test(f))) {
    // The redaction code necessarily contains patterns that LOOK like secrets.
    if (/Redact|redact|AiContextSchema/.test(file)) continue;
    const src = read(file);
    for (const [re, what] of patterns) if (re.test(src)) leaks.push(`${file}: ${what}`);
  }

  assert.deepEqual(leaks, [], `\n${leaks.join('\n')}\n`);
});

test('no PHP file closes PHP mode from inside a comment', () => {
  /**
   * `?>` ends PHP parsing even inside a `//` comment. A placeholder written as
   * `// <PLACEHOLDER: does this exist?>` therefore terminates the file: the
   * class never closes, and the parse error points at a brace many lines
   * earlier, which is a genuinely confusing half-hour.
   *
   * This package shipped exactly that in an example. Cheap to check, and the
   * failure it prevents is expensive to diagnose.
   */
  const offenders = [];

  for (const file of allFiles.filter((f) => f.endsWith('.php'))) {
    const lines = read(file).split('\n');

    lines.forEach((line, i) => {
      const trimmed = line.trim();
      const inComment = line.includes('//') || trimmed.startsWith('*') || trimmed.startsWith('/*');

      if (inComment && line.includes('?>')) {
        offenders.push(`${file}:${i + 1}  ${trimmed.slice(0, 70)}`);
      }
    });
  }

  assert.deepEqual(offenders, [], `\n${offenders.join('\n')}\n`);
});

test('every headline count in the documentation is the real one', () => {
  /**
   * Numbers in prose rot. "17 tables" survived three migrations being added,
   * and a reader has no way to tell a stale number from a current one — which
   * quietly makes every OTHER number in the document less trustworthy.
   *
   * So the counts are computed here and asserted against what the docs claim.
   * Adding a migration now fails this test instead of silently making six
   * documents wrong.
   */
  const tables = new Set();
  const migrations = allFiles.filter((f) => f.startsWith('database/migrations/') && f.endsWith('.php'));
  for (const file of migrations) {
    for (const m of read(file).matchAll(/Schema::create\('(\w+)'/g)) tables.add(m[1]);
  }

  const truth = {
    tables: tables.size,
    migrations: migrations.length,
    suites: allFiles.filter((f) => f.endsWith('.test.mjs')).length,
    routes: (read('routes/sire.php').match(/^\s*Route::(get|post|put|patch|delete)/gm) || []).length,
    phpFiles: allFiles.filter((f) => f.endsWith('.php')).length,
  };

  // Every phrasing the docs are allowed to use for a countable fact.
  const claims = [
    [/\*\*(\d+) migrations, \d+ tables/, truth.migrations],
    [/\*\*\d+ migrations, (\d+) tables/, truth.tables],
    [/(\d+) migrations, \d+ tables, every one prefixed/, truth.migrations],
    [/SIRE's (\d+) tables are additive/, truth.tables],
    [/on all (\d+) tables/, truth.tables],
    [/SIRE adds (\d+) routes/, truth.routes],
    [/(\d+) files lint clean/, truth.phpFiles],
    [/checks across (\d+) suites/, truth.suites],
  ];

  const wrong = [];
  for (const file of docs) {
    const text = read(file);
    for (const [pattern, expected] of claims) {
      const m = text.match(pattern);
      if (m && Number(m[1]) !== expected) {
        wrong.push(`${file}: claims ${m[1]}, real value is ${expected}  ("${m[0].trim()}")`);
      }
    }
  }

  assert.deepEqual(wrong, [], `\n${wrong.join('\n')}\n`);

  // Guard against passing because nothing was scanned.
  assert.ok(truth.tables >= 15 && truth.suites >= 20 && truth.routes >= 50,
    `counts look wrong: ${JSON.stringify(truth)}`);
});
