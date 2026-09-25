/**
 * Public Career Portal API — no auth token (these endpoints are public and
 * tenant-scoped by the {slug} in the path).
 */
import axios from 'axios'
import { attachMediaCompression } from '@/lib/mediaCompress'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const api = axios.create({ baseURL: BASE })

// Uploads are shrunk on the way out — see src/lib/mediaCompress.js. Hooked
// here rather than at the ~50 upload sites, so every one is covered.
attachMediaCompression(api)

export const careersApi = {
  tenant: (slug)              => api.get(`/careers/${slug}`).then(r => r.data),
  jobs:   (slug, params = {}) => api.get(`/careers/${slug}/jobs`, { params }).then(r => r.data),
  job:    (slug, id)          => api.get(`/careers/${slug}/jobs/${id}`).then(r => r.data),
  apply:  (slug, id, formData) => api.post(`/careers/${slug}/jobs/${id}/apply`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  }).then(r => r.data),
  // Application tracking (public — matched by the applicant's own email/phone)
  status:       (slug, id, payload) => api.post(`/careers/${slug}/jobs/${id}/status`, payload).then(r => r.data),

  // NO OFFER HELPERS HERE, DELIBERATELY. respondOffer() and offerLetterUrl()
  // used to sit on this object, called by nothing, and neither carried the
  // offer token their endpoints require — offerLetterUrl() built a URL that
  // would have been rejected before it reached the letter.
  //
  // They are gone rather than repaired because this portal has no token to put
  // in them. The route is /careers/:slug/jobs/:id, nothing ever emails a
  // tokenised Careers URL, and the raw token must never be fetched from an API.
  // A page that cannot hold the credential must not host actions that need one:
  // Careers tracks the offer, and the private emailed link acts on it.
  //
  // The backend routes are untouched and still serve a candidate who holds
  // their token.
}

export default careersApi
