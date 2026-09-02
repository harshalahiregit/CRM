import { kickoffApi } from './kickoffApi'
import { purchaseKickoffApi } from './purchaseKickoffApi'

/**
 * The meeting API for whichever module the user is currently in.
 *
 * There are two meeting engines on separate tables — the shared one and
 * Purchase's — and one set of screens driving both. Those screens spread their
 * API calls across roughly fifteen sub-components, so threading the client down
 * as a prop or a context would mean touching every one of them and would make
 * the diff between "TPV's page" and "the shared page" much harder to read.
 *
 * Instead this resolves per CALL. Every property access checks the current path
 * and forwards to the right client, so `meetingEngineApi.list()` reaches
 * /kickoff/meetings under /app/tpv and /purchase/kickoff under /app/purchase.
 *
 * Why reading location directly is safe here: API calls happen in event
 * handlers and effects, which run after the router has already committed the
 * new location — never during module evaluation, when the path would be stale.
 * The one rule this imposes is that a caller must not hold onto a destructured
 * method across a module change (`const { list } = meetingEngineApi`), because
 * that captures one engine's function. Call through the object.
 *
 * Components that only need the module identity — a route base, a label, or
 * whether the module has projects — should use useMeetingModule() instead,
 * which reads the location through the router the normal way.
 */
const currentPath = () => (typeof window === 'undefined' ? '' : window.location.pathname)

const implFor = (pathname) =>
  pathname.startsWith('/app/purchase') ? purchaseKickoffApi : kickoffApi

/**
 * The route base for the module in the URL.
 *
 * The shared meeting screens linked to `/app/tpv/kickoff/...` in seventeen
 * places. Mounted under Purchase those would have walked the user out of the
 * module they were working in and shown a different company's meetings, so
 * every link is built from this instead.
 */
export const meetingBase = () =>
  currentPath().startsWith('/app/purchase') ? '/app/purchase' : '/app/tpv'

export const meetingEngineApi = new Proxy({}, {
  get(_target, prop) {
    const impl = implFor(currentPath())
    const value = impl[prop]

    // Nested groups (agenda, registers, decisions…) are plain objects on the
    // resolved client, so they come back already bound to the right engine.
    return typeof value === 'function' ? value.bind(impl) : value
  },
  has(_target, prop) {
    return prop in implFor(currentPath())
  },
})
