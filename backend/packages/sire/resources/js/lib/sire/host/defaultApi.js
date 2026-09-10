/**
 * SIRE — the HTTP client used when the host registers none.
 *
 * Built on fetch, so it adds no dependency. A host that registers its own axios
 * instance gets that instead, and should: SIRE then inherits the base URL, auth
 * header, CSRF handling, interceptors and error reporting the rest of the
 * application already uses, which is most of what makes a module feel native.
 *
 * The shape is axios-compatible on purpose — `.get()`, `.post()`, and a response
 * with `.data` — so `sireApi.js` reads identically whichever client is behind
 * it, and swapping one for the other changes nothing downstream.
 *
 * WHAT IT DOES FOR YOU
 *
 *   - sends and reads JSON
 *   - forwards cookies (same-origin), so session auth works untouched
 *   - reads the XSRF-TOKEN cookie into X-XSRF-TOKEN, so Laravel's CSRF
 *     protection works untouched
 *   - throws an axios-shaped error, so SIRE's error handling is one code path
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *
 * No retries, no refresh-token dance, no global error toast. Those are policy,
 * they differ per application, and a module inventing them would fight whatever
 * the host already does.
 */

const readCookie = (name) => {
  // Guarded: SIRE components are sometimes rendered server-side or in tests,
  // where document does not exist.
  if (typeof document === 'undefined') return null;

  const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`));
  return match ? decodeURIComponent(match[2]) : null;
};

const request = async (method, url, { params, data, headers = {} } = {}) => {
  const query = params
    ? `?${new URLSearchParams(
        Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''),
      )}`
    : '';

  const csrf = readCookie('XSRF-TOKEN');

  const response = await fetch(`${url}${query}`, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(data instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
      ...(csrf ? { 'X-XSRF-TOKEN': csrf } : {}),
      ...headers,
    },
    body: data === undefined ? undefined : (data instanceof FormData ? data : JSON.stringify(data)),
  });

  // 204 has no body; parsing it throws, and the throw would surface as a
  // network error rather than the success it is.
  const payload = response.status === 204 ? null : await response.json().catch(() => null);

  if (!response.ok) {
    // Axios-shaped, so every caller's error handling works unchanged.
    const error = new Error(payload?.message ?? `Request failed with status ${response.status}`);
    error.response = { status: response.status, data: payload };
    throw error;
  }

  return { data: payload, status: response.status };
};

export const defaultApi = {
  get:    (url, config) => request('GET', url, config),
  post:   (url, data, config) => request('POST', url, { ...config, data }),
  put:    (url, data, config) => request('PUT', url, { ...config, data }),
  patch:  (url, data, config) => request('PATCH', url, { ...config, data }),
  delete: (url, config) => request('DELETE', url, config),
};

export default defaultApi;
