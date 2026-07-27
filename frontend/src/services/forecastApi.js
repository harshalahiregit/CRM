// Sales forecasting (computed) — /api/sales/forecast/*
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const forecastApi = {
  revenue: (period = 'monthly') =>
    api.get('/sales/forecast/revenue', { params: { period } }).then(r => r.data).catch(handleErr),
  pipeline: () =>
    api.get('/sales/forecast/pipeline').then(r => r.data).catch(handleErr),
  funnel: () =>
    api.get('/sales/forecast/funnel').then(r => r.data).catch(handleErr),
}

export default forecastApi
