/**
 * Public site-gate API — no auth token (the badge QR {token} is the credential).
 *
 * Deliberately its own bare axios instance: the shared client attaches the
 * portal's bearer token and redirects to /auth/login on 401. A gate guard has
 * no login, so neither behaviour belongs here.
 */
import axios from 'axios'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const api = axios.create({ baseURL: BASE })

export const gateScanApi = {
  // Read-only — shows the pass card, never touches attendance.
  scan:     (token) => api.get(`/scan/${token}`).then(r => r.data),
  checkIn:  (token, gate) => api.post(`/scan/${token}/check-in`, { gate }).then(r => r.data),
  checkOut: (token, gate) => api.post(`/scan/${token}/check-out`, { gate }).then(r => r.data),
}

export default gateScanApi
