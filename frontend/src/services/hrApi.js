/**
 * HR Module API Service
 * All HTTP calls to /api/hr/* endpoints
 */

import axios from 'axios'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'

// Create axios instance with auth token
const api = axios.create({ baseURL: BASE })

api.interceptors.request.use(cfg => {
  const token = localStorage.getItem('crm_token') // ✅ FIXED: was 'auth_token'
  if (token) cfg.headers.Authorization = `Bearer ${token}`
  return cfg
})

api.interceptors.response.use(
  res => res,
  err => {
    if (err.response?.status === 401) {
      localStorage.removeItem('crm_token') // ✅ FIXED: was 'auth_token'
      localStorage.removeItem('crm_user')
      localStorage.removeItem('crm_tenant')
      window.location.href = '/auth/login'
    }
    return Promise.reject(err)
  }
)

// Tokenless instance for public (no-auth) token portals — must NOT attach the
// logged-in bearer token or hard-redirect to /auth/login on 401, so the portals
// can show their own friendly errors and behave as a real anonymous client.
const publicApi = axios.create({ baseURL: BASE })

// ── Dashboard ─────────────────────────────────────────────────────────
export const hrApi = {
  dashboard: {
    get: () => api.get('/hr/dashboard').then(r => r.data),
  },

  // ── Manpower Requests (L1/L2 approval → HR queue → JD → job posting) ──
  manpower: {
    list:            (params = {}) => api.get('/hr/manpower-requests', { params }).then(r => r.data),
    queue:           (params = {}) => api.get('/hr/manpower-requests/queue', { params }).then(r => r.data),
    stats:           ()            => api.get('/hr/manpower-requests/stats').then(r => r.data),
    pendingApprovals:()            => api.get('/hr/manpower-requests/pending-approvals').then(r => r.data),
    // Distinct project names for the searchable Project dropdown (dynamic, no new table).
    projects:        ()            => api.get('/hr/manpower-requests/projects').then(r => r.data),
    // Enterprise form lookups: business units, departments (+ by-BU map), hiring managers.
    formOptions:     ()            => api.get('/hr/manpower-requests/form-options').then(r => r.data),
    get:             (id)          => api.get(`/hr/manpower-requests/${id}`).then(r => r.data),
    create:          (data)        => api.post('/hr/manpower-requests', data).then(r => r.data),
    update:          (id, data)    => api.put(`/hr/manpower-requests/${id}`, data).then(r => r.data),
    delete:          (id)          => api.delete(`/hr/manpower-requests/${id}`).then(r => r.data),
    // Approval workflow
    submit:          (id)              => api.post(`/hr/manpower-requests/${id}/submit`).then(r => r.data),
    approveL1:       (id, remarks = '') => api.post(`/hr/manpower-requests/${id}/approve-l1`, { remarks }).then(r => r.data),
    rejectL1:        (id, remarks)      => api.post(`/hr/manpower-requests/${id}/reject-l1`, { remarks }).then(r => r.data),
    approveL2:       (id, remarks = '') => api.post(`/hr/manpower-requests/${id}/approve-l2`, { remarks }).then(r => r.data),
    rejectL2:        (id, remarks)      => api.post(`/hr/manpower-requests/${id}/reject-l2`, { remarks }).then(r => r.data),
    sendBack:        (id, remarks)      => api.post(`/hr/manpower-requests/${id}/send-back`, { remarks }).then(r => r.data),
    // HR queue actions
    generateJd:      (id, data = {})    => api.post(`/hr/manpower-requests/${id}/generate-jd`, data).then(r => r.data),
    templateJd:      (id, template)     => api.post(`/hr/manpower-requests/${id}/template-jd`, { template }).then(r => r.data),
    analyzeJd:       (id, data = {})    => api.post(`/hr/manpower-requests/${id}/analyze-jd`, data).then(r => r.data),
    jdImprovementDecision: (id, decision, ats_before, ats_after) => api.post(`/hr/manpower-requests/${id}/jd-improvement-decision`, { decision, ats_before, ats_after }).then(r => r.data),
    jdTemplates:     ()                 => api.get('/hr/manpower-requests/jd-templates').then(r => r.data),
    convertToJd:     (id, data = {})    => api.post(`/hr/manpower-requests/${id}/convert-to-jd`, data).then(r => r.data),
    publish:         (id)               => api.post(`/hr/manpower-requests/${id}/publish`).then(r => r.data),
    close:           (id, remarks = '') => api.post(`/hr/manpower-requests/${id}/close`, { remarks }).then(r => r.data),
    assignManager:   (id, manager_id)   => api.patch(`/hr/manpower-requests/${id}/assign-manager`, { manager_id }).then(r => r.data),
  },

  // ── Job Postings — Recruitment Workspace ────────────────────────────
  jobs: {
    list:         (params = {}) => api.get('/hr/jobs', { params }).then(r => r.data),
    stats:        ()            => api.get('/hr/jobs/stats').then(r => r.data),
    bulk:         (action, ids) => api.post('/hr/jobs/bulk', { action, ids }).then(r => r.data),
    get:          (id)          => api.get(`/hr/jobs/${id}`).then(r => r.data),
    create:       (data)        => api.post('/hr/jobs', data).then(r => r.data),
    analyzeJd:    (data)        => api.post('/hr/jobs/analyze-jd', data).then(r => r.data),
    update:       (id, data)    => api.put(`/hr/jobs/${id}`, data).then(r => r.data),
    updateStatus: (id, status)  => api.patch(`/hr/jobs/${id}/status`, { status }).then(r => r.data),
    updateExternalId: (id, platform, external_id) => api.patch(`/hr/jobs/${id}/external-id`, { platform, external_id }).then(r => r.data),
    delete:       (id)          => api.delete(`/hr/jobs/${id}`).then(r => r.data),
    // Lifecycle actions
    publish:      (id)          => api.post(`/hr/jobs/${id}/publish`).then(r => r.data),
    unpublish:    (id)          => api.post(`/hr/jobs/${id}/unpublish`).then(r => r.data),
    pause:        (id)          => api.post(`/hr/jobs/${id}/pause`).then(r => r.data),
    close:        (id, remarks) => api.post(`/hr/jobs/${id}/close`, { remarks }).then(r => r.data),
    cancel:       (id, remarks) => api.post(`/hr/jobs/${id}/cancel`, { remarks }).then(r => r.data),
    duplicate:    (id)          => api.post(`/hr/jobs/${id}/duplicate`).then(r => r.data),
    // Distribution channels (Career Portal + future LinkedIn/Naukri/Indeed/TrulyTalents)
    channels:        ()             => api.get('/hr/jobs/channels').then(r => r.data),
    publishTo:       (id, channel)  => api.post(`/hr/jobs/${id}/publish-to`, { channel }).then(r => r.data),
    publishChannels: (id, channels) => api.post(`/hr/jobs/${id}/publish-channels`, { channels }).then(r => r.data),
    unpublishFrom:   (id, channel)  => api.delete(`/hr/jobs/${id}/publish-to/${channel}`).then(r => r.data),
  },

  // ── Candidates ──────────────────────────────────────────────────────
  candidates: {
    list:          (params = {}) => api.get('/hr/candidates', { params }).then(r => r.data),
    get:           (id)          => api.get(`/hr/candidates/${id}`).then(r => r.data),
    journey:       (id)          => api.get(`/hr/candidates/${id}/journey`).then(r => r.data),
    communications:  (id)        => api.get(`/hr/candidates/${id}/communications`).then(r => r.data),
    commPreview:     (id, channel, event) => api.get(`/hr/candidates/${id}/communication-preview`, { params: { channel, event } }).then(r => r.data),
    communicate:     (id, data)  => api.post(`/hr/candidates/${id}/communicate`, data).then(r => r.data),
    scheduleReminder:(id, type, days) => api.post(`/hr/candidates/${id}/reminder`, { type, days }).then(r => r.data),
    create:        (data)        => api.post('/hr/candidates', data).then(r => r.data),
    update:        (id, data)    => api.put(`/hr/candidates/${id}`, data).then(r => r.data),
    updateStage:   (id, stage)   => api.patch(`/hr/candidates/${id}/stage`, { stage }).then(r => r.data),
    updateDecision:(id, decision)=> api.patch(`/hr/candidates/${id}/decision`, { final_decision: decision }).then(r => r.data),
    delete:        (id)          => api.delete(`/hr/candidates/${id}`).then(r => r.data),
    linkedinParse: (url)         => api.post('/hr/candidates/linkedin-parse', { url }).then(r => r.data),
    // Resume
    uploadResume:  (id, file)    => {
      const fd = new FormData()
      fd.append('resume', file)
      return api.post(`/hr/candidates/${id}/resume`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' }
      }).then(r => r.data)
    },
    resumeUrl:     (id)          => `${BASE}/hr/candidates/${id}/resume`,
    deleteResume:  (id)          => api.delete(`/hr/candidates/${id}/resume`).then(r => r.data),
    // Recruiter assignment
    recruiters:    ()            => api.get('/hr/candidates/recruiters').then(r => r.data),
    assign:        (id, recruiterId) => api.patch(`/hr/candidates/${id}/assign`, { recruiter_id: recruiterId }).then(r => r.data),
    // Collaborative notes thread
    notes: {
      list:   (id)          => api.get(`/hr/candidates/${id}/notes`).then(r => r.data),
      add:    (id, body, visibleToCandidate = false) => api.post(`/hr/candidates/${id}/notes`, { body, visible_to_candidate: visibleToCandidate }).then(r => r.data),
      delete: (id, noteId)  => api.delete(`/hr/candidates/${id}/notes/${noteId}`).then(r => r.data),
    },
    // Typed documents (beyond the primary resume)
    documents: {
      list:   (id)          => api.get(`/hr/candidates/${id}/documents`).then(r => r.data),
      upload: (id, file, type = 'other') => {
        const fd = new FormData()
        fd.append('document', file)
        fd.append('type', type)
        return api.post(`/hr/candidates/${id}/documents`, fd, {
          headers: { 'Content-Type': 'multipart/form-data' }
        }).then(r => r.data)
      },
      url:    (id, docId)   => `${BASE}/hr/candidates/${id}/documents/${docId}`,
      // Authenticated blob fetch (bearer header) — safe for private files.
      blob:   (id, docId)   => api.get(`/hr/candidates/${id}/documents/${docId}`, { responseType: 'blob' }).then(r => r.data),
      delete: (id, docId)   => api.delete(`/hr/candidates/${id}/documents/${docId}`).then(r => r.data),
    },
  },


  // ── Interviews ──────────────────────────────────────────────────────
  interviews: {
    list:             (params = {}) => api.get('/hr/interviews', { params }).then(r => r.data),
    stats:            ()            => api.get('/hr/interviews/stats').then(r => r.data),
    schedule:         (data)        => api.post('/hr/interviews', data).then(r => r.data),
    get:              (id)          => api.get(`/hr/interviews/${id}`).then(r => r.data),
    update:           (id, data)    => api.put(`/hr/interviews/${id}`, data).then(r => r.data),
    recordFeedback:   (id, data)    => api.patch(`/hr/interviews/${id}/feedback`, data).then(r => r.data),
    cancel:           (id, reason)  => api.patch(`/hr/interviews/${id}/cancel`, { reason }).then(r => r.data),
    generateMeetLink: (id)          => api.post(`/hr/interviews/${id}/meet-link`).then(r => r.data),
    sendNotification: (id, type, extra = {}) => api.post(`/hr/interviews/${id}/notify`, { type, ...extra }).then(r => r.data),
    emailPreview:     (id, type = 'candidate') => api.get(`/hr/interviews/${id}/email-preview`, { params: { type } }).then(r => r.data),
    panelUsers:       (role, company) => api.get('/hr/interview-panel/users', { params: { role, company } }).then(r => r.data),
    panelOrgs:        (role)        => api.get('/hr/interview-panel/organizations', { params: { role } }).then(r => r.data),
    delete:           (id)          => api.delete(`/hr/interviews/${id}`).then(r => r.data),
  },

  // ── Offers ──────────────────────────────────────────────────────────
  offers: {
    list:          (params = {}) => api.get('/hr/offers', { params }).then(r => r.data),
    joiningBuckets:()           => api.get('/hr/offers/joining-buckets').then(r => r.data),
    get:           (id)          => api.get(`/hr/offers/${id}`).then(r => r.data),
    create:        (data)        => api.post('/hr/offers', data).then(r => r.data),
    send:          (id)          => api.patch(`/hr/offers/${id}/send`).then(r => r.data),
    updateStatus:  (id, payload) => api.patch(`/hr/offers/${id}/status`, typeof payload === 'string' ? { status: payload } : payload).then(r => r.data),
    confirmJoining:(id)          => api.patch(`/hr/offers/${id}/confirm-joining`).then(r => r.data),
    regenerate:    (id, validity) => api.patch(`/hr/offers/${id}/regenerate`, { validity_date: validity }).then(r => r.data),
    delete:        (id)          => api.delete(`/hr/offers/${id}`).then(r => r.data),
    // Public candidate offer-portal link built from the stored token.
    portalUrl:     (token)       => `${window.location.origin}/offer/${token}`,
  },

  // ── Onboarding ──────────────────────────────────────────────────────
  onboarding: {
    list:           (params={})   => api.get('/hr/onboarding', { params }).then(r => r.data),
    get:            (id)          => api.get(`/hr/onboarding/${id}`).then(r => r.data),
    start:          (data)        => api.post('/hr/onboarding', data).then(r => r.data),
    toggleStep:     (id, step)    => api.patch(`/hr/onboarding/${id}/step`, { step }).then(r => r.data),
    updateChecklist:(id, checklist)=> api.patch(`/hr/onboarding/${id}/step`, { checklist }).then(r => r.data),
    verify:         (id, data)    => api.patch(`/hr/onboarding/${id}/verify`, data).then(r => r.data),
    documentUrl:    (id, docId)   => `${BASE}/hr/onboarding/${id}/documents/${docId}`,
    documentBlob:   (id, docId)   => api.get(`/hr/onboarding/${id}/documents/${docId}`, { responseType: 'blob' }).then(r => r.data),
    verifyDocument: (id, docId, data) => api.patch(`/hr/onboarding/${id}/documents/${docId}/verify`, data).then(r => r.data),
    delete:         (id)          => api.delete(`/hr/onboarding/${id}`).then(r => r.data),
  },

  // ── Employees ───────────────────────────────────────────────────────
  employees: {
    // list() ALWAYS resolves to an array so existing consumers (Attendance, pickers)
    // keep working now that the endpoint is paginated. Use listPaged() when the
    // paginator meta (current_page/last_page/total/per_page) is needed.
    list:   (params = {}) => api.get('/hr/employees', { params }).then(r => Array.isArray(r.data) ? r.data : (r.data?.data ?? [])),
    listPaged: (params = {}) => api.get('/hr/employees', { params }).then(r => r.data),
    stats:  ()            => api.get('/hr/employees/stats').then(r => r.data),
    get:    (id)          => api.get(`/hr/employees/${id}`).then(r => r.data),
    profile:(id)          => api.get(`/hr/employees/${id}/profile`).then(r => r.data),
    // Exit Interview (SPK-1) — prefill comes from the employee record itself.
    exitInterview:     (id)       => api.get(`/hr/employees/${id}/exit-interview`).then(r => r.data),
    saveExitInterview: (id, data) => api.post(`/hr/employees/${id}/exit-interview`, data).then(r => r.data),
    create: (data)        => api.post('/hr/employees', data).then(r => r.data),
    update: (id, data)    => api.put(`/hr/employees/${id}`, data).then(r => r.data),
    delete: (id)          => api.delete(`/hr/employees/${id}`).then(r => r.data),
    attendance: (id, params = {}) => api.get(`/hr/employees/${id}/attendance`, { params }).then(r => r.data),
  },

  // ── Organization Setup — Department / Designation / Grade / Role masters ──
  organization: {
    overview:  ()          => api.get('/hr/organization/overview').then(r => r.data),
    options:   ()          => api.get('/hr/organization/options').then(r => r.data),
    hierarchy: ()          => api.get('/hr/organization/hierarchy').then(r => r.data),
    departments: {
      list:   ()           => api.get('/hr/departments').then(r => r.data),
      create: (data)       => api.post('/hr/departments', data).then(r => r.data),
      update: (id, data)   => api.put(`/hr/departments/${id}`, data).then(r => r.data),
      delete: (id)         => api.delete(`/hr/departments/${id}`).then(r => r.data),
    },
    designations: {
      list:   ()           => api.get('/hr/designations').then(r => r.data),
      create: (data)       => api.post('/hr/designations', data).then(r => r.data),
      update: (id, data)   => api.put(`/hr/designations/${id}`, data).then(r => r.data),
      delete: (id)         => api.delete(`/hr/designations/${id}`).then(r => r.data),
    },
    grades: {
      list:   ()           => api.get('/hr/grades').then(r => r.data),
      create: (data)       => api.post('/hr/grades', data).then(r => r.data),
      update: (id, data)   => api.put(`/hr/grades/${id}`, data).then(r => r.data),
      delete: (id)         => api.delete(`/hr/grades/${id}`).then(r => r.data),
    },
    roles: {
      list:   ()           => api.get('/hr/org-roles').then(r => r.data),
      create: (data)       => api.post('/hr/org-roles', data).then(r => r.data),
      update: (id, data)   => api.put(`/hr/org-roles/${id}`, data).then(r => r.data),
      delete: (id)         => api.delete(`/hr/org-roles/${id}`).then(r => r.data),
    },
  },

  // ── Payroll → Salary Components master (Phase 1) ────────────────────────
  payroll: {
    salaryComponents: {
      list:       (params = {}) => api.get('/hr/payroll/salary-components', { params }).then(r => r.data),
      create:     (data)        => api.post('/hr/payroll/salary-components', data).then(r => r.data),
      update:     (id, data)    => api.put(`/hr/payroll/salary-components/${id}`, data).then(r => r.data),
      setStatus:  (id, active)  => api.patch(`/hr/payroll/salary-components/${id}/status`, { is_active: active }).then(r => r.data),
    },
    salaryStructures: {
      list:       (params = {}) => api.get('/hr/payroll/salary-structures', { params }).then(r => r.data),
      get:        (id)          => api.get(`/hr/payroll/salary-structures/${id}`).then(r => r.data),
      create:     (data)        => api.post('/hr/payroll/salary-structures', data).then(r => r.data),
      update:     (id, data)    => api.put(`/hr/payroll/salary-structures/${id}`, data).then(r => r.data),
      setStatus:  (id, active)  => api.patch(`/hr/payroll/salary-structures/${id}/status`, { is_active: active }).then(r => r.data),
    },
  },

  attendance: {
    list:       (params = {}) => api.get('/hr/attendance', { params }).then(r => r.data),
    stats:      (params = {}) => api.get('/hr/attendance/stats', { params }).then(r => r.data),
    manual:     (data)        => api.post('/hr/attendance', data).then(r => r.data),
    correct:    (id, data)    => api.patch(`/hr/attendance/${id}`, data).then(r => r.data),
    checkIn:    (data)        => api.post('/hr/attendance/check-in', data).then(r => r.data),
    checkOut:   (data)        => api.post('/hr/attendance/check-out', data).then(r => r.data),
    breakStart: (data)        => api.post('/hr/attendance/break-start', data).then(r => r.data),
    breakEnd:   (data)        => api.post('/hr/attendance/break-end', data).then(r => r.data),
    exportUrl:  (params = {}) => `/hr/attendance/export?${new URLSearchParams(params).toString()}`,
    exportBlob: (params = {}) => api.get('/hr/attendance/export', { params, responseType: 'blob' }).then(r => r.data),
  },

  // ── Recruitment Services (external-company hiring intake) ────────────────
  recruitmentServices: {
    dashboard:      ()            => api.get('/recruitment-services/dashboard').then(r => r.data),
    companies:      (params = {}) => api.get('/recruitment-services/companies', { params }).then(r => r.data),
    createCompany:  (data)        => api.post('/recruitment-services/companies', data).then(r => r.data),
    updateCompany:  (id, data)    => api.put(`/recruitment-services/companies/${id}`, data).then(r => r.data),
    deleteCompany:  (id)          => api.delete(`/recruitment-services/companies/${id}`).then(r => r.data),
    requests:       (params = {}) => api.get('/recruitment-services/requests', { params }).then(r => r.data),
    getRequest:     (id)          => api.get(`/recruitment-services/requests/${id}`).then(r => r.data),
    createRequest:  (data)        => api.post('/recruitment-services/requests', data).then(r => r.data),
    updateRequest:  (id, data)    => api.put(`/recruitment-services/requests/${id}`, data).then(r => r.data),
    // Company Portal — HR approval of self-registered companies
    pendingCompanies: ()            => api.get('/recruitment-services/company-accounts/pending').then(r => r.data),
    approveCompany:   (id)          => api.post(`/recruitment-services/company-accounts/${id}/approve`).then(r => r.data),
    rejectCompany:    (id, reason = '') => api.post(`/recruitment-services/company-accounts/${id}/reject`, { reason }).then(r => r.data),
    review:         (id, decision, notes = '') => api.post(`/recruitment-services/requests/${id}/review`, { decision, notes }).then(r => r.data),
    assign:         (id, recruiter_id) => api.post(`/recruitment-services/requests/${id}/assign`, { recruiter_id }).then(r => r.data),
    convert:        (id)          => api.post(`/recruitment-services/requests/${id}/convert`).then(r => r.data),
    // Phase 2 — client collaboration
    submittable:    (id)          => api.get(`/recruitment-services/requests/${id}/submittable-candidates`).then(r => r.data),
    submissions:    (id)          => api.get(`/recruitment-services/requests/${id}/submissions`).then(r => r.data),
    submitCandidates: (id, candidate_ids, note = '') => api.post(`/recruitment-services/requests/${id}/submit-candidates`, { candidate_ids, note }).then(r => r.data),
    notifyClient:   (id, event)   => api.post(`/recruitment-services/requests/${id}/notify-client`, { event }).then(r => r.data),
    // Phase 3 — recruiter operations
    workspace:          ()             => api.get('/recruitment-services/recruiter/workspace').then(r => r.data),
    recruiterDashboard: ()             => api.get('/recruitment-services/recruiter/dashboard').then(r => r.data),
    sla:                (recruiterId)  => api.get('/recruitment-services/recruiter/sla', { params: recruiterId ? { recruiter_id: recruiterId } : {} }).then(r => r.data),
    performance:        (recruiterId)  => api.get('/recruitment-services/recruiter/performance', { params: recruiterId ? { recruiter_id: recruiterId } : {} }).then(r => r.data),
    notes:          (id)           => api.get(`/recruitment-services/requests/${id}/notes`).then(r => r.data),
    addNote:        (id, body)     => api.post(`/recruitment-services/requests/${id}/notes`, { body }).then(r => r.data),
    deleteNote:     (id, noteId)   => api.delete(`/recruitment-services/requests/${id}/notes/${noteId}`).then(r => r.data),
    rating:         (id)           => api.get(`/recruitment-services/requests/${id}/rating`).then(r => r.data),
    resumeShares:   (id)           => api.get(`/recruitment-services/requests/${id}/resume-shares`).then(r => r.data),
    shareResume:    (id, candidate_id) => api.post(`/recruitment-services/requests/${id}/share-resume`, { candidate_id }).then(r => r.data),
  },

  // Public hiring-request portal (no auth — company token)
  hiringPortal: {
    company: (token)       => publicApi.get(`/hiring-request/${token}`).then(r => r.data),
    submit:  (token, data) => publicApi.post(`/hiring-request/${token}`, data).then(r => r.data),
  },

  // Public client-tracking portal (no auth — per-request tracking token)
  clientTracking: {
    show:      (token)               => publicApi.get(`/client-tracking/${token}`).then(r => r.data),
    feedback:  (token, submission_id, decision, comment = '') => publicApi.post(`/client-tracking/${token}/feedback`, { submission_id, decision, comment }).then(r => r.data),
    rating:    (token, data)         => publicApi.post(`/client-tracking/${token}/rating`, data).then(r => r.data),
    resumeUrl: (shareToken)          => `${BASE}/client-tracking/resume/${shareToken}`,
  },

  // ── Employee Onboarding (HR-driven; authenticated) ──────────────────────
  employeeOnboarding: {
    dashboard:         ()             => api.get('/employee-onboarding/dashboard').then(r => r.data),
    list:              (params = {})  => api.get('/employee-onboarding', { params }).then(r => r.data),
    eligibleEmployees: (params = {})  => api.get('/employee-onboarding/eligible-employees', { params }).then(r => r.data),
    create:            (data)         => api.post('/employee-onboarding', data).then(r => r.data),
    get:               (id)           => api.get(`/employee-onboarding/${id}`).then(r => r.data),
    saveSection:       (id, section, data) => api.patch(`/employee-onboarding/${id}/section/${section}`, data).then(r => r.data),
    // HR section verification — the only writer of Verified / Rejected / Correction Requested.
    verifySection:     (id, section, status, remarks) => api.patch(`/employee-onboarding/${id}/section/${section}/verify`, { status, remarks }).then(r => r.data),
    setStage:          (id, stage)    => api.patch(`/employee-onboarding/${id}/stage`, { stage }).then(r => r.data),
    updateTask:        (id, taskId, data) => api.patch(`/employee-onboarding/${id}/tasks/${taskId}`, data).then(r => r.data),
    addEducation:      (id, data)     => api.post(`/employee-onboarding/${id}/education`, data).then(r => r.data),
    updateEducation:   (id, rid, data) => api.put(`/employee-onboarding/${id}/education/${rid}`, data).then(r => r.data),
    deleteEducation:   (id, rid)      => api.delete(`/employee-onboarding/${id}/education/${rid}`).then(r => r.data),
    addExperience:     (id, data)     => api.post(`/employee-onboarding/${id}/experience`, data).then(r => r.data),
    updateExperience:  (id, rid, data) => api.put(`/employee-onboarding/${id}/experience/${rid}`, data).then(r => r.data),
    deleteExperience:  (id, rid)      => api.delete(`/employee-onboarding/${id}/experience/${rid}`).then(r => r.data),
    addFamily:         (id, data)     => api.post(`/employee-onboarding/${id}/family`, data).then(r => r.data),
    updateFamily:      (id, rid, data) => api.put(`/employee-onboarding/${id}/family/${rid}`, data).then(r => r.data),
    deleteFamily:      (id, rid)      => api.delete(`/employee-onboarding/${id}/family/${rid}`).then(r => r.data),
    addAsset:          (id, data)     => api.post(`/employee-onboarding/${id}/assets`, data).then(r => r.data),
    updateAsset:       (id, rid, data) => api.put(`/employee-onboarding/${id}/assets/${rid}`, data).then(r => r.data),
    deleteAsset:       (id, rid)      => api.delete(`/employee-onboarding/${id}/assets/${rid}`).then(r => r.data),
  },
}

export default hrApi
