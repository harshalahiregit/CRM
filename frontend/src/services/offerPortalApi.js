/**
 * Public candidate Offer Letter portal API — no auth header (scoped by {token}).
 *
 * TWO CREDENTIALS REACH THE SAME OFFER, and the only difference between them is
 * the URL they live under:
 *
 *   offerPortalApi       /offer/{offerToken}            — the emailed offer link
 *   onboardingOfferApi   /onboarding/{onboardingToken}/offer
 *                                                       — the Offer tab inside
 *                                                         the onboarding portal
 *
 * The second one exists because the onboarding portal used to be handed the
 * offer's own raw token to render that tab, which meant a candidate who opened
 * one link ended up holding two separate secrets. It now uses the link it
 * already arrived with. Same actions, same payloads, same backend service —
 * only the resolver on the other end differs, so both are built from one
 * definition here rather than kept in step by hand.
 */
import axios from 'axios'
import { attachMediaCompression } from '@/lib/mediaCompress'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const api = axios.create({ baseURL: BASE })

// Uploads are shrunk on the way out — see src/lib/mediaCompress.js. Hooked
// here rather than at the ~50 upload sites, so every one is covered.
attachMediaCompression(api)

/** One portal's worth of calls, given how that portal builds its root path. */
const client = (root) => ({
  get:     (token) => api.get(root(token)).then(r => r.data),
  accept:  (token, payload = {}) => api.post(`${root(token)}/accept`, { agreed: true, ...payload }).then(r => r.data),
  decline: (token, reason) => api.post(`${root(token)}/decline`, { reason }).then(r => r.data),
  clarify: (token, message) => api.post(`${root(token)}/clarify`, { message }).then(r => r.data),
  letterUrl: (token) => `${BASE}${root(token)}/letter`,
  task: (token, key, value, file) => {
    const fd = new FormData()
    fd.append('key', key)
    if (value != null) fd.append('value', value)
    if (file) fd.append('file', file)
    return api.post(`${root(token)}/tasks`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data)
  },
})

export const offerPortalApi = client(t => `/offer/${t}`)

export const onboardingOfferApi = client(t => `/onboarding/${t}/offer`)

export default offerPortalApi
