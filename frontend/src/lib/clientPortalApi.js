import axios from 'axios'
import { isSessionFailure } from './sessionFailure'

/**
 * Axios instance for the Customer portal.
 *
 * Its own Sanctum token under its own storage key, so a customer contact's
 * session is completely independent of staff, vendor and purchase-vendor auth.
 * Someone can be signed into the CRM as staff in one tab and into a customer
 * portal in another without either clobbering the other.
 */
const KEY = 'client_portal_token'

export const clientToken = {
  get: () => localStorage.getItem(KEY),
  set: (t) => localStorage.setItem(KEY, t),
  clear: () => localStorage.removeItem(KEY),
  has: () => !!localStorage.getItem(KEY),
}

const cpApi = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
})

cpApi.interceptors.request.use((config) => {
  const t = clientToken.get()
  if (t) config.headers.Authorization = `Bearer ${t}`
  return config
})

cpApi.interceptors.response.use(
  (r) => r,
  (error) => {
    // A 403 here usually means "you lack permission for this section", not
    // "your session is dead" — clearing the token on that would sign someone
    // out for clicking a tab they were never granted.
    if (isSessionFailure(error, !!clientToken.get())) {
      clientToken.clear()
      if (!window.location.pathname.startsWith('/portal/login')) {
        window.location.href = '/portal/login'
      }
    }
    return Promise.reject(error)
  },
)

const get = (p) => cpApi.get(p).then((r) => r.data)

export const clientPortalApi = {
  // ── auth (unauthenticated) ──
  login: (email, password) =>
    cpApi.post('/client-portal/login', { email, password }).then((r) => {
      const d = r.data?.data ?? r.data
      if (d?.access_token) clientToken.set(d.access_token)
      return d
    }),
  forgotPassword: (email) => cpApi.post('/client-portal/forgot-password', { email }).then((r) => r.data),
  setPassword: (payload) => cpApi.post('/client-portal/set-password', payload).then((r) => r.data),

  async logout() {
    try { await cpApi.post('/portal/client/logout') } catch { /* token may already be gone */ }
    clientToken.clear()
  },

  // ── session ──
  me: () => get('/portal/client/me'),
  dashboard: () => get('/portal/client/dashboard'),
  updateProfile: (data) => cpApi.put('/portal/client/profile', data).then((r) => r.data),
  changePassword: (data) => cpApi.post('/portal/client/change-password', data).then((r) => r.data),

  // ── records (each gated server-side on the contact's permissions) ──
  invoices: (filter) => get('/portal/client/invoices' + (filter ? `?filter=${filter}` : '')),
  payments: () => get('/portal/client/payments'),
  creditNotes: () => get('/portal/client/credit-notes'),
  statement: () => get('/portal/client/statement'),
  estimates: () => get('/portal/client/estimates'),
  proposals: () => get('/portal/client/proposals'),
  contracts: () => get('/portal/client/contracts'),
  projects: () => get('/portal/client/projects'),
  tickets: () => get('/portal/client/tickets'),
  notes: () => get('/portal/client/notes'),
  files: () => get('/portal/client/files'),
  contacts: () => get('/portal/client/contacts'),

  // §10 — the customer's own satisfaction responses.
  feedback: {
    mine:   () => get('/portal/client/feedback'),
    submit: (payload) => cpApi.post('/portal/client/feedback', payload).then((r) => r.data),
  },

  token: clientToken,
}

export default cpApi
