/**
 * SIRE — failed-request ring buffer.
 *
 * DELIBERATE DEVIATION from "only collect when the user activates Report Issue":
 * a failure that has already happened cannot be collected retroactively. This is
 * the one always-on piece, and it is bounded to stay honest to the spirit of the
 * rule:
 *
 *   - event-driven, never polled, never on a timer
 *   - at most MAX_ENTRIES records held, in memory only (no storage, no network)
 *   - metadata only: method, path, status, timestamp, correlation ref
 *   - NO request bodies, NO response bodies, NO headers other than the
 *     correlation id, NO query strings (dropped entirely, not redacted)
 *
 * Cost per failure is one small object and one array shift. Cost when nothing
 * fails is zero.
 */

const MAX_ENTRIES = 5;

/** Failures older than this are stale and not worth attaching to a report. */
const MAX_AGE_MS = 10 * 60 * 1000;

const buffer = [];

/** Header names checked, in order, for a server-side correlation id. */
const CORRELATION_HEADERS = ['x-request-id', 'x-correlation-id', 'x-trace-id'];

function pathOnly(url) {
  if (!url) return null;
  const withoutQuery = String(url).split('?')[0].split('#')[0];
  // Absolute -> path. Never keep the query string: the brief asks for
  // endpoint/path only, and dropping it removes a whole class of leak.
  const m = withoutQuery.match(/^https?:\/\/[^/]+(\/.*)$/);
  return m ? m[1] : withoutQuery;
}

/**
 * Call from the EXISTING axios response interceptor's error branch in
 * src/lib/api.js. Must never throw and must never alter the rejection.
 */
export function recordFailedRequest(error) {
  try {
    const config = error?.config || {};
    const response = error?.response;

    let correlationRef = null;
    if (response?.headers) {
      for (const h of CORRELATION_HEADERS) {
        const v = response.headers[h] ?? response.headers[h.toUpperCase()];
        if (v) { correlationRef = String(v).slice(0, 64); break; }
      }
    }
    // ApiErrorMapper returns a short reference alongside the friendly message.
    if (!correlationRef && typeof response?.data?.reference === 'string') {
      correlationRef = response.data.reference.slice(0, 64);
    }

    buffer.push({
      method: String(config.method || 'get').toUpperCase(),
      path: pathOnly(config.url),
      status: response?.status ?? 0, // 0 = network error / no response
      occurred_at: new Date().toISOString(),
      correlation_ref: correlationRef,
    });
    while (buffer.length > MAX_ENTRIES) buffer.shift();
  } catch {
    // Diagnostics must never break the app's error path.
  }
}

/** Most recent first, stale entries dropped. Read only on Report Issue. */
export function recentFailedRequests(limit = MAX_ENTRIES) {
  const cutoff = Date.now() - MAX_AGE_MS;
  return buffer
    .filter((e) => Date.parse(e.occurred_at) >= cutoff)
    .slice(-limit)
    .reverse();
}

export function clearFailedRequests() {
  buffer.length = 0;
}
