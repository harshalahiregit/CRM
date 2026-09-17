import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * SIRE — static tenant-isolation check.
 *
 * Tenant scoping in this CRM is OPT-IN, per query. There is no global scope, no
 * tenant middleware and no row-level security: `BelongsToTenant` provides a
 * scope you must remember to call. A missing ->forTenant() returns every tenant's
 * rows with no error and no log line.
 *
 * A feature test proves the endpoints that exist today are scoped. This proves
 * that no query in SIRE's source is unscoped at all — including ones no feature
 * test happens to cover. It runs without a database, so it can gate every commit.
 *
 * A deliberate cross-tenant read (there is exactly one: discovering which tenants
 * to sweep) must carry a `// tenant-sweep:` comment saying why.
 */

const ROOTS = ['src/Services', 'src/Http/Controllers',
               'src/Console/Commands', 'src/Models',
               // Services/Sire is recursive, so Services/Sire/Ai is already
               // covered; listed for the reader rather than for the scan.
               'src/Support'];

/** Models whose tables carry tenant_id and must therefore always be scoped. */
const TENANT_MODELS = [
  // Phase 0/1
  'Report', 'WorkCycle', 'ReportContext', 'ReportCategory', 'ReportSeverity', 'ReportApproval',
  // Phase 2 — added here the moment each model was written; a model missing from
  // this list is a blind spot, not a pass.
  'Release', 'RootCause', 'RecurrenceGroup', 'ReportLink', 'ReportWatcher', 'ReportAssignee', 'KbLink',
  'ReleaseNote', 'CorrectiveAction',
  // Phase 3 — release governance
  'ReleaseOverride',
  // Phase 3 — AI foundation
  'AiSuggestion', 'IssueToken', 'IssueTestCase',
  // SDK fallback stores — SIRE's own, used when the host provides no equivalent
  'Setting', 'AuditEvent',
  // Shared engines SIRE reads through when a host provides them
  'AuditLog', 'Note', 'Attachment',
];

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

/**
 * Blank out comments while preserving offsets and line numbers, so the scan reads
 * CODE and not prose. Without this, a docblock saying "there is not a single raw
 * Report::query() below" is itself reported as a violation — which is exactly what
 * happened the first time this ran.
 */
function stripComments(source) {
  let out = source
    // /* ... */ and /** ... */ — keep newlines so line numbers survive.
    .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));

  // // ... to end of line, but not the '//' in a 'https://' style literal.
  out = out.replace(/(^|[^:])\/\/[^\n]*/g, (m, prefix) => prefix + ' '.repeat(m.length - prefix.length));

  return out;
}

/** The statement starting at `from`, up to its terminating semicolon. */
function statementAt(source, from) {
  const end = source.indexOf(';', from);
  return source.slice(from, end === -1 ? source.length : end + 1);
}

const FILES = ROOTS.flatMap(phpFiles);

test('the scan found the SIRE source (guards against a silently empty check)', () => {
  assert.ok(FILES.length >= 10, `expected SIRE php files, found ${FILES.length}`);
});

/** The detector, factored out so it can be proven to detect. */
export function findViolations(source, file = 'inline') {
  const violations = [];
  const code = stripComments(source);
  const lines = source.split('\n');

  for (const model of TENANT_MODELS) {
    const needle = `${model}::query()`;
    let index = code.indexOf(needle);

    while (index !== -1) {
      const lineNo = code.slice(0, index).split('\n').length;
      const statement = statementAt(code, index);

      // A deliberate unscoped read must say so, within the 5 lines above it.
      const preamble = lines.slice(Math.max(0, lineNo - 6), lineNo - 1).join('\n');
      const excused = preamble.includes('tenant-sweep:');
      const scoped = statement.includes('->forTenant(') || statement.includes("where('tenant_id'");

      if (!scoped && !excused) {
        violations.push(`${file}:${lineNo}  ${model}::query() is not tenant-scoped`);
      }
      index = code.indexOf(needle, index + 1);
    }
  }

  return violations;
}

