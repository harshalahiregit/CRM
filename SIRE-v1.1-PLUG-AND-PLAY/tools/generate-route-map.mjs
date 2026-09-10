#!/usr/bin/env node
/**
 * SIRE — generate the PHP route map from the JavaScript one.
 *
 * The route → context map has to exist on both sides: the browser resolves it
 * instantly so Report Issue opens already filled in, and the server re-resolves
 * the submitted path because a client can send anything it likes.
 *
 * Two hand-maintained copies of the same 70 routes would drift within a week,
 * and the drift would be invisible — the modal would say Billing while the
 * stored issue said Sales. So the JS map is the single source and this emits the
 * PHP one. tests/route-map-sync.test.mjs re-runs it and fails if the committed
 * PHP file differs, which makes editing the PHP by hand a build failure rather
 * than a silent inconsistency.
 *
 * Usage: node tools/generate-route-map.mjs [--check]
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SRC = join(ROOT, 'resources/js/lib/sire/contextRoutes.js');
const OUT = join(ROOT, 'src/Support/SireRouteMap.php');

const src = readFileSync(SRC, 'utf8');

function block(name) {
  const start = src.indexOf(`export const ${name} = `);
  if (start === -1) throw new Error(`${name} not found in contextRoutes.js`);
  const open = src.indexOf(name.endsWith('MAP') ? '[' : '{', start);
  const closer = name.endsWith('MAP') ? ']' : '}';
  let depth = 0;
  for (let i = open; i < src.length; i++) {
    if (src[i] === '[' || src[i] === '{') depth++;
    else if (src[i] === ']' || src[i] === '}') {
      depth--;
      if (depth === 0) return src.slice(open, i + 1);
    }
  }
  throw new Error(`unterminated ${name}`);
}

/** Strip comments, then read the object literals. Values here are plain strings. */
function entries() {
  const body = block('ROUTE_CONTEXT_MAP').replace(/\/\/[^\n]*/g, '');
  return [...body.matchAll(/\{([^}]*)\}/g)].map((m) => {
    const entry = {};
    for (const pair of m[1].matchAll(/(\w+):\s*'([^']*)'/g)) entry[pair[1]] = pair[2];
    return entry;
  });
}

function labels(name) {
  const body = block(name).replace(/\/\/[^\n]*/g, '');
  const out = {};
  for (const m of body.matchAll(/(\w+):\s*'([^']*)'/g)) out[m[1]] = m[2];
  return out;
}

const routes = entries();
const moduleLabels = labels('MODULE_LABELS');

if (routes.length === 0) throw new Error('parsed zero routes — the map format changed');
for (const r of routes) {
  if (!r.pattern || !r.module) throw new Error(`incomplete entry: ${JSON.stringify(r)}`);
  if (!moduleLabels[r.module]) throw new Error(`route ${r.pattern} uses unknown module '${r.module}'`);
}

const php = (v) => `'${String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

const routeLines = routes.map((r) => {
  const parts = ['pattern', 'module', 'section', 'screen', 'entityType', 'entityParam']
    .filter((k) => r[k] !== undefined)
    .map((k) => `${php(k.replace(/[A-Z]/g, (c) => '_' + c.toLowerCase()))} => ${php(r[k])}`);
  return `        [${parts.join(', ')}],`;
}).join('\n');

const labelLines = Object.entries(moduleLabels)
  .map(([k, v]) => `        ${php(k)} => ${php(v)},`)
  .join('\n');

const out = `<?php

namespace Sire\\Support;

/**
 * SIRE — the route → context map, server side.
 *
 * GENERATED FILE. Do not edit.
 * Source:    resources/js/lib/sire/contextRoutes.js
 * Generator: tools/generate-route-map.mjs
 * Guarded by tests/route-map-sync.test.mjs, which fails the build if this file
 * and the JavaScript map disagree.
 *
 * The map is SEEDED from the CRM discovery report, not read from the CRM's route
 * registry. Reconcile it with \`node tools/sire-route-audit.mjs <routes-file>\`,
 * edit the JAVASCRIPT map, and re-run the generator.
 *
 * ${routes.length} routes, ${Object.keys(moduleLabels).length} modules.
 */
final class SireRouteMap
{
    /** @var array<int, array<string, string>> */
    public const ROUTES = [
${routeLines}
    ];

    /** @var array<string, string> */
    public const MODULE_LABELS = [
${labelLines}
    ];
}
`;

if (process.argv.includes('--check')) {
  const current = readFileSync(OUT, 'utf8');
  if (current !== out) {
    console.error('SireRouteMap.php is out of date — run: node tools/generate-route-map.mjs');
    process.exit(1);
  }
  console.log('route map in sync');
} else {
  writeFileSync(OUT, out);
  console.log(`wrote ${OUT} (${routes.length} routes, ${Object.keys(moduleLabels).length} modules)`);
}
