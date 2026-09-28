#!/usr/bin/env node
/**
 * SIRE — route map audit.
 *
 * Run INSIDE the CRM repo to close the gap between the route map and the real
 * router:
 *
 *   node tools/sire-route-audit.mjs resources/js/app/routes.jsx
 *
 * WHY THIS WAS REWRITTEN. The first version was a flat text scan. React Router
 * writes child routes RELATIVE to their parent, so out of ~480 routes it could
 * only resolve the 36 written absolutely; the other 300 -- which is every page
 * inside `/app`, i.e. essentially the whole CRM -- it printed as a list of bare
 * words like `:id` and `attendance` and told a human to work it out. The result
 * was an audit reporting "35 missing" when the real answer was 163, and Report
 * Issue quietly falling back to module-only detection on screens nobody had
 * checked.
 *
 * So this resolves the nesting: it walks the JSX keeping a stack of parent
 * prefixes and joins each child onto its parent, which is what react-router
 * does itself.
 *
 * Still a scanner, not an evaluator: routes.jsx is a large registry of lazy
 * imports and nothing here should try to execute it. But it is now a scanner
 * that understands tags, so `element={<Guard when={a > b} />}` cannot end a tag
 * early and a nested route cannot lose its parent.
 *
 * `--json` prints the resolved routes as JSON. `tests/route-map-coverage.test.mjs`
 * imports the functions below directly.
 */
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolveRouteContext } from '../resources/js/lib/sire/resolveRouteContext.js';
import { ROUTE_CONTEXT_MAP } from '../resources/js/lib/sire/contextRoutes.js';

/**
 * Read one JSX tag starting at `<`, returning its raw attribute text and where
 * it ended.
 *
 * The whole reason this is hand-written: attributes hold JSX of their own, and
 * `element={<Guard when={a > b}><Page /></Guard>}` contains both `>` and `/>`
 * long before the tag actually closes. Tracking brace depth and quotes is what
 * keeps those from ending the tag early.
 */
function readTag(src, start) {
  let i = src.indexOf(' ', start);
  if (i === -1) i = start;

  let depth = 0;
  let quote = null;
  let attrs = '';

  for (; i < src.length; i += 1) {
    const ch = src[i];

    if (quote) {
      attrs += ch;
      if (ch === quote && src[i - 1] !== '\\') quote = null;
      continue;
    }

    if (ch === '"' || ch === "'" || ch === '`') {
      quote = ch;
      attrs += ch;
      continue;
    }

    if (ch === '{') depth += 1;
    else if (ch === '}') depth -= 1;

    // Only a '>' outside every brace and quote closes the tag.
    if (depth === 0 && ch === '>') {
      return { attrs, end: i, selfClosing: attrs.trimEnd().endsWith('/') };
    }

    attrs += ch;
  }

  return { attrs, end: src.length, selfClosing: true };
}

