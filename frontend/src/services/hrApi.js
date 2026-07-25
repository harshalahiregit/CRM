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
      // Enterprise Salary Engine — live preview (no persist) + duplicate.
      preview:    (lines)       => api.post('/hr/payroll/salary-structures/preview', { lines }).then(r => r.data),
      duplicate:  (id)          => api.post(`/hr/payroll/salary-structures/${id}/duplicate`).then(r => r.data),
    },
    // Employee Salary Assignment (Phase 3) — nested under an employee.
    employeeSalary: {
      get:       (employeeId)          => api.get(`/hr/payroll/employees/${employeeId}/salary`).then(r => r.data),
      revisions: (employeeId)          => api.get(`/hr/payroll/employees/${employeeId}/salary/revisions`).then(r => r.data),
      assign:    (employeeId, data)    => api.post(`/hr/payroll/employees/${employeeId}/salary`, data).then(r => r.data),
      update:    (employeeId, id, data)=> api.put(`/hr/payroll/employees/${employeeId}/salary/${id}`, data).then(r => r.data),
      setStatus: (employeeId, id, active) => api.patch(`/hr/payroll/employees/${employeeId}/salary/${id}/status`, { is_active: active }).then(r => r.data),
    },
    // Payroll Processing (Phase 4) — monthly runs.
    runs: {
      list:      (params = {}) => api.get('/hr/payroll/runs', { params }).then(r => r.data),
      get:       (id)          => api.get(`/hr/payroll/runs/${id}`).then(r => r.data),
      create:    (month, year) => api.post('/hr/payroll/runs', { month, year }).then(r => r.data),
      process:   (id)          => api.post(`/hr/payroll/runs/${id}/process`).then(r => r.data),
      records:   (id)          => api.get(`/hr/payroll/runs/${id}/records`).then(r => r.data),
      setStatus: (id, status)  => api.patch(`/hr/payroll/runs/${id}/status`, { status }).then(r => r.data),
      generatePayslips: (id)   => api.post(`/hr/payroll/runs/${id}/generate-payslips`).then(r => r.data),
    },
    // Payroll Reports & Analytics (Phase 6) — read-only over frozen data.
    reports: {
      filters:     ()             => api.get('/hr/payroll/reports/filters').then(r => r.data),
      summary:     (params = {})  => api.get('/hr/payroll/reports/summary', { params }).then(r => r.data),
      employees:   (params = {})  => api.get('/hr/payroll/reports/employees', { params }).then(r => r.data),
      departments: (params = {})  => api.get('/hr/payroll/reports/departments', { params }).then(r => r.data),
      components:  (params = {})  => api.get('/hr/payroll/reports/components', { params }).then(r => r.data),
      trends:      (params = {})  => api.get('/hr/payroll/reports/trends', { params }).then(r => r.data),
      // CSV (Excel) or PDF export → triggers a browser download.
      export: (report, format, params = {}) => api.get('/hr/payroll/reports/export', { params: { ...params, report, format }, responseType: 'blob' }).then(r => {
        const url = URL.createObjectURL(r.data)
        const a = document.createElement('a'); a.href = url; a.download = `${report}-report.${format === 'pdf' ? 'pdf' : 'csv'}`; a.click()
        setTimeout(() => URL.revokeObjectURL(url), 1500)
      }),
    },
    // Payslips (Phase 5) — generated from a completed run; PDF via dompdf.
    payslips: {
      list:     (params = {})   => api.get('/hr/payroll/payslips', { params }).then(r => r.data),
      get:      (id)            => api.get(`/hr/payroll/payslips/${id}`).then(r => r.data),
      forEmployee: (employeeId) => api.get(`/hr/employees/${employeeId}/payslips`).then(r => r.data),
      // Authenticated blob fetch → triggers a browser download of the PDF.
      download: (id, filename)  => api.get(`/hr/payroll/payslips/${id}/download`, { responseType: 'blob' }).then(r => {
        const url = URL.createObjectURL(r.data)
        const a = document.createElement('a'); a.href = url; a.download = filename || `payslip-${id}.pdf`; a.click()
        setTimeout(() => URL.revokeObjectURL(url), 1500)
      }),
    },
  },

  // ── Performance Management System (PMS) ─────────────────────────────────
  performance: {
    dashboard: ()           => api.get('/hr/performance/dashboard').then(r => r.data),
    timeline:  (employeeId) => api.get(`/hr/performance/timeline/${employeeId}`).then(r => r.data),
    kpis: {
      list:      (params = {}) => api.get('/hr/performance/kpis', { params }).then(r => r.data),
      create:    (data)        => api.post('/hr/performance/kpis', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/performance/kpis/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/performance/kpis/${id}/status`, { is_active: active }).then(r => r.data),
    },
    goals: {
      list:   (params = {}) => api.get('/hr/performance/goals', { params }).then(r => r.data),
      create: (data)        => api.post('/hr/performance/goals', data).then(r => r.data),
      update: (id, data)    => api.put(`/hr/performance/goals/${id}`, data).then(r => r.data),
    },
    assignments: {
      list:   (params = {})       => api.get('/hr/performance/assignments', { params }).then(r => r.data),
      assign: (goalId, employeeIds) => api.post('/hr/performance/assignments', { goal_id: goalId, employee_ids: employeeIds }).then(r => r.data),
      update: (id, data)          => api.patch(`/hr/performance/assignments/${id}`, data).then(r => r.data),
    },
    reviews: {
      list:      (params = {}) => api.get('/hr/performance/reviews', { params }).then(r => r.data),
      get:       (id)          => api.get(`/hr/performance/reviews/${id}`).then(r => r.data),
      create:    (data)        => api.post('/hr/performance/reviews', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/performance/reviews/${id}`, data).then(r => r.data),
      setStatus: (id, status)  => api.patch(`/hr/performance/reviews/${id}/status`, { status }).then(r => r.data),
    },
    promotions: {
      list:      (params = {}) => api.get('/hr/performance/promotions', { params }).then(r => r.data),
      generate:  (employeeId)  => api.post('/hr/performance/promotions/generate', { employee_id: employeeId }).then(r => r.data),
      setStatus: (id, status, recommended_designation) => api.patch(`/hr/performance/promotions/${id}/status`, { status, recommended_designation }).then(r => r.data),
    },
    increments: {
      list:      (params = {}) => api.get('/hr/performance/increments', { params }).then(r => r.data),
      generate:  (employeeId)  => api.post('/hr/performance/increments/generate', { employee_id: employeeId }).then(r => r.data),
      setStatus: (id, status)  => api.patch(`/hr/performance/increments/${id}/status`, { status }).then(r => r.data),
    },
  },

  // ── Leave Management — Phase 1 (Types + Policies) ───────────────────────
  leave: {
    types: {
      list:      (params = {}) => api.get('/hr/leave/types', { params }).then(r => r.data),
      create:    (data)        => api.post('/hr/leave/types', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/leave/types/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/leave/types/${id}/status`, { is_active: active }).then(r => r.data),
    },
    policies: {
      list:      (params = {}) => api.get('/hr/leave/policies', { params }).then(r => r.data),
      get:       (id)          => api.get(`/hr/leave/policies/${id}`).then(r => r.data),
      create:    (data)        => api.post('/hr/leave/policies', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/leave/policies/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/leave/policies/${id}/status`, { is_active: active }).then(r => r.data),
    },
    // Employee Leave Balance & Allocation (Phase 2).
    balances: {
      list:       (params = {})  => api.get('/hr/leave/balances', { params }).then(r => r.data),
      forEmployee:(employeeId)   => api.get(`/hr/leave/balances/${employeeId}`).then(r => r.data),
      assign:     (data)         => api.post('/hr/leave/balances/assign', data).then(r => r.data),
      allocate:   (data)         => api.post('/hr/leave/balances/allocate', data).then(r => r.data),
      adjust:     (data)         => api.post('/hr/leave/balances/adjust', data).then(r => r.data),
      history:    (balanceId)    => api.get(`/hr/leave/balances/history/${balanceId}`).then(r => r.data),
    },
    // Leave Applications (Phase 3).
    applications: {
      list:   (params = {}) => api.get('/hr/leave/applications', { params }).then(r => r.data),
      get:    (id)          => api.get(`/hr/leave/applications/${id}`).then(r => r.data),
      apply:  (formData)    => api.post('/hr/leave/applications', formData, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data),
      submit: (id)          => api.patch(`/hr/leave/applications/${id}/submit`).then(r => r.data),
      cancel: (id)          => api.patch(`/hr/leave/applications/${id}/cancel`).then(r => r.data),
      attachmentUrl: (id)   => `${BASE}/hr/leave/applications/${id}/attachment`,
    },
    // Leave Approval workflow (Phase 4).
    approvals: {
      list:    (params = {})  => api.get('/hr/leave/approvals', { params }).then(r => r.data),
      get:     (id)           => api.get(`/hr/leave/approvals/${id}`).then(r => r.data),
      approve: (id, remarks)  => api.patch(`/hr/leave/approvals/${id}/approve`, { remarks }).then(r => r.data),
      reject:  (id, remarks)  => api.patch(`/hr/leave/approvals/${id}/reject`, { remarks }).then(r => r.data),
      history: (employeeId)   => api.get(`/hr/leave/approvals/history/${employeeId}`).then(r => r.data),
    },
    // Holiday Calendar (Phase 5).
    holidays: {
      list:      (params = {}) => api.get('/hr/leave/holidays', { params }).then(r => r.data),
      calendar:  (params = {}) => api.get('/hr/leave/holidays/calendar', { params }).then(r => r.data),
      get:       (id)          => api.get(`/hr/leave/holidays/${id}`).then(r => r.data),
      create:    (data)        => api.post('/hr/leave/holidays', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/leave/holidays/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/leave/holidays/${id}/status`, { is_active: active }).then(r => r.data),
    },
    // Leave Reports & Analytics (final phase) — read-only.
    reports: {
      filters:     ()            => api.get('/hr/leave/reports/filters').then(r => r.data),
      dashboard:   ()            => api.get('/hr/leave/reports/dashboard').then(r => r.data),
      employees:   (params = {}) => api.get('/hr/leave/reports/employees', { params }).then(r => r.data),
      departments: (params = {}) => api.get('/hr/leave/reports/departments', { params }).then(r => r.data),
      types:       (params = {}) => api.get('/hr/leave/reports/types', { params }).then(r => r.data),
      balances:    (params = {}) => api.get('/hr/leave/reports/balances', { params }).then(r => r.data),
      holidays:    (params = {}) => api.get('/hr/leave/reports/holidays', { params }).then(r => r.data),
      trends:      (params = {}) => api.get('/hr/leave/reports/trends', { params }).then(r => r.data),
      export: (report, format, params = {}) => api.get('/hr/leave/reports/export', { params: { ...params, report, format }, responseType: 'blob' }).then(r => {
        const url = URL.createObjectURL(r.data)
        const a = document.createElement('a'); a.href = url; a.download = `leave-${report}-report.${format === 'pdf' ? 'pdf' : 'csv'}`; a.click()
        setTimeout(() => URL.revokeObjectURL(url), 1500)
      }),
    },
  },

  // ── Exit / Separation Management — Phase 1 (Types + Policies) ───────────
  exit: {
    types: {
      list:      (params = {}) => api.get('/hr/exit/types', { params }).then(r => r.data),
      create:    (data)        => api.post('/hr/exit/types', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/exit/types/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/exit/types/${id}/status`, { is_active: active }).then(r => r.data),
    },
    policies: {
      list:      (params = {}) => api.get('/hr/exit/policies', { params }).then(r => r.data),
      get:       (id)          => api.get(`/hr/exit/policies/${id}`).then(r => r.data),
      create:    (data)        => api.post('/hr/exit/policies', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/exit/policies/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/exit/policies/${id}/status`, { is_active: active }).then(r => r.data),
    },
    // Exit Requests (Phase 2). create/update send multipart (optional attachment);
    // update uses POST + _method spoof so PHP parses the upload on an edit.
    requests: {
      list:        (params = {}) => api.get('/hr/exit/requests', { params }).then(r => r.data),
      get:         (id)          => api.get(`/hr/exit/requests/${id}`).then(r => r.data),
      forEmployee: (employeeId)  => api.get(`/hr/exit/requests/employee/${employeeId}`).then(r => r.data),
      create:      (formData)    => api.post('/hr/exit/requests', formData, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data),
      update:      (id, formData) => { formData.append('_method', 'PUT'); return api.post(`/hr/exit/requests/${id}`, formData, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data) },
      submit:      (id)          => api.patch(`/hr/exit/requests/${id}/submit`).then(r => r.data),
      withdraw:    (id, data)    => api.patch(`/hr/exit/requests/${id}/withdraw`, data).then(r => r.data),
      attachmentUrl: (id)        => `${BASE}/hr/exit/requests/${id}/attachment`,
    },
    // Exit Approval workflow (Phase 3). Submitted → Under Review → Approved / Rejected.
    approvals: {
      list:        (params = {}) => api.get('/hr/exit/approvals', { params }).then(r => r.data),
      get:         (id)          => api.get(`/hr/exit/approvals/${id}`).then(r => r.data),
      history:     (params = {}) => api.get('/hr/exit/approvals/history', { params }).then(r => r.data),
      startReview: (id, remarks) => api.patch(`/hr/exit/approvals/${id}/review`, { review_remarks: remarks }).then(r => r.data),
      updateRemarks: (id, remarks) => api.patch(`/hr/exit/approvals/${id}/remarks`, { review_remarks: remarks }).then(r => r.data),
      approve:     (id, remarks) => api.patch(`/hr/exit/approvals/${id}/approve`, { remarks }).then(r => r.data),
      reject:      (id, remarks) => api.patch(`/hr/exit/approvals/${id}/reject`, { remarks }).then(r => r.data),
    },
    // Departmental Clearance (Phase 4). Each department clears independently;
    // overall completes when every mandatory department is cleared.
    clearances: {
      list:        (params = {}) => api.get('/hr/exit/clearances', { params }).then(r => r.data),
      get:         (id)          => api.get(`/hr/exit/clearances/${id}`).then(r => r.data),
      history:     (params = {}) => api.get('/hr/exit/clearances/history', { params }).then(r => r.data),
      forEmployee: (employeeId)  => api.get(`/hr/exit/clearances/employee/${employeeId}`).then(r => r.data),
      start:       (id, itemId, data = {}) => api.patch(`/hr/exit/clearances/${id}/items/${itemId}/start`, data).then(r => r.data),
      clear:       (id, itemId, remarks)   => api.patch(`/hr/exit/clearances/${id}/items/${itemId}/clear`, { remarks }).then(r => r.data),
      reject:      (id, itemId, remarks)   => api.patch(`/hr/exit/clearances/${id}/items/${itemId}/reject`, { remarks }).then(r => r.data),
      remarks:     (id, itemId, data = {}) => api.patch(`/hr/exit/clearances/${id}/items/${itemId}/remarks`, data).then(r => r.data),
    },
    // Full & Final Settlement (Phase 5). Frozen snapshot from payroll/salary/leave (read-only).
    settlements: {
      list:        (params = {}) => api.get('/hr/exit/settlements', { params }).then(r => r.data),
      get:         (id)          => api.get(`/hr/exit/settlements/${id}`).then(r => r.data),
      history:     (params = {}) => api.get('/hr/exit/settlements/history', { params }).then(r => r.data),
      forEmployee: (employeeId)  => api.get(`/hr/exit/settlements/employee/${employeeId}`).then(r => r.data),
      generate:    (id, inputs = {}) => api.post(`/hr/exit/settlements/${id}/generate`, inputs).then(r => r.data),
      review:      (id)          => api.patch(`/hr/exit/settlements/${id}/review`).then(r => r.data),
      approve:     (id)          => api.patch(`/hr/exit/settlements/${id}/approve`).then(r => r.data),
      settle:      (id)          => api.patch(`/hr/exit/settlements/${id}/settle`).then(r => r.data),
    },
    // Exit Reports & Analytics (Phase 6) — read-only. Export reuses the shared CSV/PDF pattern.
    reports: {
      filters:     ()            => api.get('/hr/exit/reports/filters').then(r => r.data),
      dashboard:   ()            => api.get('/hr/exit/reports/dashboard').then(r => r.data),
      employees:   (params = {}) => api.get('/hr/exit/reports/employees', { params }).then(r => r.data),
      departments: (params = {}) => api.get('/hr/exit/reports/departments', { params }).then(r => r.data),
      exitTypes:   (params = {}) => api.get('/hr/exit/reports/exit-types', { params }).then(r => r.data),
      settlements: (params = {}) => api.get('/hr/exit/reports/settlements', { params }).then(r => r.data),
      clearances:  (params = {}) => api.get('/hr/exit/reports/clearances', { params }).then(r => r.data),
      trends:      (params = {}) => api.get('/hr/exit/reports/trends', { params }).then(r => r.data),
      export: (report, format, params = {}) => api.get('/hr/exit/reports/export', { params: { ...params, report, format }, responseType: 'blob' }).then(r => {
        const url = URL.createObjectURL(r.data)
        const a = document.createElement('a'); a.href = url; a.download = `exit-${report}-report.${format === 'pdf' ? 'pdf' : 'csv'}`; a.click()
        setTimeout(() => URL.revokeObjectURL(url), 1500)
      }),
    },
  },

  // ── Learning & Development — Phase 1 (Training Masters) ─────────────────
  learning: {
    categories: {
      list:      (params = {}) => api.get('/hr/learning/categories', { params }).then(r => r.data),
      create:    (data)        => api.post('/hr/learning/categories', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/learning/categories/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/learning/categories/${id}/status`, { is_active: active }).then(r => r.data),
    },
    types: {
      list:      (params = {}) => api.get('/hr/learning/types', { params }).then(r => r.data),
      create:    (data)        => api.post('/hr/learning/types', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/learning/types/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/learning/types/${id}/status`, { is_active: active }).then(r => r.data),
    },
    providers: {
      list:      (params = {}) => api.get('/hr/learning/providers', { params }).then(r => r.data),
      create:    (data)        => api.post('/hr/learning/providers', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/learning/providers/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/learning/providers/${id}/status`, { is_active: active }).then(r => r.data),
    },
    programs: {
      list:      (params = {}) => api.get('/hr/learning/programs', { params }).then(r => r.data),
      get:       (id)          => api.get(`/hr/learning/programs/${id}`).then(r => r.data),
      create:    (data)        => api.post('/hr/learning/programs', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/learning/programs/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/learning/programs/${id}/status`, { is_active: active }).then(r => r.data),
    },
    sessions: {
      list:      (params = {}) => api.get('/hr/learning/sessions', { params }).then(r => r.data),
      calendar:  (params = {}) => api.get('/hr/learning/sessions/calendar', { params }).then(r => r.data),
      get:       (id)          => api.get(`/hr/learning/sessions/${id}`).then(r => r.data),
      create:    (data)        => api.post('/hr/learning/sessions', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/learning/sessions/${id}`, data).then(r => r.data),
      setStatus: (id, status)  => api.patch(`/hr/learning/sessions/${id}/status`, { status }).then(r => r.data),
    },
    assignments: {
      list:        (params = {}) => api.get('/hr/learning/assignments', { params }).then(r => r.data),
      get:         (id)          => api.get(`/hr/learning/assignments/${id}`).then(r => r.data),
      forEmployee: (employeeId)  => api.get(`/hr/learning/assignments/employee/${employeeId}`).then(r => r.data),
      history:     (params = {}) => api.get('/hr/learning/assignments/history', { params }).then(r => r.data),
      assign:      (data)        => api.post('/hr/learning/assignments', data).then(r => r.data),
      start:       (id, data = {}) => api.patch(`/hr/learning/assignments/${id}/start`, data).then(r => r.data),
      complete:    (id, remarks) => api.patch(`/hr/learning/assignments/${id}/complete`, { remarks }).then(r => r.data),
      cancel:      (id, remarks) => api.patch(`/hr/learning/assignments/${id}/cancel`, { remarks }).then(r => r.data),
    },
    // Training Attendance (Phase 5) — separate from office attendance / SangoeTrack.
    attendance: {
      list:    (params = {}) => api.get('/hr/learning/attendance', { params }).then(r => r.data),
      roster:  (sessionId)   => api.get(`/hr/learning/attendance/roster/${sessionId}`).then(r => r.data),
      get:     (id)          => api.get(`/hr/learning/attendance/${id}`).then(r => r.data),
      mark:    (data)        => api.post('/hr/learning/attendance', data).then(r => r.data),
      update:  (id, data)    => api.put(`/hr/learning/attendance/${id}`, data).then(r => r.data),
    },
    assessments: {
      list:   (params = {}) => api.get('/hr/learning/assessments', { params }).then(r => r.data),
      get:    (id)          => api.get(`/hr/learning/assessments/${id}`).then(r => r.data),
      create: (data)        => api.post('/hr/learning/assessments', data).then(r => r.data),
      update: (id, data)    => api.put(`/hr/learning/assessments/${id}`, data).then(r => r.data),
    },
    quizzes: {
      list:   (params = {}) => api.get('/hr/learning/quizzes', { params }).then(r => r.data),
      get:    (id)          => api.get(`/hr/learning/quizzes/${id}`).then(r => r.data),
      create: (data)        => api.post('/hr/learning/quizzes', data).then(r => r.data),
      update: (id, data)    => api.put(`/hr/learning/quizzes/${id}`, data).then(r => r.data),
    },
    // Certificates + Completion (Phase 6).
    certificates: {
      list:     (params = {}) => api.get('/hr/learning/certificates', { params }).then(r => r.data),
      get:      (id)          => api.get(`/hr/learning/certificates/${id}`).then(r => r.data),
      generate: (data)        => api.post('/hr/learning/certificates', data).then(r => r.data),
      expire:   (id)          => api.patch(`/hr/learning/certificates/${id}/expire`).then(r => r.data),
      upload:   (id, formData) => api.post(`/hr/learning/certificates/${id}/upload`, formData, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data),
      downloadUrl: (id)       => `${BASE}/hr/learning/certificates/${id}/download`,
      download: (id) => api.get(`/hr/learning/certificates/${id}/download`, { responseType: 'blob' }).then(r => {
        const url = URL.createObjectURL(r.data)
        const a = document.createElement('a'); a.href = url; a.download = `certificate-${id}.pdf`; a.click()
        setTimeout(() => URL.revokeObjectURL(url), 1500)
      }),
    },
    completion: {
      list:        (params = {}) => api.get('/hr/learning/completion', { params }).then(r => r.data),
      forEmployee: (employeeId)  => api.get(`/hr/learning/completion/employee/${employeeId}`).then(r => r.data),
    },
    // Training Reports & Analytics (Phase 7) — read-only; export reuses shared CSV/PDF.
    reports: {
      filters:      ()            => api.get('/hr/learning/reports/filters').then(r => r.data),
      dashboard:    ()            => api.get('/hr/learning/reports/dashboard').then(r => r.data),
      employees:    (params = {}) => api.get('/hr/learning/reports/employees', { params }).then(r => r.data),
      departments:  (params = {}) => api.get('/hr/learning/reports/departments', { params }).then(r => r.data),
      programs:     (params = {}) => api.get('/hr/learning/reports/programs', { params }).then(r => r.data),
      trainers:     (params = {}) => api.get('/hr/learning/reports/trainers', { params }).then(r => r.data),
      attendance:   (params = {}) => api.get('/hr/learning/reports/attendance', { params }).then(r => r.data),
      assessments:  (params = {}) => api.get('/hr/learning/reports/assessments', { params }).then(r => r.data),
      certificates: (params = {}) => api.get('/hr/learning/reports/certificates', { params }).then(r => r.data),
      completion:   (params = {}) => api.get('/hr/learning/reports/completion', { params }).then(r => r.data),
      trends:       (params = {}) => api.get('/hr/learning/reports/trends', { params }).then(r => r.data),
      export: (report, format, params = {}) => api.get('/hr/learning/reports/export', { params: { ...params, report, format }, responseType: 'blob' }).then(r => {
        const url = URL.createObjectURL(r.data)
        const a = document.createElement('a'); a.href = url; a.download = `training-${report}-report.${format === 'pdf' ? 'pdf' : 'csv'}`; a.click()
        setTimeout(() => URL.revokeObjectURL(url), 1500)
      }),
    },
  },

  // ── Probation Management — Phase 1 (Masters & Policies) ─────────────────
  probation: {
    types: {
      list:      (params = {}) => api.get('/hr/probation/types', { params }).then(r => r.data),
      create:    (data)        => api.post('/hr/probation/types', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/probation/types/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/probation/types/${id}/status`, { is_active: active }).then(r => r.data),
    },
    policies: {
      list:      (params = {}) => api.get('/hr/probation/policies', { params }).then(r => r.data),
      get:       (id)          => api.get(`/hr/probation/policies/${id}`).then(r => r.data),
      create:    (data)        => api.post('/hr/probation/policies', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/probation/policies/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/probation/policies/${id}/status`, { is_active: active }).then(r => r.data),
    },
    // Employee Probation (Phase 2).
    employees: {
      list:        (params = {}) => api.get('/hr/probation/employees', { params }).then(r => r.data),
      get:         (id)          => api.get(`/hr/probation/employees/${id}`).then(r => r.data),
      forEmployee: (employeeId)  => api.get(`/hr/probation/employees/employee/${employeeId}`).then(r => r.data),
      assign:      (data)        => api.post('/hr/probation/employees', data).then(r => r.data),
      update:      (id, data)    => api.put(`/hr/probation/employees/${id}`, data).then(r => r.data),
      activate:    (id)          => api.patch(`/hr/probation/employees/${id}/activate`).then(r => r.data),
      cancel:      (id, remarks) => api.patch(`/hr/probation/employees/${id}/cancel`, { remarks }).then(r => r.data),
    },
    // Probation Reviews (Phase 3).
    reviews: {
      list:        (params = {}) => api.get('/hr/probation/reviews', { params }).then(r => r.data),
      get:         (id)          => api.get(`/hr/probation/reviews/${id}`).then(r => r.data),
      forEmployee: (employeeId)  => api.get(`/hr/probation/reviews/employee/${employeeId}`).then(r => r.data),
      create:      (data)        => api.post('/hr/probation/reviews', data).then(r => r.data),
      update:      (id, data)    => api.put(`/hr/probation/reviews/${id}`, data).then(r => r.data),
      submit:      (id)          => api.patch(`/hr/probation/reviews/${id}/submit`).then(r => r.data),
      complete:    (id)          => api.patch(`/hr/probation/reviews/${id}/complete`).then(r => r.data),
    },
    // Probation Extensions (Phase 4).
    extensions: {
      list:        (params = {}) => api.get('/hr/probation/extensions', { params }).then(r => r.data),
      get:         (id)          => api.get(`/hr/probation/extensions/${id}`).then(r => r.data),
      history:     (params = {}) => api.get('/hr/probation/extensions/history', { params }).then(r => r.data),
      forEmployee: (employeeId)  => api.get(`/hr/probation/extensions/employee/${employeeId}`).then(r => r.data),
      request:     (data)        => api.post('/hr/probation/extensions', data).then(r => r.data),
      update:      (id, data)    => api.put(`/hr/probation/extensions/${id}`, data).then(r => r.data),
      approve:     (id, hrComments) => api.patch(`/hr/probation/extensions/${id}/approve`, { hr_comments: hrComments }).then(r => r.data),
      reject:      (id, hrComments) => api.patch(`/hr/probation/extensions/${id}/reject`, { hr_comments: hrComments }).then(r => r.data),
    },
    // Probation Confirmations (Phase 5).
    confirmations: {
      list:        (params = {}) => api.get('/hr/probation/confirmations', { params }).then(r => r.data),
      get:         (id)          => api.get(`/hr/probation/confirmations/${id}`).then(r => r.data),
      history:     (params = {}) => api.get('/hr/probation/confirmations/history', { params }).then(r => r.data),
      forEmployee: (employeeId)  => api.get(`/hr/probation/confirmations/employee/${employeeId}`).then(r => r.data),
      create:      (data)        => api.post('/hr/probation/confirmations', data).then(r => r.data),
      update:      (id, data)    => api.put(`/hr/probation/confirmations/${id}`, data).then(r => r.data),
      approve:     (id, hrComments) => api.patch(`/hr/probation/confirmations/${id}/approve`, { hr_comments: hrComments }).then(r => r.data),
      reject:      (id, hrComments) => api.patch(`/hr/probation/confirmations/${id}/reject`, { hr_comments: hrComments }).then(r => r.data),
      confirm:     (id, data = {}) => api.patch(`/hr/probation/confirmations/${id}/confirm`, data).then(r => r.data),
    },
    // Probation Reports & Analytics (Phase 6) — read-only; export reuses shared CSV/PDF.
    reports: {
      filters:       ()            => api.get('/hr/probation/reports/filters').then(r => r.data),
      dashboard:     ()            => api.get('/hr/probation/reports/dashboard').then(r => r.data),
      employees:     (params = {}) => api.get('/hr/probation/reports/employees', { params }).then(r => r.data),
      departments:   (params = {}) => api.get('/hr/probation/reports/departments', { params }).then(r => r.data),
      policies:      (params = {}) => api.get('/hr/probation/reports/policies', { params }).then(r => r.data),
      reviews:       (params = {}) => api.get('/hr/probation/reports/reviews', { params }).then(r => r.data),
      extensions:    (params = {}) => api.get('/hr/probation/reports/extensions', { params }).then(r => r.data),
      confirmations: (params = {}) => api.get('/hr/probation/reports/confirmations', { params }).then(r => r.data),
      trends:        (params = {}) => api.get('/hr/probation/reports/trends', { params }).then(r => r.data),
      export: (report, format, params = {}) => api.get('/hr/probation/reports/export', { params: { ...params, report, format }, responseType: 'blob' }).then(r => {
        const url = URL.createObjectURL(r.data)
        const a = document.createElement('a'); a.href = url; a.download = `probation-${report}-report.${format === 'pdf' ? 'pdf' : 'csv'}`; a.click()
        setTimeout(() => URL.revokeObjectURL(url), 1500)
      }),
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

  // ── Central Notification & Reminder Engine (platform foundation) ────────
  notifications: {
    // Recipient feed — navbar bell, Notification Center, history
    list:        (params = {}) => api.get('/hr/notifications', { params }).then(r => r.data),
    bell:        (limit = 10)  => api.get('/hr/notifications/bell', { params: { limit } }).then(r => r.data),
    unreadCount: ()            => api.get('/hr/notifications/unread-count').then(r => r.data),
    stats:       ()            => api.get('/hr/notifications/stats').then(r => r.data),
    catalog:     ()            => api.get('/hr/notifications/catalog').then(r => r.data),
    get:         (id)          => api.get(`/hr/notifications/${id}`).then(r => r.data),
    markRead:    (id)          => api.patch(`/hr/notifications/${id}/read`).then(r => r.data),
    markAllRead: ()            => api.post('/hr/notifications/mark-all-read').then(r => r.data),
    forEmployee: (employeeId)  => api.get(`/hr/notifications/employee/${employeeId}`).then(r => r.data),
    resend:      (id)          => api.post(`/hr/notifications/${id}/resend`).then(r => r.data),
    // Templates
    templates: {
      list:      (params = {}) => api.get('/hr/notifications/templates', { params }).then(r => r.data),
      create:    (data)        => api.post('/hr/notifications/templates', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/notifications/templates/${id}`, data).then(r => r.data),
      setStatus: (id, active)  => api.patch(`/hr/notifications/templates/${id}/status`, { is_active: active }).then(r => r.data),
      seed:      ()            => api.post('/hr/notifications/templates/seed').then(r => r.data),
    },
    // Reminder / escalation rules
    rules: {
      list:      (params = {}) => api.get('/hr/notifications/rules', { params }).then(r => r.data),
      create:    (data)        => api.post('/hr/notifications/rules', data).then(r => r.data),
      update:    (id, data)    => api.put(`/hr/notifications/rules/${id}`, data).then(r => r.data),
      setStatus: (id, enabled) => api.patch(`/hr/notifications/rules/${id}/status`, { enabled }).then(r => r.data),
    },
    // Delivery queue monitor
    queue: {
      list:    (params = {}) => api.get('/hr/notifications/queue', { params }).then(r => r.data),
      failed:  (params = {}) => api.get('/hr/notifications/queue/failed', { params }).then(r => r.data),
      stats:   ()            => api.get('/hr/notifications/queue/stats').then(r => r.data),
      process: ()            => api.post('/hr/notifications/queue/process').then(r => r.data),
      retry:   (id)          => api.post(`/hr/notifications/queue/${id}/retry`).then(r => r.data),
    },
  },
}

export default hrApi
