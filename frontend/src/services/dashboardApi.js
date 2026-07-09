// Sales + HR dashboard stats
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const dashboardApi = {
  sales: {
    get: () => api.get('/sales/dashboard').then(r => r.data).catch(handleErr),
  },
  hr: {
    get: () => api.get('/hr/dashboard').then(r => r.data).catch(handleErr),
  },
  main: {
    get: () => api.get('/dashboard').then(r => r.data).catch(handleErr),
  },
}

export default dashboardApi
