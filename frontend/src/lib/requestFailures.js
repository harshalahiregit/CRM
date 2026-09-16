/**
 * A record of requests that failed for a reason the page could not show.
 *
 * Nearly every portal screen fetches like this:
 *
 *     api.workers.list().then(setRows).catch(() => setRows([]))
 *
 * which turns a 500 into an empty list. That is how the TPV portal's Gate Log,
 * Attendance and Contacts tabs stayed broken for months: each one rendered "no
 * records yet" to a vendor who had plenty, and nobody could tell an account with
 * nothing in it from an endpoint that was throwing.
 *
 * Rewriting all sixty-odd of those call sites would be a large, risky change to
 * pages that otherwise work. This is the small one: the axios clients report
 * failures here, and the portal shells show a banner. The page still renders its
 * empty state — but the person reading it is told that something did not load,
 * rather than being quietly misinformed.
 *
 * Deliberately NOT a React context: the reporters are axios interceptors, which
 * live outside the tree and cannot reach one.
 */

const listeners = new Set()
let failures = []

/** 401 is handled by redirecting; 4xx is the page's own business to display. */
const isWorthReporting = (error) => {
  const status = error?.response?.status
  if (status === undefined) return true            // network / CORS / timeout
  if (status === 401 || status === 403) return false
  return status >= 500
}

const emit = () => listeners.forEach((fn) => fn(failures))

export function recordRequestFailure(error) {
  if (!isWorthReporting(error)) return

  const url = error?.config?.url || 'a request'
  const status = error?.response?.status ?? 'network'

  // One entry per endpoint. A dashboard firing eight calls that all fail should
  // read as one problem, not eight.
  if (failures.some((f) => f.url === url)) return

  failures = [...failures, { url, status, at: Date.now() }]
  emit()
}

export function clearRequestFailures() {
  if (failures.length === 0) return
  failures = []
  emit()
}

export function subscribeToRequestFailures(fn) {
  listeners.add(fn)
  fn(failures)

  return () => listeners.delete(fn)
}

/** Read once, for a component mounting after the failures happened. */
export function currentRequestFailures() {
  return failures
}
