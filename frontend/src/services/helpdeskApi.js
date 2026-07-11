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

  // Support settings — priorities / statuses / departments + public-form settings (Phase 1)
  settings: {
    all: () => api.get('/helpdesk/settings').then(unwrap).catch(handleErr),
    createItem: (type, data) => api.post(`/helpdesk/settings/${type}`, data).then(unwrap).catch(handleErr),
    updateItem: (type, id, data) => api.put(`/helpdesk/settings/${type}/${id}`, data).then(unwrap).catch(handleErr),
    deleteItem: (type, id) => api.delete(`/helpdesk/settings/${type}/${id}`).then(unwrap).catch(handleErr),
    reorder: (type, ids) => api.patch(`/helpdesk/settings/${type}/reorder`, { ids }).then(unwrap).catch(handleErr),
    updateGeneral: (data) => api.put('/helpdesk/settings/general', data).then(unwrap).catch(handleErr),
  },

  // Tickets assigned to the current user (their task list)
  myTasks: () =>
    api.get('/helpdesk/my-tasks').then(unwrap).catch(handleErr),

  // Embeddable widget settings (admin)
  widget: {
    get: () => api.get('/helpdesk/widget').then(unwrap).catch(handleErr),
    update: (data) => api.put('/helpdesk/widget', data).then(unwrap).catch(handleErr),
    rotate: () => api.post('/helpdesk/widget/rotate').then(unwrap).catch(handleErr),
  },

  // Public (no-auth) surfaces — widget submit + public KB
  public: {
    submitTicket: (key, data) =>
      api.post(`/helpdesk/public/widget/${key}/tickets`, data).then(unwrap).catch(handleErr),
    kbTree: (key) =>
      api.get(`/helpdesk/public/widget/${key}/kb`).then(unwrap).catch(handleErr),
    kbSearch: (key, q) =>
      api.get(`/helpdesk/public/widget/${key}/kb/search`, { params: { q } }).then(unwrap).catch(handleErr),
    article: (slug) =>
      api.get(`/helpdesk/public/kb/articles/${slug}`).then(unwrap).catch(handleErr),
    vote: (slug, direction) =>
      api.patch(`/helpdesk/public/kb/articles/${slug}/vote`, { direction }).then(unwrap).catch(handleErr),
  },

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

    // Integration with Projects / Tasks
    linkProject: (id, project_id) =>
      api.patch(`/helpdesk/tickets/${id}/link-project`, { project_id }).then(unwrap).catch(handleErr),
    createTask: (id, data) =>
      api.post(`/helpdesk/tickets/${id}/create-task`, data).then(unwrap).catch(handleErr),
    createTasks: (id, tasks) =>
      api.post(`/helpdesk/tickets/${id}/create-tasks`, { tasks }).then(unwrap).catch(handleErr),

    // AI summary (Phase 6) — cached server-side; pass refresh to regenerate
    summarize: (id, refresh = false) =>
      api.post(`/helpdesk/tickets/${id}/summarize`, { refresh }).then(unwrap).catch(handleErr),

    // Tags + merge (Phase 3)
    tags: (id) => api.get(`/helpdesk/tickets/${id}/tags`).then(unwrap).catch(handleErr),
    addTag: (id, data) => api.post(`/helpdesk/tickets/${id}/tags`, data).then(unwrap).catch(handleErr),
    removeTag: (id, tagId) => api.delete(`/helpdesk/tickets/${id}/tags/${tagId}`).then(unwrap).catch(handleErr),
    merge: (id, merge_ticket_id) => api.post(`/helpdesk/tickets/${id}/merge`, { merge_ticket_id }).then(unwrap).catch(handleErr),

    // Collaboration — private notes, reminders, related tickets (Phase 2)
    notes: (id) => api.get(`/helpdesk/tickets/${id}/notes`).then(unwrap).catch(handleErr),
    addNote: (id, content) => api.post(`/helpdesk/tickets/${id}/notes`, { content }).then(unwrap).catch(handleErr),
    reminders: (id) => api.get(`/helpdesk/tickets/${id}/reminders`).then(unwrap).catch(handleErr),
    addReminder: (id, data) => api.post(`/helpdesk/tickets/${id}/reminders`, data).then(unwrap).catch(handleErr),
    reminderDone: (reminderId) => api.patch(`/helpdesk/reminders/${reminderId}/done`).then(unwrap).catch(handleErr),
    related: (id) => api.get(`/helpdesk/tickets/${id}/related`).then(unwrap).catch(handleErr),
    addRelated: (id, related_ticket_id) => api.post(`/helpdesk/tickets/${id}/related`, { related_ticket_id }).then(unwrap).catch(handleErr),
    removeRelated: (id, relatedId) => api.delete(`/helpdesk/tickets/${id}/related/${relatedId}`).then(unwrap).catch(handleErr),

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

    subcategories: (params = {}) =>
      api.get('/helpdesk/kb/subcategories', { params }).then(unwrap).catch(handleErr),
    createSubcategory: (data) =>
      api.post('/helpdesk/kb/subcategories', data).then(unwrap).catch(handleErr),
    updateSubcategory: (id, data) =>
      api.put(`/helpdesk/kb/subcategories/${id}`, data).then(unwrap).catch(handleErr),
    deleteSubcategory: (id) =>
      api.delete(`/helpdesk/kb/subcategories/${id}`).then(unwrap).catch(handleErr),

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
    publish: (id) =>
      api.patch(`/helpdesk/kb/articles/${id}/publish`).then(unwrap).catch(handleErr),
    unpublish: (id) =>
      api.patch(`/helpdesk/kb/articles/${id}/unpublish`).then(unwrap).catch(handleErr),
  },
}

export default helpdeskApi
