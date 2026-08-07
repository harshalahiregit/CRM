// Invoice / estimate / credit-note templates — /api/sales/document-templates/*
//
// Distinct from proposalTemplateApi: proposal templates carry content and terms
// but never pricing, while these carry the LINE ITEMS — that's the bulk of an
// invoice form and the part worth not retyping.
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const salesDocumentTemplateApi = {
  // docType: 'invoice' | 'estimate' | 'credit_note'
  list: (docType) =>
    api.get('/sales/document-templates', { params: docType ? { doc_type: docType } : {} })
      .then(r => r.data).catch(handleErr),

  get: (id) =>
    api.get(`/sales/document-templates/${id}`).then(r => r.data).catch(handleErr),

  create: (data) =>
    api.post('/sales/document-templates', data).then(r => r.data).catch(handleErr),

  update: (id, data) =>
    api.put(`/sales/document-templates/${id}`, data).then(r => r.data).catch(handleErr),

  delete: (id) =>
    api.delete(`/sales/document-templates/${id}`).then(r => r.data).catch(handleErr),

  duplicate: (id) =>
    api.post(`/sales/document-templates/${id}/duplicate`).then(r => r.data).catch(handleErr),

  // Capture an already-saved document as a template (row menu on the list pages).
  fromDocument: (docType, documentId, data) =>
    api.post('/sales/document-templates/from-document', { doc_type: docType, document_id: documentId, ...data })
      .then(r => r.data).catch(handleErr),
}

export default salesDocumentTemplateApi
