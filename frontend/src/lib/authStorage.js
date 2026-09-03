/**
 * Session persistence for auth. The token always lives in localStorage so EVERY
 * tab shares one session — a tab opened from an email deep-link recognises the
 * existing login and lands on the target page instead of bouncing to login.
 *
 * "Remember me" no longer changes WHERE the session is stored; it is recorded as
 * a flag that the idle-timeout uses to decide whether this session may sit idle
 * indefinitely (remembered) or is signed out after the idle window (not). So a
 * non-remembered session is still protected — by the idle timeout on the server,
 * not by dying with the tab.
 *
 * All reads still fall back across both stores, so any older sessionStorage-only
 * session keeps working until the next login migrates it to localStorage.
 */
const KEYS = ['crm_token', 'crm_user', 'crm_tenant', 'crm_remember']

export function setAuth({ token, user, tenant, remember }) {
  localStorage.setItem('crm_token', token)
  localStorage.setItem('crm_user', JSON.stringify(user))
  localStorage.setItem('crm_tenant', JSON.stringify(tenant))
  localStorage.setItem('crm_remember', remember ? '1' : '0')

  // A session must never linger in sessionStorage (older builds wrote there for
  // non-remembered logins); otherwise a stale copy could shadow this one.
  KEYS.forEach(k => sessionStorage.removeItem(k))
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
