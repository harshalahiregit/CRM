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
    const e = new Error('Could not reach the server. Check the address and that you are on the same network.')
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
}
