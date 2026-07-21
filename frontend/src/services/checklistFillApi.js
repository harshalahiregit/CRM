/**
 * Public checklist fill-in API — no auth token (the 48-char {token} is the
 * credential).
 *
 * Deliberately its own bare axios instance, same reasoning as gateScanApi: the
 * shared client attaches the portal's bearer token and redirects to /auth/login
 * on 401. A vendor's site supervisor has no login, so neither behaviour belongs
 * here — a redirect would strand them on a login screen they can never pass.
 */
import axios from 'axios'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const api = axios.create({ baseURL: BASE })

export const checklistFillApi = {
  // Read-only — renders the form, never mutates.
  form: (token) => api.get(`/checklists/fill/${token}`).then(r => r.data),

  // Save progress. A site walk takes longer than one request.
  save: (token, responses) => api.post(`/checklists/fill/${token}/save`, { responses }).then(r => r.data),

  /**
   * Submit in two steps, deliberately.
   *
   * The selfie forces multipart, and multipart stringifies every field — a
   * boolean answer would reach the server as "true" and fail the evaluator's
   * strict is_bool check. So the answers go first as JSON, where a boolean stays
   * a boolean, and the multipart request then carries only the media and the
   * coordinates. The server falls back to the saved answers on submit.
   *
   * Saving first also means a submission rejected for a missing required answer
   * leaves the supervisor's work intact rather than emptying the form they just
   * spent twenty minutes filling in on a phone.
   *
   * Note what is NOT sent: score, risk band, critical failures. The server
   * computes those; posting them here would be ignored.
   */
  submit: async (token, { responses, latitude, longitude, selfie }) => {
    await api.post(`/checklists/fill/${token}/save`, { responses })

    const fd = new FormData()
    if (latitude != null) fd.append('latitude', String(latitude))
    if (longitude != null) fd.append('longitude', String(longitude))
    if (selfie) fd.append('selfie', selfie)

    return api.post(`/checklists/fill/${token}`, fd).then(r => r.data)
  },
}

export default checklistFillApi
