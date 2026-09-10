#!/usr/bin/env node
/**
 * SIRE — route map audit.
 *
 * Run INSIDE the CRM repo to close the gap between the seeded route map and the
 * real one:
 *
 *   node tools/sire-route-audit.mjs resources/js/app/routes.jsx
 *
 * Deliberately a text scan, not a parser: routes.jsx is a large registry of lazy
 * imports and nothing here should try to execute it. It handles both route
 * styles — JSX <Route path="..."> and object { path: '...' } — because either
 * may appear in a react-router v6 registry.
 *
 * Nested routes are written relative to their parent, so this tool reports them
 * separately rather than guessing at a prefix. It flags; a human decides.
 */
import { readFile } from 'node:fs/promises';
import { resolveRouteContext } from '../resources/js/lib/sire/resolveRouteContext.js';
import { ROUTE_CONTEXT_MAP } from '../resources/js/lib/sire/contextRoutes.js';

const file = process.argv[2];
if (!file) {
  console.error('usage: node tools/sire-route-audit.mjs <path-to-routes.jsx>');
  process.exit(1);
}

const source = await readFile(file, 'utf8');

// path="x" | path='x' | path={`x`} | path: 'x' | path: "x"
const found = new Set();
for (const m of source.matchAll(/\bpath\s*[:=]\s*[{]?\s*["'`]([^"'`]*)["'`]/g)) {
  const p = m[1].trim();
  if (p && p !== '*') found.add(p);
}

const absolute = [...found].filter((p) => p.startsWith('/')).sort();
const relative = [...found].filter((p) => !p.startsWith('/')).sort();

/** ':id' -> '1' so the resolver sees a concrete path. */
const probe = (p) => p.replace(/:([A-Za-z0-9_]+)/g, '1');

const missing = [];
for (const path of absolute) {
  const r = resolveRouteContext(probe(path));
  if (r.source !== 'route') {
    missing.push({ path, module: r.module || '—', confidence: r.confidence });
  }
}

const mapped = ROUTE_CONTEXT_MAP.map((e) => e.pattern);
const absoluteSet = new Set(absolute);
const stale = mapped.filter((p) => !p.endsWith('/*') && !absoluteSet.has(p));

const pad = (s, n) => String(s).padEnd(n);

console.log(`\nSIRE route audit — ${file}`);
console.log(`  absolute routes found : ${absolute.length}`);
console.log(`  relative routes found : ${relative.length} (nested; parent prefix unknown to this tool)`);
console.log(`  entries in route map  : ${mapped.length}\n`);

console.log(`MISSING (${missing.length}) — no map entry; Report Issue falls back to module-prefix detection`);
console.log(`  (module still correct, section/screen blank, user offered a correction)\n`);
for (const m of missing) console.log(`  ${pad(m.path, 52)} module=${pad(m.module, 12)} ${m.confidence}`);

if (relative.length) {
  console.log(`\nRELATIVE (${relative.length}) — resolve against their parent, then check by hand:`);
  for (const p of relative) console.log(`  ${p}`);
}

console.log(`\nSTALE (${stale.length}) — in the map, no exact absolute match in this file.`);
console.log(`  Expect false positives here: a nested child route listed above may be`);
console.log(`  the real target. Verify before deleting an entry.\n`);
for (const s of stale) console.log(`  ${s}`);

console.log(`\nFix by editing resources/js/lib/sire/contextRoutes.js only. Nothing else changes.\n`);
