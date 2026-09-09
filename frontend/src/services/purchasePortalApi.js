/**
 * Purchase Vendor Portal API — hits /portal/purchase/*. Every endpoint resolves
 * the vendor from the Purchase-vendor token server-side; there is no vendor id to
 * pass. Uses the dedicated Purchase-vendor axios instance (its own token),
 * completely independent of the shared user/vendor auth.
 */
import api from '@/lib/purchaseVendorApi'

const upload = (url, formData) =>
  api.post(url, formData, { headers: { 'Content-Type': undefined } }).then(r => r.data)

export const purchasePortalApi = {
  // Persist the welcome-banner dismissal server-side (never localStorage).
  dismissWelcomeBanner: () => api.post('/portal/purchase/welcome/dismiss').then(r => r.data),

  me: () => api.get('/portal/purchase/me').then(r => r.data),

  // ── In-app (bell) notifications — the vendor's own (Purchase-owned store) ──
  notifications: {
    list:        () => api.get('/portal/purchase/notifications').then(r => r.data),
    markRead:    (id) => api.patch(`/portal/purchase/notifications/${id}/read`).then(r => r.data),
    markAllRead: () => api.post('/portal/purchase/notifications/read-all').then(r => r.data),
  },

  // Rich dashboard payload (vendor, onboarding %, pending items, commercial counts).
  dashboard: () => api.get('/portal/purchase/dashboard').then(r => r.data),

  // Self-service profile + commercial fields (business fields only; never
  // code/category/status/auth). Maps to PUT /portal/purchase/profile.
  updateProfile: (payload) => api.put('/portal/purchase/profile', payload).then(r => r.data),

  // ── Onboarding (the vendor's own record) ────────────────────────────────
  // list() wraps the single-record response in an array for list-style
  // rendering; stats() is derived client-side from the record.
  onboarding: {
    list: async () => {
      const r = await api.get('/portal/purchase/onboarding')
      const ob = r.data?.onboarding
      return { data: ob ? [ob] : [] }
    },
    stats: async () => {
      const r = await api.get('/portal/purchase/onboarding')
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
    // Direct bootstrap of the caller's onboarding record, if needed elsewhere.
    self:     ()             => api.get('/portal/purchase/onboarding').then(r => r.data),
    get:      (id)           => api.get(`/portal/purchase/onboarding/${id}`).then(r => r.data),
    progress: (id)           => api.get(`/portal/purchase/onboarding/${id}/progress`).then(r => r.data),
    // `draft` — see tpvApi.saveProfile; both engines behave identically here.
    saveProfile: (id, profile, draft = false) => api.post(`/portal/purchase/onboarding/${id}/profile`, { profile, draft }).then(r => r.data),
    setStep:     (id, step)    => api.patch(`/portal/purchase/onboarding/${id}/step`, { step }).then(r => r.data),
    submit:      (id, data = {}) => api.post(`/portal/purchase/onboarding/${id}/submit`, data).then(r => r.data),
    // Step 1 — kickoff PDF / acknowledgement (by onboarding, own-vendor scoped).
    kickoffPdf:      (id)        => api.get(`/portal/purchase/onboarding/${id}/kickoff`, { responseType: 'blob' }).then(r => r.data),
    // The same minutes the PDF prints, as data — resolved by the SAME
    // server-side resolver, so the screen and the document can never
    // describe two different meetings.
    kickoffData:     (id)        => api.get(`/portal/purchase/onboarding/${id}/kickoff-data`).then(r => r.data),
    // The vendor's own proof they are cleared to start. Purchase had it on the
    // admin side and TPV had it in the portal; only this one was missing.
    workStartLetter: (id)        => api.get(`/portal/purchase/onboarding/${id}/work-start-letter`, { responseType: 'blob' }).then(r => r.data),
    acceptKickoff:   (id)        => api.post(`/portal/purchase/onboarding/${id}/kickoff/accept`).then(r => r.data),
    logKickoffEvent: (id, event) => api.post(`/portal/purchase/onboarding/${id}/kickoff/log`, { event }).then(r => r.data),
    // Admin-only — a portal vendor cannot create, approve, hold or delete.
    create:          ()   => Promise.reject(new Error('Not available in vendor portal')),
    delete:          ()   => Promise.reject(new Error('Not available in vendor portal')),
    approve:         ()   => Promise.reject(new Error('Admin only')),
    reject:          ()   => Promise.reject(new Error('Admin only')),
    hold:            ()   => Promise.reject(new Error('Admin only')),
    release:         ()   => Promise.reject(new Error('Admin only')),
    requestResubmit: ()   => Promise.reject(new Error('Admin only')),
  },

  // ── Documents — mirrors portalApi.documents shape (upload takes 3 args) ──
  documents: {
    checklist: ()          => api.get('/portal/purchase/documents').then(r => r.data),
    upload:    (_vendorId, type, file) => {
      const fd = new FormData()
      fd.append('type', type)
      fd.append('file', file)
      return upload('/portal/purchase/documents', fd)
    },
    resubmit: (documentId, file) => {
      const fd = new FormData()
      fd.append('file', file)
      return upload(`/portal/purchase/documents/${documentId}/resubmit`, fd)
    },
    open: async (documentId) => {
      const res = await api.get(`/portal/purchase/documents/${documentId}/download`, { responseType: 'blob' })
      return URL.createObjectURL(res.data)
    },
    // A vendor may take back and inspect its OWN work: delete an unapproved
    // document it uploaded by mistake, and read the versions its own
    // replacements archived. These were stubs — delete rejected with "Admin
    // only" and versions resolved to a hardcoded [] — while the screen drew the
    // buttons anyway, so both answered nothing. Approving is still not the
    // vendor's to do, and the server enforces that regardless of this file.
    delete:   (documentId) => api.delete(`/portal/purchase/documents/${documentId}`).then(r => r.data),
    versions: (documentId) => api.get(`/portal/purchase/documents/${documentId}/versions`).then(r => r.data),
    downloadVersion: (documentId, versionId) =>
      api.get(`/portal/purchase/documents/${documentId}/versions/${versionId}/download`, { responseType: 'blob' }).then(r => r.data),
    // Genuinely admin-only: a vendor may never approve or reject its own
    // document, and may not roll one back to a version an admin already judged.
    review:         () => Promise.reject(new Error('Admin only')),
    restoreVersion: () => Promise.reject(new Error('Admin only')),
  },

  // ── Contacts — mirrors portalApi.contacts shape (vendorId ignored) ──────
  contacts: {
    list:      (_vendorId, params = {}) => api.get('/portal/purchase/contacts', { params }).then(r => r.data),
    create:    (_vendorId, data)        => api.post('/portal/purchase/contacts', data).then(r => r.data),
    update:    (_vendorId, id, data)    => api.put(`/portal/purchase/contacts/${id}`, data).then(r => r.data),
    setStatus: (_vendorId, id, status)  => api.patch(`/portal/purchase/contacts/${id}/status`, { status }).then(r => r.data),
  },

  // ── Vendor — own vendor only (mirrors portalApi.vendors.get) ────────────
  vendors: {
    get:       () => api.get('/portal/purchase/me').then(r => r.data?.vendor ?? null),
    list:      () => Promise.resolve([]),
    setStatus: () => Promise.reject(new Error('Admin only')),
  },

  // ── Workforce — the vendor's own workers, resolved from the token ───────
  // No vendor_id is ever sent: the server reads it from the PurchaseVendor token
  // and 404s any worker that is not the caller's.
  trainings: () => api.get('/portal/purchase/trainings').then(r => r.data?.data ?? r.data),

  // Safety strikes against this vendor's workers — read-only. Issuing belongs
  // with the site, not with the company being struck.
  strikes: {
    list: (params = {}) => api.get('/portal/purchase/strikes', { params }).then(r => r.data?.data ?? r.data),
  },

  workers: {
    list:      (params = {}) => api.get('/portal/purchase/workers', { params }).then(r => r.data),
    summary:   ()            => api.get('/portal/purchase/workers/summary').then(r => r.data),
    get:       (id)          => api.get(`/portal/purchase/workers/${id}`).then(r => r.data),
    create:    (data)        => api.post('/portal/purchase/workers', data).then(r => r.data),
    update:    (id, data)    => api.put(`/portal/purchase/workers/${id}`, data).then(r => r.data),
    remove:    (id)          => api.delete(`/portal/purchase/workers/${id}`).then(r => r.data),
    readiness: (id)          => api.get(`/portal/purchase/workers/${id}/readiness`).then(r => r.data),
    medical:   (id, data)    => api.post(`/portal/purchase/workers/${id}/medical`, data).then(r => r.data),
    training:  (id, data)    => api.post(`/portal/purchase/workers/${id}/training`, data,
      data instanceof FormData ? { headers: { 'Content-Type': undefined } } : undefined).then(r => r.data),
    // Named to match portalApi so one shared page serves both portals.
    saveTraining: (id, data) => api.post(`/portal/purchase/workers/${id}/training`, data,
      data instanceof FormData ? { headers: { 'Content-Type': undefined } } : undefined).then(r => r.data),
    induction: (id, data)    => api.post(`/portal/purchase/workers/${id}/induction`, data).then(r => r.data),
    document:  (id, fd)      => api.post(`/portal/purchase/workers/${id}/documents`, fd).then(r => r.data),
    // Step 5 is READ ONLY here — activation is an admin decision.
    badge:     (id)          => api.get(`/portal/purchase/workers/${id}/badge`).then(r => r.data),
    activate:  ()            => Promise.reject(new Error('Admin only')),
  },

  /**
   * The site gate, this vendor's own people only — READ ONLY.
   *
   * Same method names as `purchaseApi.gate`, so PurchaseWorkforceAttendance is
   * one screen serving both surfaces. `scan`, `events` and `storeEvent` are
   * absent by design: recording a crossing is the security desk's act, and a
   * vendor able to write its own scans could manufacture attendance.
   *
   * The vendor is resolved from the token server-side, so no scope is sent and
   * none can be widened.
   */
  gate: {
    stats:      (date)        => api.get('/portal/purchase/gate/stats', { params: date ? { date } : {} }).then(r => r.data),
    log:        (params = {}) => api.get('/portal/purchase/gate-log', { params }).then(r => r.data?.data ?? r.data),
    onSite:     (date)        => api.get('/portal/purchase/gate/on-site', { params: date ? { date } : {} }).then(r => r.data?.data ?? r.data),
    attendance: (workerId, params = {}) => api.get(`/portal/purchase/workers/${workerId}/attendance`, { params }).then(r => r.data),
  },

  // ── PPE — served from central INVENTORY (single source of truth) ────────
  // Issue/return move inventory_stock through StockService; the vendor never
  // supplies a warehouse, so it cannot move stock between sites.
  ppe: {
    catalogue: ()          => api.get('/portal/purchase/ppe').then(r => r.data),
    summary:   ()          => api.get('/portal/purchase/ppe/summary').then(r => r.data),
    // Private file: fetched as a blob so the bearer token is sent.
    imageBlob: (productId) => api.get(`/portal/purchase/ppe/item/${productId}/image`, { responseType: 'blob' }).then(r => URL.createObjectURL(r.data)),
    forWorker:  (workerId)        => api.get(`/portal/purchase/workers/${workerId}/ppe`).then(r => r.data),
    compliance: (workerId)        => api.get(`/portal/purchase/workers/${workerId}/ppe/compliance`).then(r => r.data),
    issue:      (workerId, data)  => api.post(`/portal/purchase/workers/${workerId}/ppe/issue`, data).then(r => r.data),
    return:     (issueId, data)   => api.post(`/portal/purchase/ppe/issues/${issueId}/return`, data).then(r => r.data),
  },

  /**
   * The admin client's shape, over the portal's endpoints.
   *
   * This is why the Purchase portal had a workforce screen of its own. TPV's two
   * clients (`tpvApi` / `portalApi`) deliberately share one namespace and one set
   * of method names, so every workforce component is written once as
   * `api.workers.list(...)` and serves BOTH surfaces by swapping the client.
   * Purchase broke that convention: its admin client says
   * `workforce.workers()` / `workforce.saveMedical()`, its portal client says
   * `workers.list()` / `workers.medical()`. Same endpoints, same server, two
   * vocabularies — so `PurchaseWorkers` and `PurchaseWorkerWizard` could not be
   * pointed at the portal, and 541 lines were rewritten instead, with a different
   * layout, fewer steps and none of the admin screen's detail.
   *
   * Naming it the same is what makes one component serve both. Nothing is added
   * on the server and `workers` / `ppe` above are untouched, so the existing
   * portal screens keep working while the shared ones are moved across.
   *
   * The lifecycle decisions stay refused, as they are on the TPV portal: whether
   * a worker may walk on site is the site's call, not the vendor's.
   */
  workforce: {
    /*
     * These two also normalise the SHAPE, not just the name.
     *
     * The portal answers `{workers, summary}` where the admin answers a plain
     * array, and answers a flat worker with `readiness` merged in where the
     * admin wraps it as `{worker, readiness, badge}`. The shared screens read
     * the admin shape, so before this the portal list silently fell back to []
     * — a vendor registered a worker, the save returned 201, and the register
     * stayed empty, which reads as "registration is broken".
     *
     * Reconciling the shape is this adapter's job just as much as the naming.
     */
    workers: (params = {}) => api.get('/portal/purchase/workers', { params })
      .then(r => r.data?.workers ?? r.data?.data ?? r.data ?? []),

    worker: (id) => api.get(`/portal/purchase/workers/${id}`).then(r => {
      const d = r.data ?? {}
      if (d.worker) return d                      // already the admin shape
      const { readiness, ...worker } = d

      return {
        worker,
        readiness,
        badge: {
          badge_number: worker.badge_number,
          badge_issued_at: worker.badge_issued_at,
          badge_valid_until: worker.badge_valid_until,
          activated: !!worker.badge_number,
        },
      }
    }),
    stats:         ()            => api.get('/portal/purchase/workers/summary').then(r => r.data),
    // The vendor's own bulk import. No vendor id is sent — the server reads it
    // from the token, so an import cannot be aimed at somebody else's books.
    uploadWorkers: (file) => { const fd = new FormData(); fd.append('worker_file', file)
      return api.post('/portal/purchase/workers/upload', fd).then(r => r.data) },
    createWorker:  (data)        => api.post('/portal/purchase/workers', data).then(r => r.data),
    updateWorker:  (id, data)    => api.put(`/portal/purchase/workers/${id}`, data).then(r => r.data),
    deleteWorker:  (id)          => api.delete(`/portal/purchase/workers/${id}`).then(r => r.data),
    readiness:     (id)          => api.get(`/portal/purchase/workers/${id}/readiness`).then(r => r.data),
    saveMedical:   (id, data)    => api.post(`/portal/purchase/workers/${id}/medical`, data).then(r => r.data),
    saveTraining:  (id, data)    => api.post(`/portal/purchase/workers/${id}/training`, data).then(r => r.data),
    saveInduction: (id, data)    => api.post(`/portal/purchase/workers/${id}/induction`, data).then(r => r.data),
    document:      (id, fd)      => api.post(`/portal/purchase/workers/${id}/documents`, fd).then(r => r.data),
    badge:         (id)          => api.get(`/portal/purchase/workers/${id}/badge`).then(r => r.data),

    ppeCatalogue:  ()                 => api.get('/portal/purchase/ppe').then(r => r.data),
    ppe:           (workerId)         => api.get(`/portal/purchase/workers/${workerId}/ppe`).then(r => r.data),
    issuePpe:      (workerId, data)   => api.post(`/portal/purchase/workers/${workerId}/ppe/issue`, data).then(r => r.data),
    returnPpe:     (issueId, data)    => api.post(`/portal/purchase/ppe/issues/${issueId}/return`, data).then(r => r.data),

    // Admin decisions — refused here the way portalApi refuses them for TPV.
    activate:  () => Promise.reject(new Error('Activation is an admin decision.')),
    suspend:   () => Promise.reject(new Error('Suspension is an admin decision.')),
    terminate: () => Promise.reject(new Error('Termination is an admin decision.')),
    reinstate: () => Promise.reject(new Error('Reinstatement is an admin decision.')),
    // Tenant-wide registers and the security desk's scan record stay admin-side.
    medicals:  () => Promise.reject(new Error('Admin only')),
    trainings: () => Promise.reject(new Error('Admin only')),
    gate:      () => Promise.reject(new Error('Admin only')),
  },

  // Standalone kickoff summary (the portal Kickoff tab, resolved from the token).
  kickoff: {
    get:    () => api.get('/portal/purchase/kickoff').then(r => r.data),
    accept: () => api.post('/portal/purchase/kickoff/accept').then(r => r.data),
  },

  // The Inventory items this vendor is approved to supply. Read-only: the
  // mapping is the buyer's to make, the vendor's to see.
  items: () => api.get('/portal/purchase/items').then(r => r.data),

  // ── Commercial — own vendor only, read-only lists + detail (with items) ──
  // The vendor sees the documents raised against them: orders, quotations,
  // contracts, invoices, debit notes, payments, and a running statement.
  commercial: {
    orders:      ()   => api.get('/portal/purchase/orders').then(r => r.data),
    order:       (id) => api.get(`/portal/purchase/orders/${id}`).then(r => r.data),
    quotations:  ()   => api.get('/portal/purchase/quotations').then(r => r.data),
    quotation:   (id) => api.get(`/portal/purchase/quotations/${id}`).then(r => r.data),
    contracts:   ()   => api.get('/portal/purchase/contracts').then(r => r.data),
    contract:    (id) => api.get(`/portal/purchase/contracts/${id}`).then(r => r.data),
    invoices:    ()   => api.get('/portal/purchase/invoices').then(r => r.data),
    invoice:     (id) => api.get(`/portal/purchase/invoices/${id}`).then(r => r.data),
    debitNotes:  ()   => api.get('/portal/purchase/debit-notes').then(r => r.data),
    debitNote:   (id) => api.get(`/portal/purchase/debit-notes/${id}`).then(r => r.data),
    payments:    ()   => api.get('/portal/purchase/payments').then(r => r.data),
    statement:   ()   => api.get('/portal/purchase/statement').then(r => r.data),
    // RFQs the vendor was invited to, + submitting a quotation against one.
    rfqs:        ()          => api.get('/portal/purchase/rfqs').then(r => r.data),
    rfq:         (id)        => api.get(`/portal/purchase/rfqs/${id}`).then(r => r.data),
    submitQuote: (id, body)  => api.post(`/portal/purchase/rfqs/${id}/quotation`, body).then(r => r.data),
  },

  // ── Parity with the TPV portal (General/Execution/Performance/Compliance) ──
  customers: () => api.get('/portal/purchase/customers').then(r => r.data?.data ?? r.data),
  myWork: {
    projects:         () => api.get('/portal/purchase/projects').then(r => r.data?.data ?? r.data),
    tasks:            () => api.get('/portal/purchase/work-tasks').then(r => r.data?.data ?? r.data),
    taskStatuses:     () => api.get('/portal/purchase/task-statuses').then(r => r.data?.data ?? r.data),
    updateTaskStatus: (id, status) => api.patch(`/portal/purchase/tasks/${id}/status`, { status }).then(r => r.data?.data ?? r.data),
    tickets:          () => api.get('/portal/purchase/work-tickets').then(r => r.data?.data ?? r.data),
    // Raise and reply, matching portalApi.myWork so MyWork renders identically
    // against either portal. Purchase had the list alone, which is why its
    // Tickets screen was mounted with ticketWrite:false and drew no button.
    raiseTicket:      (body)   => api.post('/portal/purchase/work-tickets', body).then(r => r.data),
    ticket:           (id)     => api.get(`/portal/purchase/work-tickets/${id}`).then(r => r.data?.data ?? r.data),
    replyTicket:      (id, message) => api.post(`/portal/purchase/work-tickets/${id}/reply`, { message }).then(r => r.data),
    expenses:         () => api.get('/portal/purchase/expenses').then(r => r.data?.data ?? r.data),
    logExpense:       (body) => api.post('/portal/purchase/expenses', body).then(r => r.data),
  },
  performance: {
    risk:           () => api.get('/portal/purchase/risk').then(r => r.data),
    feedback:       () => api.get('/portal/purchase/feedback').then(r => r.data),
    violations:     () => api.get('/portal/purchase/violations').then(r => r.data),
    awards:         () => api.get('/portal/purchase/awards').then(r => r.data),
    referrals:      () => api.get('/portal/purchase/referrals').then(r => r.data),
    submitReferral: (body) => api.post('/portal/purchase/referrals', body).then(r => r.data),
  },
  hsse: {
    permits:        () => api.get('/portal/purchase/permits').then(r => r.data),
    requestPermit:  (body) => api.post('/portal/purchase/permits', body).then(r => r.data),
    incidents:      () => api.get('/portal/purchase/incidents').then(r => r.data),
    reportIncident: (body) => api.post('/portal/purchase/incidents', body).then(r => r.data),
  },
  logistics: {
    shipments:      () => api.get('/portal/purchase/shipments').then(r => r.data),
    createShipment: (body) => api.post('/portal/purchase/shipments', body).then(r => r.data),
    updateStatus:   (id, status) => api.patch(`/portal/purchase/shipments/${id}/status`, { status }).then(r => r.data),
    packages:       () => api.get('/portal/purchase/shipment-packages').then(r => r.data),
  },

  // ── Knowledge Base (tenant-published, read-only) — parity with TPV portal ──
  kb: {
    list:    ()     => api.get('/portal/purchase/kb').then(r => r.data?.data ?? r.data),
    article: (slug) => api.get(`/portal/purchase/kb/${slug}`).then(r => r.data?.data ?? r.data),
  },

  // §32 "View compliance" — the vendor's own compliance register (read-only).
  compliance: {
    get: () => api.get('/portal/purchase/compliance').then(r => r.data),
  },

  // §32 Governance-response half (no PPE matrix — Purchase has none).
  governance: {
    ncrs:            ()            => api.get('/portal/purchase/ncrs').then(r => r.data),
    // The site's PPE rule, read-only. Parity with portalApi.governance.ppeMatrix.
    ppeMatrix: () => api.get('/portal/purchase/ppe-matrix').then(r => r.data),
    respondNcr:      (id, payload) => api.post(`/portal/purchase/ncrs/${id}/respond`, payload).then(r => r.data),
    capas:           ()            => api.get('/portal/purchase/capas').then(r => r.data),
    submitCapa:      (id, payload) => api.post(`/portal/purchase/capas/${id}/evidence`, payload).then(r => r.data),
    requestApproval: (payload)     => api.post('/portal/purchase/approvals/request', payload).then(r => r.data),
    requestExtension:(payload)     => api.post('/portal/purchase/extensions/request', payload).then(r => r.data),
    meetings:        ()            => api.get('/portal/purchase/meetings').then(r => r.data),
    meetingMom:      (id)          => api.get(`/portal/purchase/meetings/${id}/mom`).then(r => r.data),
    // Records that this person opened the meeting, then hands back the link.
    // A meeting held on Google Meet or Teams runs where we cannot see it, so
    // the click is the only evidence there is — and it is worth keeping.
    // Marking attendance is what releases the joining link — it is not in the
    // meetings payload until this returns. See MeetingAttendanceGate.
    markAttendance:  (id)          => api.post(`/portal/purchase/meetings/${id}/attendance`).then(r => r.data),
    // The minutes document itself. Distributing minutes the recipient cannot
    // open is not distributing them — this had no route at all until now.
    meetingMomFile:  (id)          => api.get(`/portal/purchase/meetings/${id}/mom/file`, { responseType: 'blob' }).then(r => r.data),
    meetingDocument: (id, docId)   => api.get(`/portal/purchase/meetings/${id}/documents/${docId}/download`, { responseType: 'blob' }).then(r => r.data),
    actions:         ()            => api.get('/portal/purchase/actions').then(r => r.data),
    respondAction:   (id, payload) => api.post(`/portal/purchase/actions/${id}/respond`, payload).then(r => r.data),
    uploadCertificate: (workerId, fd) => upload(`/portal/purchase/workers/${workerId}/certificates`, fd),
  },
}

export default purchasePortalApi
