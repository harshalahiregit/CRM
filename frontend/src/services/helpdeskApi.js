// Helpdesk & Support — /api/helpdesk/* (owner: Shivam)
// Mirrors the per-resource service pattern (itemApi, leadApi). The backend wraps
// responses in { status, message, data }, so every call unwraps `.data.data`.
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.message || err?.response?.data?.error || 'Something went wrong'
  throw new Error(msg)
}

// Unwrap the ApiResponse envelope down to its `data` payload.
const unwrap = (r) => r.data?.data ?? r.data

export const helpdeskApi = {
  // Manager analytics dashboard
  analytics: () =>
    api.get('/helpdesk/analytics').then(unwrap).catch(handleErr),

  tickets: {
    list: (params = {}) =>
      api.get('/helpdesk/tickets', { params }).then(unwrap).catch(handleErr),

    get: (id) =>
      api.get(`/helpdesk/tickets/${id}`).then(unwrap).catch(handleErr),

    create: (data) =>
      api.post('/helpdesk/tickets', data).then(unwrap).catch(handleErr),

    update: (id, data) =>
      api.put(`/helpdesk/tickets/${id}`, data).then(unwrap).catch(handleErr),

    remove: (id) =>
      api.delete(`/helpdesk/tickets/${id}`).then(unwrap).catch(handleErr),

    setStatus: (id, status) =>
      api.patch(`/helpdesk/tickets/${id}/status`, { status }).then(unwrap).catch(handleErr),

    assign: (id, assignedTo) =>
      api.patch(`/helpdesk/tickets/${id}/assign`, { assigned_to: assignedTo }).then(unwrap).catch(handleErr),

    feedback: (id, data) =>
      api.post(`/helpdesk/tickets/${id}/feedback`, data).then(unwrap).catch(handleErr),

    // Conversation thread
    replies: (id) =>
      api.get(`/helpdesk/tickets/${id}/replies`).then(unwrap).catch(handleErr),

    // `payload` is a FormData (message + sender_type + attachments[] files).
    // Overriding Content-Type lets axios set the multipart boundary itself.
    reply: (id, payload) =>
      api.post(`/helpdesk/tickets/${id}/replies`, payload, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }).then(unwrap).catch(handleErr),

    // Token-auth download: fetch the file as a blob (so the Bearer header is
    // sent), then trigger a browser save. A plain <a href> can't carry the token.
    downloadAttachment: (ticketId, attachmentId, fileName) =>
      api.get(`/helpdesk/tickets/${ticketId}/attachments/${attachmentId}/download`, { responseType: 'blob' })
        .then((r) => {
          const url = window.URL.createObjectURL(r.data)
          const a = document.createElement('a')
          a.href = url
          a.download = fileName || 'attachment'
          document.body.appendChild(a)
          a.click()
          a.remove()
          window.URL.revokeObjectURL(url)
        })
        .catch(handleErr),
  },

  kb: {
    categories: () =>
      api.get('/helpdesk/kb/categories').then(unwrap).catch(handleErr),

    createCategory: (data) =>
      api.post('/helpdesk/kb/categories', data).then(unwrap).catch(handleErr),

    updateCategory: (id, data) =>
      api.put(`/helpdesk/kb/categories/${id}`, data).then(unwrap).catch(handleErr),

    deleteCategory: (id) =>
      api.delete(`/helpdesk/kb/categories/${id}`).then(unwrap).catch(handleErr),

    articles: (params = {}) =>
      api.get('/helpdesk/kb/articles', { params }).then(unwrap).catch(handleErr),

    article: (id) =>
      api.get(`/helpdesk/kb/articles/${id}`).then(unwrap).catch(handleErr),

    createArticle: (data) =>
      api.post('/helpdesk/kb/articles', data).then(unwrap).catch(handleErr),

    updateArticle: (id, data) =>
      api.put(`/helpdesk/kb/articles/${id}`, data).then(unwrap).catch(handleErr),

    deleteArticle: (id) =>
      api.delete(`/helpdesk/kb/articles/${id}`).then(unwrap).catch(handleErr),

    vote: (id, direction) =>
      api.patch(`/helpdesk/kb/articles/${id}/vote`, { direction }).then(unwrap).catch(handleErr),
  },
}

export default helpdeskApi