/** Pull `path="x"` from a tag's OWN attributes (brace depth 0 only). */
function attrPath(attrs) {
  let depth = 0;
  let quote = null;

  for (let i = 0; i < attrs.length; i += 1) {
    const ch = attrs[i];

    if (quote) {
      if (ch === quote && attrs[i - 1] !== '\\') quote = null;
      continue;
    }
    if (ch === '{') { depth += 1; continue; }
    if (ch === '}') { depth -= 1; continue; }
    if (ch === '"' || ch === "'" || ch === '`') { quote = ch; continue; }

    if (depth === 0 && attrs.startsWith('path', i) && /[\s=]/.test(attrs[i + 4] ?? '')) {
      const m = /^path\s*=\s*["'`]([^"'`]*)["'`]/.exec(attrs.slice(i));
      if (m) return m[1].trim();
    }
  }

  return null;
}

const join = (parent, child) => {
  if (!child) return parent;
  if (child.startsWith('/')) return child;
  return `${parent === '/' ? '' : parent}/${child}`.replace(/\/{2,}/g, '/');
};

/** Redirect-only routes are not pages anybody can file an issue from. */
const isRedirect = (attrs) => /<Navigate\b/.test(attrs);

/**
 * Every page the router can land on, with nesting resolved to full paths.
 *
 * @returns {Array<{path: string, index: boolean, redirect: boolean, line: number}>}
 */
export function scanRoutes(source) {
  const routes = [];
  const stack = [{ prefix: '' }];

  for (let i = 0; i < source.length; i += 1) {
    if (source.startsWith('</Route>', i)) {
      if (stack.length > 1) stack.pop();
      i += 7;
      continue;
    }

    if (!source.startsWith('<Route', i)) continue;
    // '<Routes>' is the container, not a route.
    if (/[A-Za-z]/.test(source[i + 6] ?? '')) continue;

    const { attrs, end, selfClosing } = readTag(source, i);
    const parent = stack[stack.length - 1].prefix;
    const raw = attrPath(attrs);
    const isIndex = /(^|\s)index(\s|=|$)/.test(attrs);
    const full = raw === null ? parent : join(parent, raw);

    if (raw !== '*' && (raw !== null || isIndex)) {
      routes.push({
        path: full || '/',
        index: isIndex,
        redirect: isRedirect(attrs),
        line: source.slice(0, i).split('\n').length,
      });
    }

    if (!selfClosing) stack.push({ prefix: full });

    i = end;
  }

  // Index routes duplicate their parent's path; a real page at that path wins.
  const seen = new Map();
  for (const r of routes) {
    const prior = seen.get(r.path);
    if (!prior || (prior.redirect && !r.redirect)) seen.set(r.path, r);
  }

  return [...seen.values()]
    .filter((r) => !r.redirect)
    .sort((a, b) => a.path.localeCompare(b.path));
}

/**
 * ':id' -> '1' so the resolver sees a concrete path.
 *
 * A trailing '/*' is react-router for "and anything below this", not a segment.
 * Probing it literally asked the resolver about a path with a star in it and
 * reported the parent as unmapped, which is how '/app/purchase/vendors/:id/*'
 * showed up as missing while '/app/purchase/vendors/:id' was mapped all along.
 */
export const probe = (p) => p.replace(/\/\*$/, '').replace(/:([A-Za-z0-9_]+)/g, '1') || '/';

/** Every page, with what Report Issue would detect on it. */
export function auditRoutes(source) {
  return scanRoutes(source).map((r) => {
    const ctx = resolveRouteContext(probe(r.path));
    const wantsRecord = /:[A-Za-z0-9_]+/.test(r.path.replace(/\/\*$/, ''));

    return {
      ...r,
      module: ctx.module,
      section: ctx.section,
      screen: ctx.screen,
      entityType: ctx.entityType,
      entityId: ctx.entityId,
      source: ctx.source,
      confidence: ctx.confidence,
      wantsRecord,
      // The complaint that started this: the page is found but the RECORD is not.
      recordMissed: wantsRecord && !ctx.entityType,
    };
  });
}

/**
 * Report Issue is mounted in AppShell, which wraps '/app'. The public portals
 * (/vendor-portal, /purchase-portal, /portal, /auth, token links) can never
 * open it, so mapping them would be data nothing reads.
 */
export const isReportable = (path) => path === '/app' || path.startsWith('/app/');

// ---------------------------------------------------------------- CLI

const invokedDirectly = process.argv[1]
  && fileURLToPath(import.meta.url) === process.argv[1].replace(/\\/g, '/').replace(/^\/?([A-Za-z]:)/, '$1');

if (invokedDirectly || process.argv[1]?.endsWith('sire-route-audit.mjs')) {
  const args = process.argv.slice(2);
  const asJson = args.includes('--json');
  const file = args.find((a) => !a.startsWith('--'));

  if (!file) {
    console.error('usage: node tools/sire-route-audit.mjs <path-to-routes.jsx> [--json]');
    process.exit(1);
  }

  const source = await readFile(file, 'utf8');
  const results = auditRoutes(source);

  if (asJson) {
    console.log(JSON.stringify(results, null, 2));
    process.exit(0);
  }

  const pages = results;
  const app = results.filter((r) => isReportable(r.path));
  const missing = app.filter((r) => r.source !== 'route');
  const recordMissed = app.filter((r) => r.recordMissed);
  const prefixOnly = app.filter((r) => r.source === 'route-prefix');

  const mapped = ROUTE_CONTEXT_MAP.map((e) => e.pattern);
  const realPaths = new Set(pages.map((p) => p.path));
  const stale = mapped.filter((p) => !p.endsWith('/*') && !realPaths.has(p));

  const pad = (s, n) => String(s).padEnd(n);

  console.log(`\nSIRE route audit — ${file}`);
  console.log(`  pages in the router   : ${pages.length}  (redirect-only routes excluded)`);
  console.log(`  reportable (/app)     : ${app.length}`);
  console.log(`  entries in route map  : ${mapped.length}\n`);
  console.log(`  resolved exactly      : ${app.length - missing.length - prefixOnly.length}`);
  console.log(`  resolved by prefix    : ${prefixOnly.length}`);
  console.log(`  NOT resolved          : ${missing.length}`);
  console.log(`  record not detected   : ${recordMissed.length}\n`);

  if (missing.length) {
    console.log(`MISSING (${missing.length}) — no map entry; Report Issue falls back to module detection\n`);
    for (const m of missing) {
      console.log(`  ${pad(m.path, 58)} module=${pad(m.module || '—', 12)} ${m.source}`);
    }
  }

  if (recordMissed.length) {
    console.log(`\nRECORD NOT DETECTED (${recordMissed.length}) — the URL names a record, the map does not\n`);
    for (const m of recordMissed) console.log(`  ${pad(m.path, 58)} ${m.source}`);
  }

  if (prefixOnly.length) {
    console.log(`\nPREFIX-ONLY (${prefixOnly.length}) — inherits a parent; screen key is derived, not mapped\n`);
    for (const m of prefixOnly) console.log(`  ${pad(m.path, 58)} screen=${m.screen ?? '—'}`);
  }

  const outside = results.filter((r) => !isReportable(r.path));
  console.log(`\nNOT REPORTABLE (${outside.length}) — outside /app, cannot open Report Issue. Not audited.`);

  console.log(`\nSTALE (${stale.length}) — in the map, matching no route in this file.`);
  console.log(`  A wildcard entry is never stale. Verify before deleting.\n`);
  for (const s of stale) console.log(`  ${s}`);

  console.log(`\nFix by editing resources/js/lib/sire/contextRoutes.js only. Nothing else changes.\n`);
}
