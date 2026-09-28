/**
 * SIRE — pure route resolver. No React, no DOM, no window. Unit-testable.
 */
import { ROUTE_CONTEXT_MAP, MODULE_LABELS } from './contextRoutes.js';

const segments = (path) => String(path || '').split('?')[0].split('#')[0].split('/').filter(Boolean);

/** 'lead-details' -> 'Lead Details'; 'purchase_order' -> 'Purchase Order' */
export function titleize(value) {
  if (!value) return null;
  return String(value)
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}

/**
 * Compiled once at import. Sorted most-specific-first so map order is irrelevant:
 * more path segments wins, then more STATIC segments wins. That makes
 * '/app/sales/leads/:id/edit' beat '/app/sales/leads/:id', and
 * '/app/sales/leads/new' beat '/app/sales/leads/:id' if both are ever present.
 */
const COMPILED = ROUTE_CONTEXT_MAP.map((entry) => {
  const segs = segments(entry.pattern);
  const wildcard = segs[segs.length - 1] === '*';
  return {
    ...entry,
    segs,
    wildcard,
    depth: wildcard ? segs.length - 1 : segs.length,
    staticCount: segs.filter((s) => !s.startsWith(':') && s !== '*').length,
  };
}).sort((a, b) => b.depth - a.depth || b.staticCount - a.staticCount);

function matchEntry(entry, parts) {
  if (entry.wildcard) {
    if (parts.length < entry.depth) return null;
  } else if (parts.length !== entry.segs.length) {
    return null;
  }

  const params = {};
  for (let i = 0; i < entry.segs.length; i += 1) {
    const seg = entry.segs[i];
    if (seg === '*') break;
    if (seg.startsWith(':')) {
      params[seg.slice(1)] = parts[i];
      continue;
    }
    if (seg.toLowerCase() !== String(parts[i]).toLowerCase()) return null;
  }
  return params;
}

const KNOWN_MODULES = new Set(Object.keys(MODULE_LABELS));

/**
 * @param {string} pathname  e.g. '/app/sales/leads/10452'
 * @returns {{
 *   module: string|null, section: string|null, screen: string|null,
 *   entityType: string|null, entityId: string|null,
 *   moduleLabel: string|null, sectionLabel: string|null, screenLabel: string|null,
 *   entityLabel: string|null, matchedPattern: string|null,
 *   confidence: 'high'|'medium'|'low',
 *   source: 'route'|'route-prefix'|'module-prefix'|'none'
 * }}
 */
export function resolveRouteContext(pathname) {
  const parts = segments(pathname);

  for (const entry of COMPILED) {
    const params = matchEntry(entry, parts);
    if (!params) continue;

    const entityId = entry.entityType
      ? (params[entry.entityParam || 'id'] ?? null)
      : null;

    // A pattern that promises an entity but yields no id is only 'medium' —
    // that is exactly the case where the user should be offered a correction.
    const confidence = entry.entityType && !entityId ? 'medium' : 'high';

    return {
      module: entry.module ?? null,
      section: entry.section ?? null,
      screen: entry.screen ?? null,
      entityType: entry.entityType ?? null,
      entityId: entityId != null ? String(entityId) : null,
      moduleLabel: MODULE_LABELS[entry.module] ?? titleize(entry.module),
      sectionLabel: entry.sectionLabel ?? titleize(entry.section),
      screenLabel: entry.screenLabel ?? titleize(entry.screen),
      entityLabel: entry.entityType && entityId
        ? `${titleize(entry.entityType)} #${entityId}`
        : null,
      matchedPattern: entry.pattern,
      confidence,
      source: 'route',
    };
  }

  /*
   * Second pass: the deepest mapped route this path sits UNDER.
   *
   * The exact matcher above demands the same number of segments, so a workspace
   * that puts its tabs on the URL — '/app/purchase/vendors/1/customer' against a
   * mapped '/app/purchase/vendors/:id' — matched nothing and fell all the way to
   * the module-only fallback. Report Issue then showed a module and no section,
   * screen or record, which is exactly what SIR-000011 describes.
   *
   * Listing every tab by hand would fix those tabs and none of the next ones, so
   * the parent is inherited instead: its module, section and record still hold on
   * a child path (it IS that vendor), and the leftover segments name the screen.
   * Confidence is 'medium', never 'high' — the screen key is derived, not mapped,
   * so the reporter is still offered the correction box.
   */
  for (const entry of COMPILED) {
    // '/app' itself is mapped (the dashboard), and every in-app path sits under
    // it — inheriting from it would label all of them "Dashboard / Home". A
    // parent has to name a module at least, so one segment is never enough.
    if (entry.wildcard || entry.segs.length < 2) continue;
    if (parts.length <= entry.segs.length) continue;
    const params = matchEntry(entry, parts.slice(0, entry.segs.length));
    if (!params) continue;

    const tail = parts.slice(entry.segs.length);
    // A trailing id ('/documents/57') names a record inside the tab, not a screen.
    const words = tail.filter((t) => !/^\d+$/.test(t));
    const screen = words.length
      ? `${entry.section || entry.module || ''}-${words.join('-')}`.replace(/^-/, '')
      : entry.screen ?? null;

    const entityId = entry.entityType
      ? (params[entry.entityParam || 'id'] ?? null)
      : null;

    return {
      module: entry.module ?? null,
      section: entry.section ?? null,
      screen,
      entityType: entry.entityType ?? null,
      entityId: entityId != null ? String(entityId) : null,
      moduleLabel: MODULE_LABELS[entry.module] ?? titleize(entry.module),
      sectionLabel: entry.sectionLabel ?? titleize(entry.section),
      screenLabel: titleize(words.join('-')) || entry.screenLabel || titleize(entry.screen),
      entityLabel: entry.entityType && entityId
        ? `${titleize(entry.entityType)} #${entityId}`
        : null,
      matchedPattern: `${entry.pattern}/*`,
      confidence: 'medium',
      source: 'route-prefix',
    };
  }

  // Fallback: '/app/<module>/anything-unmapped' still identifies the module.
  const candidate = parts[0] === 'app' ? parts[1] : parts[0];
  if (candidate && KNOWN_MODULES.has(candidate.toLowerCase())) {
    const module = candidate.toLowerCase();
    return {
      module,
      section: null,
      screen: null,
      entityType: null,
      entityId: null,
      moduleLabel: MODULE_LABELS[module] ?? titleize(module),
      sectionLabel: null,
      screenLabel: null,
      entityLabel: null,
      matchedPattern: null,
      confidence: 'low',
      source: 'module-prefix',
    };
  }

  return {
    module: null, section: null, screen: null,
    entityType: null, entityId: null,
    moduleLabel: null, sectionLabel: null, screenLabel: null, entityLabel: null,
    matchedPattern: null,
    confidence: 'low',
    source: 'none',
  };
}

/** Exported for the route-audit tool. */
export function knownPatterns() {
  return COMPILED.map((e) => e.pattern);
}
