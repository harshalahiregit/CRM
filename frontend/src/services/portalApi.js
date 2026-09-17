/**
 * Vendor Self-Service Portal API.
 *
 * Uses the shared axios client on purpose: a vendor has a real login, so the
 * bearer token + 401→/auth/login behaviour is exactly right here (unlike the
 * public gate-scan / checklist-fill clients, which are bare because their users
 * have no login). Every endpoint resolves the vendor from the token server-side
 * — there is no vendor id to pass.
 *
 * TPV self-service section: mirrors the tpvApi shape so existing page components
 * (TpvOnboardingWizard, TpvWorkers, etc.) can swap between tpvApi and portalApi
 * based on user.role without any changes to the component logic.
 */
import api from '@/lib/api'

const upload = (url, formData) =>
  api.post(url, formData, { headers: { 'Content-Type': undefined } }).then(r => r.data)

export const portalApi = {
  me:         () => api.get('/portal/me').then(r => r.data),

  // ── In-app (bell) notifications — the vendor's own, from the shared store ──
  notifications: {
    list:        () => api.get('/portal/notifications').then(r => r.data),
    markRead:    (id) => api.patch(`/portal/notifications/${id}/read`).then(r => r.data),
    markAllRead: () => api.post('/portal/notifications/read-all').then(r => r.data),
  },

  // ── My Work — projects/tasks/tickets assigned to this vendor / TPV ──────
  // Role-gated (not vendor.portal), so it works even before a vendor-master
  // profile exists. Returns the unwrapped payload.
  myWork: {
    summary:  () => api.get('/portal/my-work/summary').then(r => r.data?.data ?? r.data),
    projects: () => api.get('/portal/my-work/projects').then(r => r.data?.data ?? r.data),
    tasks:    () => api.get('/portal/my-work/tasks').then(r => r.data?.data ?? r.data),
    taskStatuses:    () => api.get('/portal/my-work/task-statuses').then(r => r.data?.data ?? r.data),
    updateTaskStatus:(id, status) => api.patch(`/portal/my-work/tasks/${id}/status`, { status }).then(r => r.data?.data ?? r.data),
    // One task in full — brief, checklist, conversation, files.
    task:        (id) => api.get(`/portal/my-work/tasks/${id}`).then(r => r.data?.data ?? r.data),
    commentTask: (id, { body = '', files = [] } = {}) => {
      const fd = new FormData()
      if (body) fd.append('body', body)
      files.forEach(f => fd.append('files[]', f))
      return upload(`/portal/my-work/tasks/${id}/comments`, fd)
    },
    // Task files are private: the download is an authenticated request, so it
    // comes back as a blob rather than a link the browser could follow on its own.
    taskFile: async (id, fileId) => {
      const res = await api.get(`/portal/my-work/tasks/${id}/files/${fileId}`, { responseType: 'blob' })
      return URL.createObjectURL(res.data)
    },
    tickets:  () => api.get('/portal/my-work/tickets').then(r => r.data?.data ?? r.data),
    raiseTicket:  (body) => api.post('/portal/my-work/tickets', body).then(r => r.data),
    ticket:       (id) => api.get(`/portal/my-work/tickets/${id}`).then(r => r.data),
    replyTicket:  (id, message) => api.post(`/portal/my-work/tickets/${id}/reply`, { message }).then(r => r.data),
    expenses:     () => api.get('/portal/my-work/expenses').then(r => r.data?.data ?? r.data),
    logExpense:   (body) => api.post('/portal/my-work/expenses', body).then(r => r.data),
    kb:       () => api.get('/portal/my-work/kb').then(r => r.data?.data ?? r.data),
    kbArticle: (slug) => api.get(`/portal/my-work/kb/${slug}`).then(r => r.data?.data ?? r.data),
  },

  // ── Onboarding — mirrors tpvApi.onboarding shape ───────────────────────
  // list() wraps the single-record response in an array so TpvOnboardings
  // renders without modification. stats() is derived client-side from the record.
  onboarding: {
    list: async () => {
      const r = await api.get('/portal/onboarding')
      const ob = r.data?.onboarding
      return { data: ob ? [ob] : [] }
    },
    stats: async () => {
      const r = await api.get('/portal/onboarding')
      const ob = r.data?.onboarding
      if (!ob) return { total: 0, in_progress: 0, awaiting: 0, approved: 0, rejected: 0 }
      return {
        total:       1,
        in_progress: ob.status === 'In_Progress' ? 1 : 0,
        awaiting:    ob.status === 'Submitted' || ob.status === 'Under_Review' ? 1 : 0,
        approved:    ob.status === 'Approved' ? 1 : 0,
        rejected:    ob.status === 'Rejected' ? 1 : 0,
      }
    },
    get:      (id) => api.get(`/portal/onboarding/${id}`).then(r => r.data),
    progress: (id) => api.get(`/portal/onboarding/${id}/progress`).then(r => r.data),
    // Wizard write actions
    // `draft` — see tpvApi.saveProfile; both engines behave identically here.
    saveProfile:     (id, profile, draft = false) => api.post(`/portal/onboarding/${id}/profile`, { profile, draft }).then(r => r.data),
    setStep:         (id, step)    => api.patch(`/portal/onboarding/${id}/step`, { step }).then(r => r.data),
    submit:          (id, data={}) => api.post(`/portal/onboarding/${id}/submit`, data).then(r => r.data),
    // Step 1 — Kickoff PDF
    kickoffPdf:      (id)          => api.get(`/portal/onboarding/${id}/kickoff`, { responseType: 'blob' }).then(r => r.data),
    // The same minutes the PDF prints, as data — resolved by the SAME
    // server-side resolver, so the screen and the document can never
    // describe two different meetings.
    kickoffData:     (id)        => api.get(`/portal/onboarding/${id}/kickoff-data`).then(r => r.data),
    workStartLetter: (id)          => api.get(`/portal/onboarding/${id}/work-start-letter`, { responseType: 'blob' }).then(r => r.data),
    acceptKickoff:   (id, comment) => api.post(`/portal/onboarding/${id}/kickoff/accept`, comment ? { comment } : {}).then(r => r.data),
    logKickoffEvent: (id, event)   => api.post(`/portal/onboarding/${id}/kickoff/log`, { event }).then(r => r.data),
    // Admin-only — vendors cannot create, approve or delete onboardings
    create:          ()   => Promise.reject(new Error('Not available in vendor portal')),
    delete:          ()   => Promise.reject(new Error('Not available in vendor portal')),
    approve:         ()   => Promise.reject(new Error('Admin only')),
    requestResubmit: ()   => Promise.reject(new Error('Admin only')),
  },

  // ── Documents — mirrors tpvApi.documents shape ──────────────────────────
  /*
   * Compliance agencies a vendor can be handed off to when they do not hold a
   * document yet. `list` returns only agencies with a lead address configured,
   * so the panel offers nobody it cannot actually reach — the whole feature
   * stays dark until Settings → Service Providers is filled in.
   */
  serviceProviders: {
    list: () => api.get('/portal/service-providers').then(r => r.data),
    requestCallback: (providerId, payload) =>
      api.post(`/portal/service-providers/${providerId}/callback`, payload).then(r => r.data),
  },

  documents: {
    checklist: () => api.get('/portal/documents').then(r => r.data),
    // Nested under the namespace the panel is handed, so the same component
    // reaches the right portal without knowing which one it is in.
    serviceProviders: {
      list: () => api.get('/portal/service-providers').then(r => r.data),
      requestCallback: (providerId, payload) =>
        api.post(`/portal/service-providers/${providerId}/callback`, payload).then(r => r.data),
    },
    upload:    (_vendorId, type, file) => {
      const fd = new FormData()
      fd.append('type', type)
      fd.append('file', file)
      return upload('/portal/documents', fd)
    },
    resubmit: (documentId, file) => {
      const fd = new FormData()
      fd.append('file', file)
      return upload(`/portal/documents/${documentId}/resubmit`, fd)
    },
    open: async (documentId) => {
      const res = await api.get(`/portal/documents/${documentId}/download`, { responseType: 'blob' })
      return URL.createObjectURL(res.data)
    },
    // A vendor may take back and inspect its OWN work: delete an unapproved
    // document it uploaded by mistake, and read the versions its own
    // replacements archived. The onboarding wizard has always drawn Delete and
    // History on the portal; both were stubs, so Delete answered "Admin only"
    // and History always reported no history at all.
    delete:   (documentId) => api.delete(`/portal/documents/${documentId}`).then(r => r.data),
    versions: (documentId) => api.get(`/portal/documents/${documentId}/versions`).then(r => r.data),
    downloadVersion: (documentId, versionId) =>
      api.get(`/portal/documents/${documentId}/versions/${versionId}/download`, { responseType: 'blob' }).then(r => r.data),
    // Genuinely admin-only: a vendor may never approve or reject its own
    // document, and may not roll one back to a version an admin already judged.
    review:         () => Promise.reject(new Error('Admin only')),
    restoreVersion: () => Promise.reject(new Error('Admin only')),
  },

  // ── Contacts — mirrors tpvApi.contacts shape ────────────────────────────
  // vendorId param is accepted but ignored — the backend resolves own vendor.
  contacts: {
    list:      (_vendorId, params={}) => api.get('/portal/contacts', { params }).then(r => r.data),
    create:    (_vendorId, data)      => api.post('/portal/contacts', data).then(r => r.data),
    update:    (_vendorId, id, data)  => api.put(`/portal/contacts/${id}`, data).then(r => r.data),
    setStatus: (_vendorId, id, status)=> api.patch(`/portal/contacts/${id}/status`, { status }).then(r => r.data),
  },

  // Read-only: the vendor's own work packages (for the worker-wizard deploy
  // field). Mirrors tpvApi.workPackages.list; vendor_id forced server-side.
  workPackages: {
    list: (params = {}) => api.get('/portal/work-packages', { params }).then(r => r.data?.data ?? r.data),
  },

  // ── Workers — mirrors tpvApi.workers shape ──────────────────────────────
  // vendor_id in params is silently overridden server-side.
  // Training across this vendor's workers. Read and write, so a certificate
  // filed here can be seen again — it could be written and never read back.
  trainings: () => api.get('/portal/trainings').then(r => r.data?.data ?? r.data),

  workers: {
    list:          (params={}) => api.get('/portal/workers', { params }).then(r => r.data),
    stats:         ()          => api.get('/portal/workers/stats').then(r => r.data),
    get:           (id)        => api.get(`/portal/workers/${id}`).then(r => r.data),
    progress:      (id)        => api.get(`/portal/workers/${id}/progress`).then(r => r.data),
    create:        (data)      => api.post('/portal/workers', data).then(r => r.data),
    update:        (id, data)  => api.put(`/portal/workers/${id}`, data).then(r => r.data),
    saveMedical:   (id, data)  => api.post(`/portal/workers/${id}/medical`, data, data instanceof FormData ? { headers: { 'Content-Type': undefined } } : undefined).then(r => r.data),
    saveInduction: (id, data)  => api.post(`/portal/workers/${id}/induction`, data).then(r => r.data),
    // The typed training catalogue. Multipart when a certificate is attached —
    // axios must be left to set its own boundary, hence the undefined header.
    saveTraining:  (id, data)  => api.post(`/portal/workers/${id}/training`, data,
      data instanceof FormData ? { headers: { 'Content-Type': undefined } } : undefined).then(r => r.data),
    // Portal-owned, ownership-checked. These two used to hit the admin /tpv/*
    // routes, which forced third_party_vendor into the admin role gate.
    markPunch:       (id, punch_count, punch_reason) => api.post(`/portal/workers/${id}/mark-punch`, { punch_count, punch_reason }).then(r => r.data),
    markCardStatus:  (id, card_status) => api.post(`/portal/workers/${id}/mark-card-status`, { card_status }).then(r => r.data),
    // Portal-owned like markPunch/markCardStatus above. Posting this to the
    // admin /tpv route is what told every vendor "Unauthorized. Required role:
    // admin or staff". No vendor_id is sent — the server takes it from the token.
    uploadWorkers: (file) => {
      const fd = new FormData()
      fd.append('worker_file', file)
      return upload('/portal/workers/upload', fd)
    },
    // Read-only: the vendor VIEWS the admin-issued badge and, until it is issued,
    // sees exactly what is still blocking it. Issuing itself stays admin-only.
    badge:     (id) => api.get(`/portal/workers/${id}/badge`).then(r => r.data),
    // Admin-only
    activate:  () => Promise.reject(new Error('Requires admin approval')),
    suspend:   () => Promise.reject(new Error('Admin only')),
    reinstate: () => Promise.reject(new Error('Admin only')),
    terminate: () => Promise.reject(new Error('Admin only')),
    delete:    () => Promise.reject(new Error('Admin only')),
  },

  // ── Gate — mirrors tpvApi.gate shape ────────────────────────────────────
  gate: {
    stats:            ()             => api.get('/portal/gate/stats').then(r => r.data),
    log:              (params={})    => api.get('/portal/gate-log', { params }).then(r => r.data),
    roster:           (date=null)    => api.get('/portal/attendance', { params: date ? { date } : {} }).then(r => r.data),
    workerAttendance: (wid, days=30) => api.get(`/portal/workers/${wid}/attendance`, { params: { days } }).then(r => r.data),
  },

  // ── Strikes — mirrors tpvApi.strikes shape ──────────────────────────────
  strikes: {
    list:      (params={}) => api.get('/portal/strikes', { params }).then(r => r.data),
    stats:     ()          => Promise.resolve({ total: 0 }),
    forWorker: (wid)       => api.get(`/portal/workers/${wid}/strikes`).then(r => r.data),
    // Admin-only
    issue: () => Promise.reject(new Error('Admin only')),
    void:  () => Promise.reject(new Error('Admin only')),
  },

  // ── Vendor — mirrors tpvApi.vendors.get() for vendor detail fetches ─────
  // In portal context "get vendor" always means own vendor — no id needed.
  vendors: {
    get:       ()   => api.get('/portal/me').then(r => r.data?.vendor ?? null),
    list:      ()   => Promise.resolve([]),
    setStatus: ()   => Promise.reject(new Error('Admin only')),
  },

  // ── Purchase-side portal (unchanged) ────────────────────────────────────
  orders:  () => api.get('/portal/orders').then(r => r.data),
  order:   (id) => api.get(`/portal/orders/${id}`).then(r => r.data),
  invoices: () => api.get('/portal/invoices').then(r => r.data),
  invoice: (id) => api.get(`/portal/invoices/${id}`).then(r => r.data),

  // Legacy flat API (kept for PortalDashboard, PortalDocuments)
  uploadDocument: (type, file) => {
    const fd = new FormData()
    fd.append('type', type)
    fd.append('file', file)
    return upload('/portal/documents', fd)
  },
  resubmitDocument: (docId, file) => {
    const fd = new FormData()
    fd.append('file', file)
    return upload(`/portal/documents/${docId}/resubmit`, fd)
  },
  downloadDocument: (docId) => api.get(`/portal/documents/${docId}/download`, { responseType: 'blob' }).then(r => r.data),
  // ── PPE — served from INVENTORY (single source of truth) ────────────
  ppe: {
    catalogue:   ()               => api.get('/portal/ppe').then(r => r.data),
    summary:     ()               => api.get('/portal/ppe/summary').then(r => r.data),
    forWorker:   (workerId)       => api.get(`/portal/ppe/workers/${workerId}`).then(r => r.data),
    issue:       (workerId, data) => api.post(`/portal/ppe/workers/${workerId}/issue`, data).then(r => r.data),
    returnIssue: (issueId, data)  => api.post(`/portal/ppe/issues/${issueId}/return`, data).then(r => r.data),
    // No holders() on the portal, deliberately. The controller behind it scopes
    // by TENANT only and returns every worker with that item plus their vendor's
    // company name — so exposing it to a vendor login would show one vendor another
    // vendor's workforce. The route was never registered; this method was the only
    // thing pointing at it, and calling it 404'd. Staff use tpvApi.ppe.holders,
    // which is correct for an internal screen.
    // Read-only: a vendor sees what its own workers still need, but cannot edit rules.
    workerCompliance: (workerId)  => api.get(`/portal/ppe/compliance/workers/${workerId}`).then(r => r.data),
    // Private file: fetched as a blob so the bearer token is sent.
    imageBlob:   (productId)      => api.get(`/portal/ppe/item/${productId}/image`, { responseType: 'blob' }).then(r => URL.createObjectURL(r.data)),
  },

  // §32 "View compliance" — the vendor's own compliance register (read-only).
  compliance: {
    get: () => api.get('/portal/compliance').then(r => r.data),
  },

  // General › Customer — the customers linked to this vendor (read-only).
  customers: () => api.get('/portal/customers').then(r => r.data?.data ?? r.data),

  // Compliance & HSSE — the vendor requests permits + reports incidents.
  hsse: {
    permits:        () => api.get('/portal/permits').then(r => r.data),
    requestPermit:  (body) => api.post('/portal/permits', body).then(r => r.data),
    incidents:      () => api.get('/portal/incidents').then(r => r.data),
    reportIncident: (body) => api.post('/portal/incidents', body).then(r => r.data),
  },

  // Pre Alert / Packages / Shipping — the vendor's dispatch notices.
  logistics: {
    shipments:      () => api.get('/portal/shipments').then(r => r.data),
    createShipment: (body) => api.post('/portal/shipments', body).then(r => r.data),
    updateStatus:   (id, status) => api.patch(`/portal/shipments/${id}/status`, { status }).then(r => r.data),
    packages:       () => api.get('/portal/shipment-packages').then(r => r.data),
  },

  // Performance — the vendor's own risk score, rating, penalties, awards, referrals.
  performance: {
    risk:           () => api.get('/portal/risk').then(r => r.data),
    feedback:       () => api.get('/portal/feedback').then(r => r.data),
    violations:     () => api.get('/portal/violations').then(r => r.data),
    awards:         () => api.get('/portal/awards').then(r => r.data),
    referrals:      () => api.get('/portal/referrals').then(r => r.data),
    submitReferral: (body) => api.post('/portal/referrals', body).then(r => r.data),
  },

  // §32 Governance-response half.
  governance: {
    ncrs:            ()            => api.get('/portal/ncrs').then(r => r.data),
    respondNcr:      (id, payload) => api.post(`/portal/ncrs/${id}/respond`, payload).then(r => r.data),
    capas:           ()            => api.get('/portal/capas').then(r => r.data),
    submitCapa:      (id, payload) => api.post(`/portal/capas/${id}/evidence`, payload).then(r => r.data),
    requestApproval: (payload)     => api.post('/portal/approvals/request', payload).then(r => r.data),
    requestExtension:(payload)     => api.post('/portal/extensions/request', payload).then(r => r.data),
    meetings:        ()            => api.get('/portal/meetings').then(r => r.data),
    meetingMom:      (id)          => api.get(`/portal/meetings/${id}/mom`).then(r => r.data),
    // Records that this person opened the meeting, then hands back the link.
    // A meeting held on Google Meet or Teams runs where we cannot see it, so
    // the click is the only evidence there is — and it is worth keeping.
    // Marking attendance is what releases the joining link — it is not in the
    // meetings payload until this returns. See MeetingAttendanceGate.
    // `where` is { latitude, longitude } when the browser offered them, {}
    // otherwise — see whereAmI. The address and device come from the request
    // itself; only the coordinates have to travel in the body.
    markAttendance:  (id, where = {}) => api.post(`/portal/meetings/${id}/attendance`, where).then(r => r.data),
    // The minutes document itself. Distributing minutes the recipient cannot
    // open is not distributing them — this had no route at all until now.
    meetingMomFile:  (id)          => api.get(`/portal/meetings/${id}/mom/file`, { responseType: 'blob' }).then(r => r.data),
    meetingDocument: (id, docId)   => api.get(`/portal/meetings/${id}/documents/${docId}/download`, { responseType: 'blob' }).then(r => r.data),
    actions:         ()            => api.get('/portal/actions').then(r => r.data),
    respondAction:   (id, payload) => api.post(`/portal/actions/${id}/respond`, payload).then(r => r.data),
    ppeMatrix:       ()            => api.get('/portal/ppe-matrix').then(r => r.data),
    uploadCertificate: (workerId, fd) => upload(`/portal/workers/${workerId}/certificates`, fd),
  },

}

export default portalApi
