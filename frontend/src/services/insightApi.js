// Rule-based sales insights (no LLM/ML) — /api/sales/…
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const insightApi = {
  winProbability: (leadId) =>
    api.get(`/sales/leads/${leadId}/win-probability`).then(r => r.data).catch(handleErr),

  nextBestAction: (leadId) =>
    api.get(`/sales/leads/${leadId}/next-best-action`).then(r => r.data).catch(handleErr),

  taskPriorities: (mine = false) =>
    api.get('/sales/insights/task-priorities', { params: { mine: mine ? 1 : 0 } })
       .then(r => r.data).catch(handleErr),
}

export default insightApi
