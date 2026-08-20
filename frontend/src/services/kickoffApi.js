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
  // Configurable meeting-type catalogue + agenda priorities + per-type agenda
  // templates (config/meetings.php).
  meetingTypes: ()     => api.get('/kickoff/meeting-types').then(r => r.data),
  // Still-open actions/issues from a subject's earlier meetings, to pre-load into
  // a new one. params: { subject_type, subject_id, exclude_meeting_id? }.
  carryForward: (params) => api.get('/kickoff/meetings/carry-forward', { params }).then(r => r.data),
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

  // Returns { meeting, ack_token } — the token is disclosed only here. The page
  // composes the link from window.location.origin, as the badge QR does.
  publish: (id) => api.post(`/kickoff/meetings/${id}/publish`).then(r => r.data),

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

  // Issue register — progress lifecycle + escalate to an Incident.
  progressIssue: (meetingId, issueId, data) =>
    api.post(`/kickoff/meetings/${meetingId}/issues/${issueId}/progress`, data).then(r => r.data),
  convertIssue: (meetingId, issueId, data) =>
    api.post(`/kickoff/meetings/${meetingId}/issues/${issueId}/convert`, data).then(r => r.data),

  // Subject pickers — thin wrappers over shared endpoints, mirroring how each
  // module wraps /vendors itself rather than importing another module's service.
  subjects: {
    vendorContacts: (vendorId) => api.get(`/vendors/${vendorId}`).then(r => r.data),
  },
}

export default kickoffApi
