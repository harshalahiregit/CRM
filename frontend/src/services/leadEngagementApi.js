// Lead profile: attachments, email activity, custom fields, appointments.
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const leadEngagementApi = {
  attachments: {
    list: (leadId) =>
      api.get(`/sales/leads/${leadId}/attachments`).then(r => r.data).catch(handleErr),
    upload: (leadId, file) => {
      const fd = new FormData()
      fd.append('file', file)
      return api.post(`/sales/leads/${leadId}/attachments`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }).then(r => r.data).catch(handleErr)
    },
    remove: (leadId, attachmentId) =>
      api.delete(`/sales/leads/${leadId}/attachments/${attachmentId}`).then(r => r.data).catch(handleErr),
  },

  emails: {
    list: (leadId) =>
      api.get(`/sales/leads/${leadId}/emails`).then(r => r.data).catch(handleErr),
    send: (leadId, data) =>
      api.post(`/sales/leads/${leadId}/emails`, data).then(r => r.data).catch(handleErr),
  },

  customFields: {
    // Returns definitions merged with this lead's values, so inputs render even
    // for fields the lead has no value for yet.
    get: (leadId) =>
      api.get(`/sales/leads/${leadId}/custom-fields`).then(r => r.data).catch(handleErr),
    save: (leadId, values) =>
      api.put(`/sales/leads/${leadId}/custom-fields`, { values }).then(r => r.data).catch(handleErr),
  },
}

// Polymorphic by design — 'lead' today, other subjects later.
export const appointmentApi = {
  list: (subjectType, subjectId) =>
    api.get('/sales/appointments', { params: { subject_type: subjectType, subject_id: subjectId } })
      .then(r => r.data).catch(handleErr),
  upcoming: (days = 14) =>
    api.get('/sales/appointments/upcoming', { params: { days } }).then(r => r.data).catch(handleErr),
  create: (data) =>
    api.post('/sales/appointments', data).then(r => r.data).catch(handleErr),
  update: (id, data) =>
    api.put(`/sales/appointments/${id}`, data).then(r => r.data).catch(handleErr),
  complete: (id, data) =>
    api.patch(`/sales/appointments/${id}/complete`, data).then(r => r.data).catch(handleErr),
  remove: (id) =>
    api.delete(`/sales/appointments/${id}`).then(r => r.data).catch(handleErr),
}

export default leadEngagementApi
