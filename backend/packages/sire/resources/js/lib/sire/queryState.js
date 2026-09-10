/**
 * SIRE — one answer to "what do I render right now".
 *
 * Every list and panel in SIRE has the same five outcomes, and before this they
 * were each handled slightly differently: some showed a spinner on refetch, some
 * showed nothing on 403, some rendered an empty state when the request had
 * actually failed. That last one is the worst — "no issues match" and "we could
 * not reach the server" look identical and mean opposite things.
 *
 * Pure. No React, no components — it decides, SireStateBoundary renders.
 */

export const STATE = {
  LOADING: 'loading',
  ERROR: 'error',
  PERMISSION: 'permission',
  NOT_FOUND: 'not_found',
  OFFLINE: 'offline',
  EMPTY: 'empty',
  READY: 'ready',
};

const isEmptyValue = (data) => {
  if (data === null || data === undefined) return true;
  if (Array.isArray(data)) return data.length === 0;
  // A paginator: the page is what matters, not the wrapper.
  if (typeof data === 'object' && Array.isArray(data.data)) return data.data.length === 0;
  if (typeof data === 'object') return Object.keys(data).length === 0;
  return false;
};

/**
 * @param {object} q  a TanStack query result, plus optional {isEmpty}
 * @returns {{state: string, status: ?number, reference: ?string, retryable: boolean}}
 */
export function resolveQueryState(q = {}) {
  const { isLoading, isPending, isError, error, data, isEmpty } = q;

  // Only the FIRST load is a loading state. A background refetch keeps the last
  // good data on screen — replacing a populated table with a spinner every thirty
  // seconds is how a page starts feeling broken.
  if ((isLoading ?? isPending) && data === undefined) {
    return { state: STATE.LOADING, status: null, reference: null, retryable: false };
  }

  if (isError) {
    const status = error?.response?.status ?? null;
    // ApiErrorMapper returns a six-character reference that ties the message on
    // screen to the logged stack trace. Surfacing it turns "it broke" into
    // something support can actually look up.
    const reference = error?.response?.data?.reference ?? null;

    if (status === 403) return { state: STATE.PERMISSION, status, reference, retryable: false };
    if (status === 404) return { state: STATE.NOT_FOUND, status, reference, retryable: false };
    // No response at all means the network, not the server.
    if (!error?.response) return { state: STATE.OFFLINE, status: null, reference: null, retryable: true };

    // 5xx is worth retrying; 4xx is the caller's problem and will not fix itself.
    return { state: STATE.ERROR, status, reference, retryable: status >= 500 };
  }

  const empty = isEmpty !== undefined ? isEmpty : isEmptyValue(data);
  if (empty) return { state: STATE.EMPTY, status: null, reference: null, retryable: false };

  return { state: STATE.READY, status: null, reference: null, retryable: false };
}

/**
 * Copy for each state. Written once so SIRE says the same thing everywhere, and
 * so an error never gets mistaken for an empty result.
 */
export const STATE_COPY = {
  [STATE.PERMISSION]: {
    title: 'You do not have access to this',
    body: 'Ask an administrator if you think you should. Nothing was changed.',
  },
  [STATE.NOT_FOUND]: {
    title: 'Not found',
    body: 'This may have been deleted, or it may belong to another workspace.',
  },
  [STATE.OFFLINE]: {
    title: 'Could not reach the server',
    body: 'Check your connection and try again. Nothing you did was lost.',
  },
  [STATE.ERROR]: {
    title: 'Something went wrong',
    body: 'This has been logged. Quote the reference below if you report it.',
  },
};