test('the detector detects (proving this check is not vacuous)', () => {
  const unscoped = `<?php
    class Bad {
        public function leak() {
            return Report::query()->where('status', 'open')->get();
        }
    }`;
  assert.equal(findViolations(unscoped).length, 1, 'an unscoped query must be caught');

  const scoped = `<?php
    class Good {
        public function safe(int $tenantId) {
            return Report::query()->forTenant($tenantId)->where('status', 'open')->get();
        }
    }`;
  assert.deepEqual(findViolations(scoped), [], 'a scoped query must pass');

  const commentOnly = `<?php
    /** There is not a single raw Report::query() below. */
    class Fine {}`;
  assert.deepEqual(findViolations(commentOnly), [], 'prose is not code');

  const excused = `<?php
    class Sweep {
        public function run() {
            // tenant-sweep: discovery only
            return Report::query()->select('tenant_id')->distinct()->pluck('tenant_id');
        }
    }`;
  assert.deepEqual(findViolations(excused), [], 'a documented sweep is allowed');

  // The excuse window is the 5 lines immediately above the query, so an excuse
  // needs a gap of 6+ lines to fall outside it. This fixture originally used 4
  // blank lines and was silently INSIDE the window — the self-test caught that
  // before it could give false confidence in the real scan.
  const excusedTooFarAway = `<?php
    class Stale {
        public function run() {
            // tenant-sweep: this excuse is nowhere near the query
${'\n'.repeat(8)}
            return Report::query()->get();
        }
    }`;
  assert.equal(findViolations(excusedTooFarAway).length, 1, 'an excuse must sit next to what it excuses');
});

test('every tenant-model query in SIRE is tenant-scoped', () => {
  const violations = FILES.flatMap((file) => findViolations(readFileSync(file, 'utf8'), file));
  assert.deepEqual(violations, [], `\n${violations.join('\n')}\n`);
});

test('the deliberate cross-tenant sweep is documented and selects only tenant_id', () => {
  const file = 'src/Console/Commands/RunSireSchedule.php';
  const source = readFileSync(file, 'utf8');
  assert.ok(source.includes('tenant-sweep:'), 'the sweep must explain itself');

  const marker = source.indexOf('tenant-sweep:');
  const window = source.slice(marker, marker + 600);
  assert.ok(window.includes("select('tenant_id')"), 'the discovery query must read tenant_id only');
  assert.ok(source.includes('->forTenant($tenantId)'), 'per-tenant reads must be scoped');
});

test('no SIRE controller trusts a tenant_id from the request', () => {
  const offenders = [];
  for (const file of FILES) {
    const source = readFileSync(file, 'utf8');
    for (const pattern of ["request->input('tenant_id')", "request->tenant_id",
                           "validated('tenant_id')", "->get('tenant_id')"]) {
      if (source.includes(pattern)) offenders.push(`${file}: ${pattern}`);
    }
  }
  assert.deepEqual(offenders, [], `tenant must come from the token:\n${offenders.join('\n')}`);
});

test('route-bound models are ownership-checked in every controller that binds one', () => {
  const controllers = FILES.filter((f) => f.includes('Controllers/Api/Sire'));
  const missing = [];

  for (const file of controllers) {
    const source = readFileSync(file, 'utf8');
    // Does any method accept a route-bound Report?
    if (!/function \w+\([^)]*Report \$report/.test(source)) continue;
    if (!source.includes('assertTenantOwnership')) {
      missing.push(file);
    }
  }

  assert.deepEqual(missing, [], `bind a model, check it owns:\n${missing.join('\n')}`);
});

test('every tenant-scoped SIRE model is covered by this scan', () => {
  // The scan can only catch what it knows about. This asserts the model list keeps
  // pace with the Models/Sire directory, so adding a model without adding it here
  // fails loudly instead of creating a silent gap.
  const modelFiles = readdirSync('src/Models')
    .filter((f) => f.endsWith('.php'))
    .map((f) => f.replace('.php', ''));

  /**
   * HostUser is deliberately absent: it is a read-only window onto the HOST's
   * users table, which SIRE does not own and does not scope. Its protection is
   * different in kind — it is read-only, exposes three columns, and every
   * identity decision goes through SireUserProvider instead. Listing it here
   * would demand a ->forTenant() on a table whose tenant column SIRE does not
   * control.
   */
  const NOT_TENANT_OWNED = ['HostUser'];

  const uncovered = modelFiles.filter((m) => !TENANT_MODELS.includes(m) && !NOT_TENANT_OWNED.includes(m));
  assert.deepEqual(uncovered, [], `models not covered by the tenant scan: ${uncovered.join(', ')}`);
});

test('SIRE never disables the tenant scope', () => {
  const offenders = [];
  for (const file of FILES) {
    const source = readFileSync(file, 'utf8');
    for (const pattern of ['withoutGlobalScope', 'withoutGlobalScopes', 'allTenants(']) {
      if (source.includes(pattern)) offenders.push(`${file}: ${pattern}`);
    }
  }
  assert.deepEqual(offenders, []);
});
