// Follow-ups / Reminders — /api/sales/reminders/*
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const followUpApi = {
  list: (params = {}) =>
    api.get('/sales/reminders', { params }).then(r => r.data).catch(handleErr),

  forSubject: (subjectType, subjectId) =>
    api.get('/sales/reminders', { params: { subject_type: subjectType, subject_id: subjectId } })
       .then(r => r.data).catch(handleErr),

  upcoming: () =>
    api.get('/sales/reminders/upcoming').then(r => r.data).catch(handleErr),

  create: (data) =>
    api.post('/sales/reminders', data).then(r => r.data).catch(handleErr),

  update: (id, data) =>
    api.put(`/sales/reminders/${id}`, data).then(r => r.data).catch(handleErr),

  delete: (id) =>
    api.delete(`/sales/reminders/${id}`).then(r => r.data).catch(handleErr),

  complete: (id, data = {}) =>
    api.patch(`/sales/reminders/${id}/complete`, data).then(r => r.data).catch(handleErr),
}

export default followUpApi
