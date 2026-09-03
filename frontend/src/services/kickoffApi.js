/**
 * Kickoff meeting API — the shared scheduling engine.
 *
 * Generic by design (mirrors App\Services\Shared on the backend): meetings
 * attach to any allowlisted subject, so this is not a TPV service even though
 * TPV is currently its only consumer with a nav entry.
 */
import api from '@/lib/api'

const upload = (url, formData) =>
  api.post(url, formData, { headers: { 'Content-Type': undefined } }).then(r => r.data)

export const kickoffApi = {
  list:  (params = {}) => api.get('/kickoff/meetings', { params }).then(r => r.data),
  stats: ()            => api.get('/kickoff/meetings/stats').then(r => r.data),
  // Richer Meetings dashboard aggregate (Meeting.docx §14).
  dashboard: ()        => api.get('/kickoff/meetings/dashboard').then(r => r.data),
  // Configurable meeting-type catalogue + agenda priorities + per-type agenda
  // templates (config/meetings.php).
  meetingTypes: ()     => api.get('/kickoff/meeting-types').then(r => r.data),
  // Active projects for the "which project is this for?" picker (Meeting.docx §16).
  projects: ()         => api.get('/kickoff/projects').then(r => r.data),
  // A project's meeting rollup (Meeting.docx §16) — counts + meeting list for the
  // project detail page. { totals, meetings }.
  projectMeetings: (projectId) => api.get(`/kickoff/projects/${projectId}/meetings`).then(r => r.data),
  // Customers + staff for the meeting pickers (Meeting.docx §2 / §5). Both are
  // read through the owning module's contract, never its tables.
  customers: ()        => api.get('/kickoff/customers').then(r => r.data),
  staff: ()            => api.get('/kickoff/staff').then(r => r.data),

  // Cross-meeting registers (Meeting.docx §8 / §9 / §10) — the searchable
  // Decision Register, Issue Register and Open Action Items backlog.
  registers: {
    options:   ()             => api.get('/kickoff/registers/options').then(r => r.data),
    decisions: (params = {})  => api.get('/kickoff/registers/decisions', { params }).then(r => r.data),
    issues:    (params = {})  => api.get('/kickoff/registers/issues', { params }).then(r => r.data),
    actions:   (params = {})  => api.get('/kickoff/registers/actions', { params }).then(r => r.data),
  },

  // §1 Send Invitation — also fired automatically when a dated meeting is
  // scheduled. Returns { sent, skipped, failed, in_app, recipients }.
  invite: (id) => api.post(`/kickoff/meetings/${id}/invite`).then(r => r.data),

  // §13 per-recipient Sent / Viewed / Acknowledged tracker.
  distribution: (id) => api.get(`/kickoff/meetings/${id}/distribution`).then(r => r.data),

  // A vendor's live governance status (Meeting.docx §4) — { vendor, sections }.
  // excludeMeetingId = the meeting being edited, so it is not counted as its
  // own history and a vendor's FIRST meeting does not offer carry-forward.
  vendorStatus: (vendorId, excludeMeetingId) => api.get('/kickoff/vendor-status', {
    params: { vendor_id: vendorId, exclude_meeting_id: excludeMeetingId || undefined },
  }).then(r => r.data),
  // AI layer (Meeting.docx §18) — suggest an agenda before, summarise minutes after.
  aiSuggestAgenda: (data) => api.post('/kickoff/ai/suggest-agenda', data).then(r => r.data),
  aiSummary: (meetingId) => api.post(`/kickoff/meetings/${meetingId}/ai-summary`).then(r => r.data),

  // Admin Types/Templates settings — { builtins, custom, effective }. Writes are
  // admin-gated server-side; layered over config/meetings.php (MeetingTypeCatalog).
  typeSettings: ()          => api.get('/kickoff/meeting-type-settings').then(r => r.data),
  createType:   (data)      => api.post('/kickoff/meeting-type-settings', data).then(r => r.data),
  updateType:   (id, data)  => api.put(`/kickoff/meeting-type-settings/${id}`, data).then(r => r.data),
  deleteType:   (id)        => api.delete(`/kickoff/meeting-type-settings/${id}`).then(r => r.data),
  // Still-open actions/issues from a subject's earlier meetings, to pre-load into
  // a new one. params: { subject_type, subject_id, exclude_meeting_id? }.
  carryForward: (params) => api.get('/kickoff/meetings/carry-forward', { params }).then(r => r.data),
  // A subject's whole meeting history + rollup totals. params: { subject_type, subject_id }.
  history: (params) => api.get('/kickoff/meetings/history', { params }).then(r => r.data),
  get:   (id)          => api.get(`/kickoff/meetings/${id}`).then(r => r.data),
  schedule: (data)     => api.post('/kickoff/meetings', data).then(r => r.data),
  update:   (id, data) => api.put(`/kickoff/meetings/${id}`, data).then(r => r.data),
  transition: (id, data) => api.post(`/kickoff/meetings/${id}/transition`, data).then(r => r.data),
  delete:   (id)       => api.delete(`/kickoff/meetings/${id}`).then(r => r.data),

  // Post-meeting attendance edit — [{ id, attended }]. Audit-logged server-side.
  markAttendance: (id, attendance) => api.patch(`/kickoff/meetings/${id}/attendance`, { attendance }).then(r => r.data),

  // Manual reminder. Returns { email:{sent,skipped,failed}, whatsapp, sms, recipients }.
  // Email is a real send; whatsapp/sms are queued stubs — never implied as delivered.
  remind: (id) => api.post(`/kickoff/meetings/${id}/remind`).then(r => r.data),

  // MoM document. uploadMom keeps the manual-upload path; generateMom builds the
  // PDF from existing data (regenerating replaces the prior file). momBlob fetches
  // the stored file as a blob for inline view / download.
  uploadMom: (id, file) => {
    const fd = new FormData()
    fd.append('mom', file)
    return upload(`/kickoff/meetings/${id}/mom`, fd)
  },
  generateMom: (id) => api.post(`/kickoff/meetings/${id}/mom/generate`).then(r => r.data),
  momBlob: (id) => api.get(`/kickoff/meetings/${id}/mom`, { responseType: 'blob' }).then(r => r.data),

  // MOM approval workflow — submit for approval, approve/return, reopen to revise.
  // Distribution is `publish` below (now gated on approval server-side).
  momSubmit: (id)            => api.post(`/kickoff/meetings/${id}/mom/submit`).then(r => r.data),
  momDecide: (id, data)      => api.post(`/kickoff/meetings/${id}/mom/decide`, data).then(r => r.data),
  momRevise: (id)            => api.post(`/kickoff/meetings/${id}/mom/revise`).then(r => r.data),

  // Distribute the approved minutes to the vendor (no public link any more —
  // the vendor reads them in their portal, notified by e-mail + in-app popup).
  publish: (id) => api.post(`/kickoff/meetings/${id}/publish`).then(r => r.data),

  // Labelled supporting documents (multiple upload). Each file carries a label.
  // momItemId (optional) scopes to one action's evidence; omitted = meeting-level.
  documents:       (id, momItemId) => api.get(`/kickoff/meetings/${id}/documents`, { params: momItemId ? { mom_item_id: momItemId } : {} }).then(r => r.data?.data ?? r.data),
  uploadDocuments: (id, files, labels, momItemId) => {
    const fd = new FormData()
    files.forEach((f) => fd.append('files[]', f))
    labels.forEach((l) => fd.append('labels[]', l ?? ''))
    if (momItemId) fd.append('mom_item_id', momItemId)
    return upload(`/kickoff/meetings/${id}/documents`, fd)
  },
  deleteDocument:  (id, docId) => api.delete(`/kickoff/meetings/${id}/documents/${docId}`).then(r => r.data),
  documentBlob:    (id, docId) => api.get(`/kickoff/meetings/${id}/documents/${docId}/download`, { responseType: 'blob' }).then(r => r.data),

  // Action Engine — progress one MOM action (status/remark/priority/evidence file).
  // Multipart so an evidence document can ride along with the status change.
  progressAction: (meetingId, itemId, data) => {
    const fd = new FormData()
    Object.entries(data).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') fd.append(k, v)
    })
    return upload(`/kickoff/meetings/${meetingId}/mom-items/${itemId}/progress`, fd)
  },
  actionEvidenceBlob: (meetingId, itemId) =>
    api.get(`/kickoff/meetings/${meetingId}/mom-items/${itemId}/evidence`, { responseType: 'blob' }).then(r => r.data),
  // §8 — turn a MOM action into a real Sangoe Task (linked to the vendor).
  pushActionTask: (meetingId, itemId) =>
    api.post(`/kickoff/meetings/${meetingId}/mom-items/${itemId}/push-task`).then(r => r.data),

  // Issue register — progress lifecycle + escalate to an Incident.
  progressIssue: (meetingId, issueId, data) =>
    api.post(`/kickoff/meetings/${meetingId}/issues/${issueId}/progress`, data).then(r => r.data),
  convertIssue: (meetingId, issueId, data) =>
    api.post(`/kickoff/meetings/${meetingId}/issues/${issueId}/convert`, data).then(r => r.data),
  // §10 — convert an issue into a real Sangoe Task (linked to the vendor).
  convertIssueTask: (meetingId, issueId) =>
    api.post(`/kickoff/meetings/${meetingId}/issues/${issueId}/convert-task`).then(r => r.data),
  // §10's remaining escalation targets — each creates a real record in its own
  // module and stamps the issue so it cannot be escalated twice.
  convertIssueNcr: (meetingId, issueId, data = {}) =>
    api.post(`/kickoff/meetings/${meetingId}/issues/${issueId}/convert-ncr`, data).then(r => r.data),
  convertIssueCapa: (meetingId, issueId, data = {}) =>
    api.post(`/kickoff/meetings/${meetingId}/issues/${issueId}/convert-capa`, data).then(r => r.data),
  convertIssueApproval: (meetingId, issueId, data = {}) =>
    api.post(`/kickoff/meetings/${meetingId}/issues/${issueId}/convert-approval`, data).then(r => r.data),

  // Subject pickers — thin wrappers over shared endpoints, mirroring how each
  // module wraps /vendors itself rather than importing another module's service.
  subjects: {
    vendorContacts: (vendorId) => api.get(`/vendors/${vendorId}`).then(r => r.data),
  },
}

export default kickoffApi
