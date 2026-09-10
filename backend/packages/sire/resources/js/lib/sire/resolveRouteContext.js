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
 *   confidence: 'high'|'medium'|'low', source: 'route'|'module-prefix'|'none'
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
