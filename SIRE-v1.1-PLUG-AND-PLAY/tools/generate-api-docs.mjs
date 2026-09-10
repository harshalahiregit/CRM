#!/usr/bin/env node
/**
 * SIRE — generate docs/API.md from the route file.
 *
 * An endpoint list maintained by hand is wrong within a fortnight, and a wrong
 * one is worse than none: a developer trusts it, calls something that no longer
 * exists, and blames their wiring. So the reference is derived, and
 * tests/api-docs-sync.test.mjs fails the build when the committed file and the
 * routes disagree.
 *
 * The narrative below the endpoint tables (conventions, request shapes) is
 * hand-written and preserved verbatim from tools/api-narrative.md.
 *
 * Usage: node tools/generate-api-docs.mjs [--check]
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const ROUTES = join(ROOT, 'routes/sire.php');
const NARRATIVE = join(ROOT, 'tools/api-narrative.md');
const OUT = join(ROOT, 'docs/API.md');

const src = readFileSync(ROUTES, 'utf8');

const prefix = (src.match(/->prefix\('([^']+)'\)/) || [, 'api/sire'])[1];

/** Walk the file in order so routes stay under the section comment above them. */
const sections = [];
let current = null;

for (const line of src.split('\n')) {
  const section = line.match(/^\s*\/\/ ---- (.+?) -+$/) || line.match(/^\s*\/\/ ={2,} (.+?) ={2,}$/);
  if (section) {
    current = { title: section[1].trim(), routes: [] };
    sections.push(current);
    continue;
  }

  const route =
    line.match(/Route::(\w+)\(\s*'([^']+)'\s*,\s*\[(\w+)::class,\s*'(\w+)'\]/) ||
    line.match(/Route::(\w+)\(\s*'([^']+)'\s*,\s*(\w+Controller)::class\s*\)/);

  if (!route) continue;

  if (!current) {
    current = { title: 'General', routes: [] };
    sections.push(current);
  }

  current.routes.push({
    verb: route[1].toUpperCase(),
    path: `/${prefix}/${route[2]}`.replace(/\/+/g, '/'),
    controller: route[3],
    method: route[4] || '__invoke',
  });
}

const withRoutes = sections.filter((s) => s.routes.length > 0);
const total = withRoutes.reduce((n, s) => n + s.routes.length, 0);

if (total === 0) throw new Error('parsed zero routes — the route file format changed');

const middleware = (src.match(/Route::middleware\((\[[^\]]+\])\)/) || [, '[]'])[1];

const body = withRoutes
  .map((s) => {
    const rows = s.routes
      .map((r) => `| ${r.verb} | \`${r.path}\` | ${r.controller}::${r.method} |`)
      .join('\n');
    return `### ${s.title}\n\n| Method | Endpoint | Handler |\n|---|---|---|\n${rows}\n`;
  })
  .join('\n');

const narrative = readFileSync(NARRATIVE, 'utf8').trim();

const out = `# SIRE API reference

<!-- GENERATED from routes/sire.php by tools/generate-api-docs.mjs.
     Do not edit the endpoint tables by hand; edit the routes and re-run.
     The narrative below comes from tools/api-narrative.md. -->

**${total} endpoints**, all inside one route group:

\`\`\`php
Route::middleware(${middleware})->prefix('${prefix}')->group(function () {
\`\`\`

> **Both middleware must stay in ONE \`->middleware([...])\` array.** A second
> chained \`->middleware()\` call replaces the first and silently drops
> \`auth:sanctum\`. The discovery report records that exact mistake causing a live
> cross-vendor leak in the first host application.

SIRE is for internal engineering staff. Customer-facing roles must not reach it.

## Endpoints

${body}
${narrative}
`;

if (process.argv.includes('--check')) {
  if (readFileSync(OUT, 'utf8') !== out) {
    console.error('docs/API.md is out of date — run: node tools/generate-api-docs.mjs');
    process.exit(1);
  }
  console.log(`API docs in sync (${total} endpoints)`);
} else {
  writeFileSync(OUT, out);
  console.log(`wrote docs/API.md (${total} endpoints, ${withRoutes.length} sections)`);
}
