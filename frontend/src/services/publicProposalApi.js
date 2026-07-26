// Public portal endpoints — bare axios (no auth header, no 401 redirect).
import axios from 'axios'

const base = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/$/, '')

const handleErr = (err) => {
  const msg = err?.response?.data?.message || err?.response?.data?.error || 'Something went wrong'
  const e = new Error(msg)
  e.status = err?.response?.status
  e.data = err?.response?.data
  throw e
}

export const publicProposalApi = {
  get: (token, accessToken) =>
    axios.get(`${base}/public/proposals/${token}`, {
      headers: accessToken ? { 'X-Portal-Access': accessToken } : {},
    }).then(r => r.data).catch(handleErr),

  requestOtp: (token) =>
    axios.post(`${base}/public/proposals/${token}/request-otp`).then(r => r.data).catch(handleErr),

  verifyOtp: (token, code) =>
    axios.post(`${base}/public/proposals/${token}/verify-otp`, { code }).then(r => r.data).catch(handleErr),

  accept: (token, accessToken) =>
    axios.post(`${base}/public/proposals/${token}/accept`, {}, {
      headers: accessToken ? { 'X-Portal-Access': accessToken } : {},
    }).then(r => r.data).catch(handleErr),

  decline: (token, accessToken) =>
    axios.post(`${base}/public/proposals/${token}/decline`, {}, {
      headers: accessToken ? { 'X-Portal-Access': accessToken } : {},
    }).then(r => r.data).catch(handleErr),
}

export default publicProposalApi

// Public contract portal (same bare-axios pattern)
export const publicContractApi = {
  get: (token) =>
    axios.get(`${base}/public/contracts/${token}`).then(r => r.data).catch(handleErr),
  sign: (token, data) =>
    axios.post(`${base}/public/contracts/${token}/sign`, data).then(r => r.data).catch(handleErr),
  comment: (token, data) =>
    axios.post(`${base}/public/contracts/${token}/comments`, data).then(r => r.data).catch(handleErr),
  pdfUrl: (token) => `${base}/public/contracts/${token}/pdf`,
}
