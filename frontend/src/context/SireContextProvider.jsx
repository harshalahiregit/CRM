/**
 * SIRE — context provider + global Report Issue state.
 *
 * Mount ONCE, high in the tree (App.jsx, inside AuthProvider and the Router).
 * Pages do not need to know it exists: route detection covers them. <SireContext>
 * is the escape hatch for screens the route map cannot describe.
 */
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { SireScreenContext } from '../lib/sire/screenContext';

const SireReportContext = createContext(null);

export function SireContextProvider({ children }) {
  /**
   * The declaration stack lives OUTSIDE React, in lib/sire/screenContext.js, so
   * that a host can declare context from anywhere it has code — a route guard,
   * a saga, an imperative wizard controller — and not only from a component.
   *
   * The provider does not own it and does not subscribe to it. Registering a
   * context re-renders nothing; Report Issue reads the stack at the moment it
   * opens. That is what keeps context capture free until somebody uses it.
   */
  const [modal, setModal] = useState({ open: false, seed: null });

  // True only while getDisplayMedia is running. The modal is CLOSED during a
  // capture (otherwise the screenshot is a picture of the report form) and the
  // floating trigger hides too, so neither appears in the shot. The user's typed
  // text rides along in `seed` and is restored when the modal reopens.
  const [capturing, setCapturing] = useState(false);

  const register = useCallback((entry) => SireScreenContext.register(entry), []);

  const explicitContext = useCallback(() => SireScreenContext.current(), []);

  const openReportIssue = useCallback((seed = null) => setModal({ open: true, seed }), []);
  const closeReportIssue = useCallback(() => setModal({ open: false, seed: null }), []);

  const value = useMemo(
    () => ({ register, explicitContext, openReportIssue, closeReportIssue, modal, capturing, setCapturing }),
    [register, explicitContext, openReportIssue, closeReportIssue, modal, capturing],
  );

  return <SireReportContext.Provider value={value}>{children}</SireReportContext.Provider>;
}

/** Internal — used by the modal/button. */
export function useSireReporting() {
  const ctx = useContext(SireReportContext);
  if (!ctx) {
    throw new Error('SIRE: <SireContextProvider> is missing from the tree.');
  }
  return ctx;
}

/** Public — open the report modal from anywhere, e.g. an error boundary. */
export function useReportIssue() {
  const ctx = useContext(SireReportContext);
  return ctx ? ctx.openReportIssue : () => {};
}

/**
 * Optional per-screen context. Renders nothing.
 *
 * TWO WAYS TO USE IT, ONE NAME.
 *
 * Declaratively, in a component tree:
 *
 *   <SireContext module="sales" section="leads" screen="lead-details"
 *                entityType="lead" entityId={lead.id} />
 *
 * Or imperatively, from anywhere at all:
 *
 *   const done = SireContext.register({
 *     module: 'sales', section: 'leads', screen: 'lead-details',
 *     entityType: 'lead', entityId: 10452,
 *   });
 *   // ... later, when the screen goes away:
 *   done();
 *
 * Both feed the same stack. The imperative form exists because not every host
 * routes through React: a route guard, a saga or a legacy island has no
 * component to hang this on, and telling those hosts "wrap it in a provider" is
 * telling them to restructure their application to suit SIRE.
 *
 * Use either only where route detection genuinely cannot tell — a wizard whose
 * step lives in component state, a console whose entity is not in the URL. If
 * the route already identifies the screen, this is redundant.
 */
export function SireContext({
  module,
  section,
  screen,
  entityType,
  entityId,
  moduleLabel,
  sectionLabel,
  screenLabel,
  pageContext,
}) {
  const { register } = useSireReporting();

  // One stable object per mount, mutated in place on prop changes, so a changing
  // entityId does not churn the stack.
  const entryRef = useRef({});
  entryRef.current.module = module ?? null;
  entryRef.current.section = section ?? null;
  entryRef.current.screen = screen ?? null;
  entryRef.current.entityType = entityType ?? null;
  entryRef.current.entityId = entityId != null ? String(entityId) : null;
  entryRef.current.moduleLabel = moduleLabel ?? null;
  entryRef.current.sectionLabel = sectionLabel ?? null;
  entryRef.current.screenLabel = screenLabel ?? null;
  entryRef.current.pageContext = pageContext ?? null;

  useEffect(() => register(entryRef.current), [register]);

  return null;
}

/**
 * The imperative half of the same API, attached to the component so there is one
 * name to learn and one thing to import.
 *
 * SireContext.register({...})  declare, returns an unregister function
 * SireContext.current()        the innermost declaration
 * SireContext.all()            the whole stack, for diagnosing cleanup bugs
 * SireContext.clear()          drop everything, for hosts that route imperatively
 */
SireContext.register = SireScreenContext.register;
SireContext.current = SireScreenContext.current;
SireContext.all = SireScreenContext.all;
SireContext.clear = SireScreenContext.clear;

export default SireContextProvider;
