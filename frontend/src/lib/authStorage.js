/**
 * Session persistence for auth. "Remember me" decides WHERE the session lives:
 *   - checked   → localStorage  (survives a browser restart)
 *   - unchecked → sessionStorage (cleared when the tab/browser closes)
 *
 * All reads fall back across both stores, so the rest of the app never has to
 * care which one holds the current session.
 */
const KEYS = ['crm_token', 'crm_user', 'crm_tenant', 'crm_remember']

export function setAuth({ token, user, tenant, remember }) {
  const store = remember ? localStorage : sessionStorage
  const other = remember ? sessionStorage : localStorage

  store.setItem('crm_token', token)
  store.setItem('crm_user', JSON.stringify(user))
  store.setItem('crm_tenant', JSON.stringify(tenant))
  store.setItem('crm_remember', remember ? '1' : '0')

  // Make sure a session never lingers in the other store.
  KEYS.forEach(k => other.removeItem(k))
}

export function getToken() {
  return localStorage.getItem('crm_token') || sessionStorage.getItem('crm_token')
}

function readJSON(key) {
  const raw = localStorage.getItem(key) ?? sessionStorage.getItem(key)
  try { return raw ? JSON.parse(raw) : null } catch { return null }
}

export const getUser = () => readJSON('crm_user')
export const getTenant = () => readJSON('crm_tenant')

/**
 * Whether this session was established with "Remember me".
 *
 * Mirrors `user_sessions.remember_me`, which the backend's EnforceIdleTimeout
 * middleware uses to exempt remembered sessions from the idle timeout — so the
 * client-side idle timer can make the same call.
 *
 * Deliberately strict: only the exact value written by setAuth() counts as
 * remembered. A session predating this key, or any unexpected value, reads as
 * NOT remembered, so the idle timeout still applies. Failing the other way
 * would silently turn an ordinary login into an unlimited one.
 */
export function getRemember() {
  const raw = localStorage.getItem('crm_remember') ?? sessionStorage.getItem('crm_remember')
  return raw === '1'
}

export function clearAuth() {
  KEYS.forEach(k => { localStorage.removeItem(k); sessionStorage.removeItem(k) })
}

/** Persist a refreshed user/tenant into whichever store currently holds the session. */
export function updateUserTenant(user, tenant) {
  const store = localStorage.getItem('crm_token') ? localStorage : sessionStorage
  store.setItem('crm_user', JSON.stringify(user))
  store.setItem('crm_tenant', JSON.stringify(tenant))
}
