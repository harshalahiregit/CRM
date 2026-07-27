/**
 * Purchase Vendor Portal authentication — hits /api/purchase-vendor/* and
 * /api/portal/purchase/logout. Authenticates ONLY a PurchaseVendor (never the
 * shared User / Vendor / TPV auth). Tokens live under a dedicated storage key.
 */
import pvApi, { pvToken } from '@/lib/purchaseVendorApi'

export const purchaseVendorAuthApi = {
  register:       (data)                  => pvApi.post('/purchase-vendor/register', data).then((r) => r.data),
  verifyEmail:    (token)                 => pvApi.post('/purchase-vendor/verify-email', { token }).then((r) => r.data),
  forgotPassword: (email)                 => pvApi.post('/purchase-vendor/forgot-password', { email }).then((r) => r.data),
  resetPassword:  (data)                  => pvApi.post('/purchase-vendor/reset-password', data).then((r) => r.data),

  async login(email, password) {
    const res = await pvApi.post('/purchase-vendor/login', { email, password }).then((r) => r.data)
    if (res?.access_token) pvToken.set(res.access_token)
    return res
  },

  me: () => pvApi.get('/portal/purchase/me').then((r) => r.data),

  async logout() {
    try { await pvApi.post('/portal/purchase/logout') } catch { /* token may already be gone */ }
    pvToken.clear()
  },

  token: pvToken,
}

export default purchaseVendorAuthApi
