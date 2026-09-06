import axios from 'axios'
import { attachMediaCompression } from './mediaCompress'
import { isSessionFailure } from '@/lib/sessionFailure'

/**
 * Dedicated axios instance for the Purchase Vendor portal. It carries the
 * Purchase-vendor's OWN Sanctum token (separate storage key from the shared user
 * token), and 401s bounce to the Purchase vendor login — completely independent
 * of the shared user/vendor auth.
 */
const KEY = 'pv_portal_token'

export const pvToken = {
  get: () => localStorage.getItem(KEY),
  set: (t) => localStorage.setItem(KEY, t),
  clear: () => localStorage.removeItem(KEY),
  has: () => !!localStorage.getItem(KEY),
}

const pvApi = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
})

// Uploads are shrunk on the way out — see src/lib/mediaCompress.js. Hooked
// here rather than at the ~50 upload sites, so every one is covered.
attachMediaCompression(pvApi)

pvApi.interceptors.request.use((config) => {
  const t = pvToken.get()
  if (t) config.headers.Authorization = `Bearer ${t}`
  return config
})

pvApi.interceptors.response.use(
  (r) => r,
  (error) => {
    // #45 — same rule for the Purchase vendor portal: 403 ("this area is for
    // Purchase vendor accounts only") must not clear their token.
    if (isSessionFailure(error, !!pvToken.get())) {
      pvToken.clear()
      // The single login page — this portal no longer has one of its own.
      if (!window.location.pathname.startsWith('/auth/login')) {
        window.location.href = '/auth/login?role=purchase_vendor'
      }
    }
    return Promise.reject(error)
  },
)

export default pvApi
