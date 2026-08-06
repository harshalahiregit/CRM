// Polls — /api/polls/* (shared across Tasks / Helpdesk / Projects, owner: Shivam)
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

const unwrap = (r) => r.data?.data ?? r.data

export const pollApi = {
  // All polls hung on one context (a task / ticket / project), each already
  // reduced by the server to a per-user results payload.
  list: (contextType, contextId) =>
    api.get('/polls', { params: { context_type: contextType, context_id: contextId } }).then(unwrap).catch(handleErr),
  create: (payload) => api.post('/polls', payload).then(unwrap).catch(handleErr),
  // Set MY selection to exactly these option ids (empty clears my vote).
  vote: (pollId, optionIds) => api.post(`/polls/${pollId}/vote`, { option_ids: optionIds }).then(unwrap).catch(handleErr),
  close: (pollId) => api.post(`/polls/${pollId}/close`).then(unwrap).catch(handleErr),
  remove: (pollId) => api.delete(`/polls/${pollId}`).then(unwrap).catch(handleErr),
}

export default pollApi
