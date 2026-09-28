// The one door to STOS. Same REST API the web app uses, same database — so
// whatever the driver files here appears in the office instantly, because it is
// the same system. Auth is a bearer token, attached to every call.
import { getToken, getBaseUrl } from './storage'

async function request(path, { method = 'GET', body, isForm = false } = {}) {
  const base = (await getBaseUrl()) || ''
  if (!base) {
    const e = new Error('No server address set. Enter it on the login screen.')
    e.status = 0
    throw e
  }

  const token = await getToken()
  const headers = { Accept: 'application/json' }
  if (token) headers.Authorization = `Bearer ${token}`

  let payload
  if (isForm) {
    payload = body // FormData — the browser/RN sets the multipart boundary itself.
  } else if (body) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  let res
  try {
    res = await fetch(`${base}/api${path}`, { method, headers, body: payload })
  } catch (networkError) {
    const e = new Error('Could not reach the server. Check the server address and your internet connection.')
    e.status = 0
    throw e
  }

  const text = await res.text()
  let json = {}
  try { json = text ? JSON.parse(text) : {} } catch { json = { message: text } }

  if (!res.ok) {
    const e = new Error(json?.message || `Request failed (${res.status})`)
    e.status = res.status
    e.data = json
    throw e
  }

  // The API wraps everything in { status, message, data } — hand back the data.
  return json?.data ?? json
}

export const api = {
  login: (email, password) => request('/auth/login', { method: 'POST', body: { email, password } }),

  // Self-registration — files a request the office must approve before the
  // driver can sign in. Public, no token needed.
  register: (data) => request('/driver/register', { method: 'POST', body: data }),

  // Forgot password — the office resets it from the board; this just tells them.
  forgotPassword: (email) => request('/driver/forgot-password', { method: 'POST', body: { email } }),

  // Trips. NOTE: /transport/trips is the office list today; a driver-scoped
  // "my trips" endpoint is Dev 1's to add (see the message in docs). For now
  // the app shows open trips and will point at the driver endpoint when it
  // lands — the screen code does not change, only this URL.
  trips: (params = {}) => {
    const q = new URLSearchParams(params).toString()
    return request(`/transport/trips${q ? `?${q}` : ''}`)
  },
  trip: (id) => request(`/transport/trips/${id}`),
  tripDocuments: (id) => request(`/transport/trips/${id}/documents`),

  // POD — the one trip action a driver is allowed today. Multipart upload of
  // the signed sheet's photo.
  uploadPod: (id, formData) => request(`/transport/trips/${id}/pod`, { method: 'POST', body: formData, isForm: true }),

  // ── The driver's own profile & documents (Phase C) ──────────────────────
  // Self-scoped: the server resolves "me" from the token, so there is no id to
  // pass. Returns profile, documents, the types that can be filed, and whether
  // the driver is cleared to drive.
  me: () => request('/v1/me/driver'),
  uploadDocument: (formData) => request('/v1/me/driver/documents', { method: 'POST', body: formData, isForm: true }),

  // ── The trip journey (Phase F) — Dev 1 (Ops) endpoints ──────────────────
  // These write to the trip/milestone machine, which is Dispatch's. Until the
  // office enables them on the server they answer 404/405; the screens catch
  // that and say "not switched on yet" rather than failing hard. The exact
  // contract is in docs/transport/DRIVER-APP-TRIP-ACTIONS-CONTRACT.md.
  submitPretrip: (id, body) => request(`/transport/trips/${id}/pretrip`, { method: 'POST', body }),
  reportIncident: (id, formData) => request(`/transport/trips/${id}/incidents`, { method: 'POST', body: formData, isForm: true }),
  handoverFeedback: (id, body) => request(`/transport/trips/${id}/handover-feedback`, { method: 'POST', body }),
}

// True when a call failed only because the endpoint is not enabled yet (not a
// real error) — the screens use this to show "coming soon", not "it broke".
export function isNotEnabled(e) {
  return e?.status === 404 || e?.status === 405 || e?.status === 501
}
