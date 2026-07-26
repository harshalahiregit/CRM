// Sales forecasting (computed) — /api/sales/forecast/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const forecastApi = {
  revenue: (period = 'monthly') =>
    api.get('/sales/forecast/revenue', { params: { period } }).then(r => r.data).catch(handleErr),
  pipeline: () =>
    api.get('/sales/forecast/pipeline').then(r => r.data).catch(handleErr),
  funnel: () =>
    api.get('/sales/forecast/funnel').then(r => r.data).catch(handleErr),
}

export default forecastApi
