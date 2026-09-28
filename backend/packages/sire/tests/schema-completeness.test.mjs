import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';

/**
 * SIRE — every table SIRE uses must have a migration that creates it.
 *
 * THE FAILURE THIS EXISTS TO CATCH IS SILENT.
 *
 * Every ALTER migration in SIRE opens with `if (! Schema::hasTable(...)) return;`
 * — the right guard for a codebase whose live database has repeatedly been found
 * with migrations pending. But it means a MISSING CREATE migration produces no
 * error at all: `php artisan migrate` reports success, the ALTERs quietly no-op,
 * and SIRE is broken with nothing in any log to say so.
 *
 * That is exactly what happened. The base tables were specified in
 * 02-data-model.md and never written as files, and the numbering starting at
 * 000007 was the only visible trace.
 */
const DIR = 'database/migrations';
const FILES = readdirSync(DIR).filter((f) => f.endsWith('.php'));
const SOURCES = Object.fromEntries(FILES.map((f) => [f, readFileSync(`${DIR}/${f}`, 'utf8')]));
const ALL = Object.values(SOURCES).join('\n');

const created = new Set([...ALL.matchAll(/Schema::create\('(\w+)'/g)].map((m) => m[1]));
const altered = new Set([...ALL.matchAll(/Schema::table\('(\w+)'/g)].map((m) => m[1]));

/** Tables owned by other modules that SIRE reads but must never create. */
const FOREIGN = new Set(['users', 'tenants', 'tenant_settings', 'audit_logs', 'notes',
  'attachments', 'kb_articles', 'tasks', 'tickets']);

test('the scan found the migrations (guards against a vacuous check)', () => {
  assert.ok(FILES.length >= 15, `found ${FILES.length} migrations`);
  assert.ok(created.size >= 10, `found ${created.size} created tables`);
});

test('EVERY SIRE TABLE THAT IS ALTERED IS ALSO CREATED', () => {
  const missing = [...altered].filter((t) => t.startsWith('sire_') && !created.has(t));

  assert.deepEqual(missing, [],
    `\nThese tables are ALTERed but never created. Every ALTER is guarded by\n`
    + `Schema::hasTable(), so migrate would report success and silently do nothing:\n  `
    + missing.join('\n  ') + '\n');
});

test('every sire_ table a model declares has a create migration', () => {
  const modelDir = 'src/Models';
  const tables = readdirSync(modelDir)
    .filter((f) => f.endsWith('.php'))
    .map((f) => readFileSync(`${modelDir}/${f}`, 'utf8').match(/\$table\s*=\s*'(\w+)'/)?.[1])
    .filter(Boolean);

  const missing = tables.filter((t) => !created.has(t));

  assert.deepEqual(missing, [], `models point at tables nothing creates:\n  ${missing.join('\n  ')}`);
});

test('SIRE creates no table it does not own, and alters no table it does not own', () => {
  // The safety property for every other module: Helpdesk, Tasks, notifications and
  // attachments cannot be affected by a migration that never names their tables.
  const trespass = [...created, ...altered].filter((t) => !t.startsWith('sire_'));

  assert.deepEqual(trespass, [],
    `SIRE migrations touch tables it does not own: ${trespass.join(', ')}`);

  for (const t of FOREIGN) {
    assert.ok(!created.has(t) && !altered.has(t), `${t} belongs to another module`);
  }
});

test('every migration is reversible', () => {
  const irreversible = FILES.filter((f) => !/public function down/.test(SOURCES[f]));
  assert.deepEqual(irreversible, [], `no down(): ${irreversible.join(', ')}`);
});

test('every index identifier is inside MySQL’s 64-character limit', () => {
  // SQLite does not care, so the test suite would never catch an overrun — and a
  // Customer 360 migration has already failed on live for exactly this.
  const tooLong = [...ALL.matchAll(/'(sire_[a-z_]{20,})'/g)]
    .map((m) => m[1])
    .filter((name) => name.length > 64);

  assert.deepEqual(tooLong, []);
});

test('every create migration is idempotent, and every alter is column-guarded', () => {
  const unguarded = [];

  for (const [file, src] of Object.entries(SOURCES)) {
    if (/Schema::create\(/.test(src) && !/Schema::hasTable\(/.test(src)) {
      unguarded.push(`${file}: create without hasTable guard`);
    }
    if (/Schema::table\(/.test(src) && !/hasColumn\(|hasTable\(/.test(src)) {
      unguarded.push(`${file}: alter without a guard`);
    }
  }

  // Live has repeatedly been found with migrations pending from earlier deploys;
  // a re-run must be a no-op, not an error.
  assert.deepEqual(unguarded, [], `\n${unguarded.join('\n')}\n`);
});

test('the checker can actually detect a missing create', () => {
  const fakeCreated = new Set(['sire_reports']);
  const fakeAltered = new Set(['sire_reports', 'sire_ghost']);
  const missing = [...fakeAltered].filter((t) => t.startsWith('sire_') && !fakeCreated.has(t));

  assert.deepEqual(missing, ['sire_ghost']);
});
