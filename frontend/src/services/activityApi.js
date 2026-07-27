// Unified sales activity timeline — /api/sales/activities
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const activityApi = {
  // subjectType is a short key: lead | proposal | estimate | invoice | credit_note | contract | task | customer
  feed: (subjectType, subjectId) =>
    api.get('/sales/activities', { params: { subject_type: subjectType, subject_id: subjectId } })
       .then(r => r.data).catch(handleErr),
}

export default activityApi
