/**
 * Review comment #45 — "STAFF MANAGEMENT: Auto log out after clicking".
 *
 * The root cause was server-side and is fixed: `routes/admin.php` chained a
 * second `->middleware()`, which REPLACES the first, so `auth:sanctum` was
 * silently dropped and `role:admin` saw a null user — returning 401
 * "Unauthenticated" to everyone, admins included. Every client treats 401 as an
 * expired token, so opening Staff Management signed the user straight out.
 *
 * This is the second half: a client that no longer ends the session for any 401
 * it happens to see. A permission problem must never cost someone their session,
 * and one mislabelled endpoint anywhere in the API should not be able to sign
 * the whole app out again.
 *
 * Shared by every axios client rather than copied into each — three separate
 * interceptors already redirect on 401, and three copies of this rule would
 * drift.
 */

/** Payload shapes that genuinely mean "your session is over". */
const AUTH_CODES = ['session_timed_out', 'token_expired', 'token_invalid']

export function isSessionFailure(error, hasToken) {
  if (error?.response?.status !== 401) return false

  // No token to begin with: nothing to end, and a protected page still needs to
  // send the visitor to the login screen.
  if (!hasToken) return true

  const data = error.response?.data || {}
  if (AUTH_CODES.includes(data.code)) return true

  const message = String(data.message || '').toLowerCase()

  // Laravel/Sanctum says "Unauthenticated."; our own middlewares say
  // "Unauthenticated"; token problems say so explicitly.
  return message.includes('unauthenticated')
    || message.includes('token')
    || message.includes('session has expired')
    || message.includes('session has timed out')
}
