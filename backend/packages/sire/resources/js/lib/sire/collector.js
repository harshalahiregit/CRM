/**
 * SIRE — SireContextCollector.
 *
 * The single assembly point. Called ONCE, when the user activates Report Issue.
 * Nothing here runs on mount, on navigation, or on a timer.
 *
 * Cost of one collect(): one route match (string ops over a pre-sorted array),
 * one user-agent regex pass, four window property reads, one array read, and —
 * only when no context was supplied and the route yielded no entity — a single
 * document.querySelector. Sub-millisecond.
 *
 * NOT COLLECTED, by construction rather than by filtering:
 *   passwords, cookies (document.cookie is never read), Authorization headers,
 *   access tokens, API secrets, environment values, request or response bodies,
 *   localStorage / sessionStorage contents, form field values.
 *
 * tenant_id and user_id are NOT sent by the client. The server takes them from
 * the authenticated token ($request->user()), because a client-supplied tenant
 * id in a codebase whose tenancy is opt-in is a leak waiting to happen.
 */
import { resolveRouteContext, titleize } from './resolveRouteContext.js';
import { MODULE_LABELS, ENTITY_LABELS } from './contextRoutes.js';
import { redactUrl } from './redact.js';
import { collectEnvironment } from './environment.js';
import { recentFailedRequests } from './requestLog.js';

const MAX_PAGE_CONTEXT_KEYS = 20;
const MAX_VALUE_LENGTH = 200;

/** Keys refused in explicit page context, whatever a caller passes. */
const FORBIDDEN_KEY = /(password|passwd|pwd|token|secret|auth|cookie|session|credential|apikey|api_key|signature|bearer|jwt)/i;

function sanitisePageContext(input) {
  if (!input || typeof input !== 'object') return null;
  const out = {};
  let count = 0;
  for (const [key, value] of Object.entries(input)) {
    if (count >= MAX_PAGE_CONTEXT_KEYS) break;
    if (FORBIDDEN_KEY.test(key)) continue;
    if (value == null) continue;
    if (typeof value === 'object') continue; // scalars only — no nested blobs
    const str = String(value);
    if (str.length > MAX_VALUE_LENGTH) continue;
    out[key] = str;
    count += 1;
  }
  return Object.keys(out).length ? out : null;
}

/**
 * Fallback entity detection from the DOM. Opt-in: a page only participates if it
 * carries the attributes. No page is required to.
 *   <div data-sire-entity-type="lead" data-sire-entity-id={lead.id}>
 */
function detectEntityFromDom() {
  if (typeof document === 'undefined') return null;
  const el = document.querySelector('[data-sire-entity-type][data-sire-entity-id]');
  if (!el) return null;
  const entityType = el.getAttribute('data-sire-entity-type');
  const entityId = el.getAttribute('data-sire-entity-id');
  if (!entityType || !entityId) return null;
  return { entityType: entityType.slice(0, 64), entityId: String(entityId).slice(0, 64) };
}

/**
 * @param {object}  opts
 * @param {string}  opts.pathname  defaults to window.location.pathname
 * @param {object}  opts.explicit  nearest <SireContext> value, if any
 * @param {object}  opts.overrides user corrections from the modal, if any
 */
/**
 * A label must describe the FINAL value, not the route's.
 *
 * Bug this closes: correcting the module from 'sales' to 'customer' kept showing
 * "Sales", because the label was read off the route match rather than off the
 * value that won precedence. A correction that still displays the wrong thing is
 * worse than no correction at all.
 */
function labelFor(finalValue, routeValue, routeLabel, explicitLabel, dictionary) {
  if (explicitLabel) return explicitLabel;
  if (finalValue == null) return null;
  if (finalValue === routeValue && routeLabel) return routeLabel;
  return (dictionary && dictionary[finalValue]) || titleize(finalValue);
}

export function collect(opts = {}) {
  const {
    pathname = typeof window !== 'undefined' ? window.location.pathname : '',
    href = typeof window !== 'undefined' ? window.location.href : null,
    explicit = null,
    overrides = null,
  } = opts;

  const route = resolveRouteContext(pathname);

  // Precedence: user correction > explicit provider > route map > DOM marker.
  let entityType = overrides?.entityType ?? explicit?.entityType ?? route.entityType ?? null;
  let entityId = overrides?.entityId ?? explicit?.entityId ?? route.entityId ?? null;
  let entitySource = overrides?.entityType ? 'override'
    : explicit?.entityType ? 'provider'
    : route.entityType ? 'route'
    : null;

  if (!entityType || !entityId) {
    const dom = detectEntityFromDom();
    if (dom) {
      entityType = entityType || dom.entityType;
      entityId = entityId || dom.entityId;
      entitySource = entitySource || 'dom';
    }
  }

  const module = overrides?.module ?? explicit?.module ?? route.module ?? null;
  const section = overrides?.section ?? explicit?.section ?? route.section ?? null;
  const screen = overrides?.screen ?? explicit?.screen ?? route.screen ?? null;

  // An explicit provider is authoritative; a user correction more so.
  let confidence = route.confidence;
  if (explicit?.module) confidence = 'high';
  if (overrides) confidence = 'high';
  // A route match that promised nothing but the module is still weak.
  if (!module) confidence = 'low';

  return {
    // --- placement ---
    module,
    section,
    screen,
    route: route.matchedPattern || pathname || null,
    url: redactUrl(href),
    entity_type: entityType,
    entity_id: entityId != null ? String(entityId) : null,

    // --- provenance, so the register can show "detected" vs "corrected" ---
    context_source: overrides ? 'user' : explicit?.module ? 'provider' : route.source,
    context_confidence: confidence,
    entity_source: entitySource,

    // --- environment ---
    ...collectEnvironment(),
    captured_at: new Date().toISOString(),

    // --- diagnostics ---
    failed_requests: recentFailedRequests(3),
    page_context: sanitisePageContext(overrides?.pageContext ?? explicit?.pageContext),

    // --- display labels, so the modal renders without re-deriving anything ---
    labels: {
      module: labelFor(module, route.module, route.moduleLabel,
        overrides?.moduleLabel ?? explicit?.moduleLabel, MODULE_LABELS),
      section: labelFor(section, route.section, route.sectionLabel,
        overrides?.sectionLabel ?? explicit?.sectionLabel),
      screen: labelFor(screen, route.screen, route.screenLabel,
        overrides?.screenLabel ?? explicit?.screenLabel),
      entity: entityType && entityId
        ? `${ENTITY_LABELS[entityType] ?? titleize(entityType)} #${entityId}`
        : null,
    },
  };
}

export const SireContextCollector = { collect };
export default SireContextCollector;
