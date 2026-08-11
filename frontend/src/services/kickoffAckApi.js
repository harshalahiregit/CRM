/**
 * Public kickoff-minutes acknowledgement API — no auth token (the 48-char
 * {token} is the credential).
 *
 * Its own bare axios instance, same reasoning as gateScanApi / checklistFillApi:
 * the shared client attaches the portal bearer and redirects to /auth/login on
 * 401. A vendor's signatory has no login, so a redirect would strand them.
 */
import axios from 'axios'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const api = axios.create({ baseURL: BASE })

export const kickoffAckApi = {
  show:        (token)       => api.get(`/kickoff/ack/${token}`).then(r => r.data),
  acknowledge: (token, name) => api.post(`/kickoff/ack/${token}`, { name }).then(r => r.data),
  // Reading the minutes is repeatable — this never burns the token, unlike
  // acknowledge(). Blob rather than a raw href so a failure surfaces as a
  // message instead of the browser showing its own error page.
  mom:         (token)       => api.get(`/kickoff/ack/${token}/mom`, { responseType: 'blob' }).then(r => r.data),
}

export default kickoffAckApi
