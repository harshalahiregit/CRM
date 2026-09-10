/**
 * SIRE — saved list views.
 *
 * THE HONEST CONSTRAINT, STATED UP FRONT
 *
 * The brief says "saved views if existing infrastructure supports them". It does
 * not. There is no per-user preference store in this CRM: `tenant_settings` is
 * per tenant, and `users` has no preferences column. The only per-user persistence
 * the CRM already uses is localStorage — the modules "marketplace" page does
 * exactly this.
 *
 * So saved views are localStorage, and that means:
 *
 *   - they live in ONE BROWSER. Not synced, not shared, gone when site data is
 *     cleared. The UI says so rather than letting someone discover it.
 *   - they cannot be shared with a colleague. A view worth sharing is a link, and
 *     `toQueryParams` already makes the filter state URL-shaped.
 *
 * When a per-user preferences store exists, this file is the only thing that
 * changes: the four functions below keep their signatures and talk to it instead.
 */

const KEY_PREFIX = 'sire.views.';
const MAX_VIEWS = 12;

/** Every accessor is wrapped: private windows and blocked site data both throw. */
function storage() {
  try {
    const probe = '__sire_probe__';
    window.localStorage.setItem(probe, '1');
    window.localStorage.removeItem(probe);
    return window.localStorage;
  } catch {
    return null;
  }
}

export function isAvailable() {
  return storage() !== null;
}

/**
 * @param {string} listKey  which list — 'issues', 'releases', 'capa'
 * @returns {Array<{id, name, filters, scope, createdAt}>}
 */
export function listViews(listKey) {
  const store = storage();
  if (!store) return [];

  try {
    const raw = store.getItem(KEY_PREFIX + listKey);
    const parsed = raw ? JSON.parse(raw) : [];
    // Corrupt or hand-edited storage must not break a page.
    return Array.isArray(parsed) ? parsed.filter((v) => v && v.id && v.name) : [];
  } catch {
    return [];
  }
}

/** A stable id no other view in this list holds. No randomness needed. */
function nextId(views) {
  const taken = new Set(views.map((v) => v.id));
  const base = `v${Date.now().toString(36)}`;

  if (!taken.has(base)) return base;

  let suffix = 1;
  while (taken.has(`${base}-${suffix}`)) suffix += 1;

  return `${base}-${suffix}`;
}

export function saveView(listKey, { name, filters, scope }) {
  const store = storage();
  if (!store) return null;

  const trimmed = (name ?? '').trim();
  if (trimmed === '') return null;

  const views = listViews(listKey);

  // Saving over a name replaces it rather than creating a second entry with the
  // same label — two identical names in a dropdown is a bug the user files.
  const existing = views.findIndex((v) => v.name.toLowerCase() === trimmed.toLowerCase());

  const view = {
    // Unique WITHIN THE LIST, not merely timestamped. Two views saved in the same
    // millisecond shared an id, so deleting one deleted both — and React reused
    // the same key for two rows. Caught by the delete test, not by review.
    id: existing >= 0 ? views[existing].id : nextId(views),
    name: trimmed.slice(0, 60),
    filters: filters ?? {},
    scope: scope ?? null,
    createdAt: existing >= 0 ? views[existing].createdAt : new Date().toISOString(),
  };

  const next = existing >= 0
    ? views.map((v, i) => (i === existing ? view : v))
    : [view, ...views].slice(0, MAX_VIEWS);

  try {
    store.setItem(KEY_PREFIX + listKey, JSON.stringify(next));
  } catch {
    // Quota, or a browser that allows setItem probes but not real writes.
    return null;
  }

  return view;
}

export function deleteView(listKey, id) {
  const store = storage();
  if (!store) return false;

  try {
    store.setItem(KEY_PREFIX + listKey, JSON.stringify(listViews(listKey).filter((v) => v.id !== id)));
    return true;
  } catch {
    return false;
  }
}

/** Does the current filter state match a saved view? Drives the "active" highlight. */
export function matchView(views, filters, scope) {
  const normalise = (f) => JSON.stringify(
    Object.fromEntries(Object.entries(f ?? {}).filter(([, v]) =>
      v !== '' && v !== null && v !== undefined && !(Array.isArray(v) && v.length === 0)).sort()),
  );

  const target = normalise(filters);

  return views.find((v) => normalise(v.filters) === target && (v.scope ?? null) === (scope ?? null)) ?? null;
}

export const LIMITS = { MAX_VIEWS };
