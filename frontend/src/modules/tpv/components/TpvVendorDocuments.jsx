import { useState, useEffect, useCallback, useMemo } from 'react'
import {
  FileText, CheckCircle, XCircle, Clock, AlertTriangle, Eye, Download, History,
  Search, Filter, ShieldCheck, Check, RefreshCw, MessageSquare, ArrowRight, RotateCcw, AlertCircle
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { Overlay, ModalFooter, StatusBadge as StatusPill } from '@/components/ui/kit3d'
import { docStatusCfg, DOC_STATUS } from '@/modules/tpv/constants'

const DOC_CATEGORIES = {
  company_registration: 'Company Documents',
  company_pan: 'Company Documents',
  gst: 'Company Documents',
  udyam_certificate: 'Company Documents',
  shop_act: 'Company Documents',

  insurance_wcp: 'Compliance Documents',
  pf_no: 'Compliance Documents',
  esic_no: 'Compliance Documents',
  bocw_registration: 'Compliance Documents',
  clr: 'Compliance Documents',
  mlwf: 'Compliance Documents',
  mscb: 'Compliance Documents',
  labour_license: 'Compliance Documents',

  loi_wo_po: 'Financial Documents',
  bank_proof: 'Financial Documents',
  cancelled_cheque: 'Financial Documents',

  subcontractor_decl: 'Other Documents',
  other: 'Other Documents',
}

const STANDARD_REQUIRED_DOCS = [
  { type: 'company_registration', label: 'Company Registration Certificate', step: 'Step 3 Statutory' },
  { type: 'company_pan', label: 'Company PAN Card', step: 'Step 3 Statutory' },
  { type: 'insurance_wcp', label: 'Insurance [WCP]', step: 'Step 3 Statutory' },
  { type: 'gst', label: 'GST Certificate', step: 'Step 3 Statutory' },
  { type: 'pf_no', label: 'PF Registration', step: 'Step 3 Statutory' },
  { type: 'esic_no', label: 'ESIC Registration', step: 'Step 3 Statutory' },
  { type: 'bocw_registration', label: 'BOCW Registration', step: 'Step 3 Statutory' },
  { type: 'clr', label: 'CLR [Contract Labour Registration]', step: 'Step 3 Statutory' },
  { type: 'mlwf', label: 'MLWF [Maharashtra Labour Welfare]', step: 'Step 3 Statutory' },
  { type: 'mscb', label: 'MSCB Certificate', step: 'Step 3 Statutory' },
  { type: 'udyam_certificate', label: 'Udyam Certificate', step: 'Step 3 Statutory' },
  { type: 'other', label: 'Other Document (Optional)', step: 'Step 3 Statutory' },
  { type: 'subcontractor_decl', label: 'Subcontractor Declaration (Optional)', step: 'Step 3 Statutory' },
]

function getCategory(type) {
  return DOC_CATEGORIES[type] || 'Company Documents'
}

export default function TpvVendorDocuments({ vendorId, vendor, manage }) {
  const [checklist, setChecklist] = useState(null)
  const [loading, setLoading]     = useState(true)
  const [search, setSearch]       = useState('')
  const [statusFilter, setStatusFilter] = useState('ALL')
  const [categoryFilter, setCategoryFilter] = useState('ALL')
  const [stepFilter, setStepFilter]     = useState('ALL')

  // Modals & Drawers State
  const [reviewing, setReviewing] = useState(null) // { row, decision: 'approve' | 'reject' }
  const [previewDoc, setPreviewDoc] = useState(null) // { url, name, type }
  const [historyDoc, setHistoryDoc] = useState(null) // documentId
  const [busyDoc, setBusyDoc]     = useState(null)
  const [actionSuccess, setActionSuccess] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    tpvApi.documents.checklist(vendorId)
      .then(res => {
        setChecklist(res?.data ?? res)
        setLoading(false)
      })
      .catch(() => setLoading(false))
  }, [vendorId])

  useEffect(() => { load() }, [load])

  const backendRows = checklist?.required || []

  // Combine backend matrix with standard required list
  const rawRowsMap = new Map(backendRows.map(r => [r.type, r]))
  const allDocRows = STANDARD_REQUIRED_DOCS.map(def => {
    const existing = rawRowsMap.get(def.type)
    const isRequired = !!existing
    return {
      type: def.type,
      type_label: def.label,
      step: def.step,
      category: getCategory(def.type),
      required: isRequired,
      uploaded: existing ? existing.uploaded : false,
      status: existing ? (existing.status || (existing.uploaded ? 'under_review' : 'missing')) : 'missing',
      original_name: existing?.original_name || null,
      document_id: existing?.document_id || null,
      remarks: existing?.remarks || null,
      reviewed_at: existing?.reviewed_at || null,
      expires_at: existing?.expires_at || null,
      version: existing?.version || (existing?.uploaded ? 'v1' : '—'),
      uploaded_by: existing?.uploaded_by || vendor?.company_name || 'Vendor Representative',
      uploaded_at: existing?.reviewed_at || vendor?.updated_at || null,
    }
  })

  // Append any extra uploaded docs outside standard list
  backendRows.forEach(r => {
    if (!STANDARD_REQUIRED_DOCS.some(d => d.type === r.type)) {
      allDocRows.push({
        type: r.type,
        type_label: r.type_label || r.type,
        step: 'Step 3 Statutory',
        category: getCategory(r.type),
        required: true,
        uploaded: true,
        status: r.status || 'under_review',
        original_name: r.original_name,
        document_id: r.document_id,
        remarks: r.remarks,
        reviewed_at: r.reviewed_at,
        expires_at: r.expires_at,
        version: r.version || 'v1',
        uploaded_by: vendor?.company_name || 'Vendor Representative',
        uploaded_at: r.reviewed_at || null,
      })
    }
  })

  // Metrics Counters
  const metrics = useMemo(() => {
    const req = allDocRows.filter(r => r.required)
    const pending  = req.filter(r => r.uploaded && r.status !== DOC_STATUS.APPROVED && r.status !== DOC_STATUS.REJECTED).length
    const approved = req.filter(r => r.status === DOC_STATUS.APPROVED).length
    const rejected = req.filter(r => r.status === DOC_STATUS.REJECTED).length
    const missing  = req.filter(r => !r.uploaded && r.status !== DOC_STATUS.APPROVED).length
    return { pending, approved, rejected, missing, total: req.length }
  }, [allDocRows])

  // Filtering Logic
  const filteredRows = useMemo(() => {
    return allDocRows.filter(r => {
      const matchSearch = r.type_label.toLowerCase().includes(search.toLowerCase()) ||
        (r.original_name && r.original_name.toLowerCase().includes(search.toLowerCase())) ||
        r.category.toLowerCase().includes(search.toLowerCase())

      let matchStatus = true
      if (statusFilter === 'PENDING') matchStatus = r.uploaded && r.status !== DOC_STATUS.APPROVED && r.status !== DOC_STATUS.REJECTED
      else if (statusFilter === 'APPROVED') matchStatus = r.status === DOC_STATUS.APPROVED
      else if (statusFilter === 'REJECTED') matchStatus = r.status === DOC_STATUS.REJECTED
      else if (statusFilter === 'MISSING') matchStatus = !r.uploaded && r.status !== DOC_STATUS.APPROVED

      let matchCat = true
      if (categoryFilter !== 'ALL') matchCat = r.category === categoryFilter

      let matchStep = true
      if (stepFilter !== 'ALL') matchStep = r.step === stepFilter

      return matchSearch && matchStatus && matchCat && matchStep
    })
  }, [allDocRows, search, statusFilter, categoryFilter, stepFilter])

  // Document Review Handler (Approve / Reject)
  const handleReviewConfirm = async (remarks) => {
    if (!reviewing) return
    const { row, decision } = reviewing
    setBusyDoc(row.document_id)
    try {
      await tpvApi.documents.review(row.document_id, decision, remarks)
      setReviewing(null)
      setActionSuccess(`Document ${row.type_label} ${decision === 'approve' ? 'Approved' : 'Rejected'} successfully. Notifications (Email, WhatsApp & In-App) dispatched.`)
      setTimeout(() => setActionSuccess(null), 5000)
      load()
    } catch (e) {
      alert(e?.response?.data?.message || 'Failed to update document status')
    } finally {
      setBusyDoc(null)
    }
  }

  // Document Preview Handler
  const handleViewDoc = async (row) => {
    if (!row.document_id) return
    try {
      const url = await tpvApi.documents.open(row.document_id)
      const ext = (row.original_name || '').split('.').pop().toLowerCase()
      if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) {
        setPreviewDoc({ url, name: row.type_label, type: 'image' })
      } else if (ext === 'pdf') {
        setPreviewDoc({ url, name: row.type_label, type: 'pdf' })
      } else {
        window.open(url, '_blank')
      }
    } catch {
      alert('Could not open document file preview.')
    }
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      {actionSuccess && (
        <div style={{ padding: '12px 16px', borderRadius: 10, background: '#f0fdf4', border: '1px solid #bbf7d0', color: '#15803d', fontSize: 13, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 8 }}>
          <CheckCircle size={16} /> {actionSuccess}
        </div>
      )}

      {/* Top Metrics Cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 14 }}>
        <MetricCard label="Pending Documents" value={metrics.pending} color="#f59e0b" icon={Clock} bg="#fffbeb" border="#fde68a" />
        <MetricCard label="Approved Documents" value={metrics.approved} color="#10b981" icon={CheckCircle} bg="#f0fdf4" border="#bbf7d0" />
        <MetricCard label="Rejected Documents" value={metrics.rejected} color="#ef4444" icon={XCircle} bg="#fef2f2" border="#fca5a5" />
        <MetricCard label="Missing / Optional" value={metrics.missing} color="#6b7280" icon={AlertCircle} bg="#f8fafc" border="#e2e8f0" />
      </div>

      {/* Main Container Card */}
      <div style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16, padding: 20, boxShadow: '0 2px 12px rgba(0,0,0,0.03)' }}>
        {/* Header & Filter Controls */}
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 18, flexWrap: 'wrap', gap: 14 }}>
          <div>
            <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
              <ShieldCheck size={18} style={{ color: '#7C3AED' }} /> Vendor Statutory &amp; Compliance Documents
            </h3>
            <p style={{ margin: '3px 0 0', fontSize: 12, color: 'var(--text-muted)' }}>
              Approve or reject TPV vendor onboarding compliance documents. Updates sync real-time.
            </p>
          </div>

          <button onClick={load} disabled={loading} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12, fontWeight: 700, cursor: 'pointer' }}>
            <RefreshCw size={13} className={loading ? 'rfq-spin' : ''} /> {loading ? 'Syncing...' : 'Refresh'}
          </button>
        </div>

        {/* Search & Filter Bar */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 18, flexWrap: 'wrap' }}>
          <div style={{ position: 'relative', flex: 1, minWidth: 240 }}>
            <Search size={14} style={{ position: 'absolute', left: 12, top: 10, color: 'var(--text-muted)' }} />
            <input
              type="text"
              placeholder="Search by Document Name, Category..."
              value={search}
              onChange={e => setSearch(e.target.value)}
              style={{ width: '100%', padding: '7px 12px 7px 34px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5 }}
            />
          </div>

          <select
            value={statusFilter}
            onChange={e => setStatusFilter(e.target.value)}
            style={{ padding: '7px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12, fontWeight: 600 }}
          >
            <option value="ALL">Status: All Statuses</option>
            <option value="PENDING">Status: Pending Review</option>
            <option value="APPROVED">Status: Approved</option>
            <option value="REJECTED">Status: Rejected</option>
            <option value="MISSING">Status: Missing</option>
          </select>

          <select
            value={categoryFilter}
            onChange={e => setCategoryFilter(e.target.value)}
            style={{ padding: '7px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12, fontWeight: 600 }}
          >
            <option value="ALL">Category: All Categories</option>
            <option value="Company Documents">Company Documents</option>
            <option value="Compliance Documents">Compliance Documents</option>
            <option value="Financial Documents">Financial Documents</option>
            <option value="Other Documents">Other Documents</option>
          </select>
        </div>

        {/* Professional Documents Table */}
        <div style={{ overflowX: 'auto', border: '1px solid var(--border)', borderRadius: 12 }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5, textAlign: 'left' }}>
            <thead>
              <tr style={{ background: 'var(--bg-input)', borderBottom: '1px solid var(--border)', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                <th style={thStyle}>Document Name</th>
                <th style={thStyle}>Category</th>
                <th style={thStyle}>Step</th>
                <th style={thStyle}>Uploaded By</th>
                <th style={thStyle}>Uploaded On</th>
                <th style={thStyle}>Status</th>
                <th style={thStyle}>Version</th>
                <th style={{ ...thStyle, textAlign: 'right' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredRows.length === 0 ? (
                <tr>
                  <td colSpan={8} style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)' }}>
                    No matching compliance documents found.
                  </td>
                </tr>
              ) : (
                filteredRows.map(row => {
                  const isApproved = row.status === DOC_STATUS.APPROVED
                  const isRejected = row.status === DOC_STATUS.REJECTED
                  const isPending  = row.uploaded && !isApproved && !isRejected
                  const cfg        = docStatusCfg(row.status)

                  return (
                    <tr key={row.type} style={{ borderBottom: '1px solid var(--border)', background: isRejected ? '#fff5f5' : isApproved ? '#f8fafc' : 'transparent' }}>
                      <td style={tdStyle}>
                        <div style={{ fontWeight: 800, color: 'var(--text-h)' }}>
                          {row.type_label} {row.required && <span style={{ color: '#ef4444' }}>*</span>}
                        </div>
                        {row.original_name ? (
                          <div style={{ fontSize: 11.5, color: 'var(--text-muted)', display: 'flex', alignItems: 'center', gap: 4, marginTop: 2 }}>
                            <FileText size={12} style={{ color: '#7C3AED' }} /> {row.original_name}
                          </div>
                        ) : (
                          <div style={{ fontSize: 11, color: '#94a3b8', fontStyle: 'italic', marginTop: 2 }}>No file uploaded yet</div>
                        )}
                      </td>

                      <td style={tdStyle}>
                        <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 6, background: 'rgba(124,58,237,0.08)', color: '#7C3AED', fontWeight: 700 }}>
                          {row.category}
                        </span>
                      </td>

                      <td style={tdStyle}>
                        <span style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 600 }}>{row.step}</span>
                      </td>

                      <td style={tdStyle}>
                        <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-h)' }}>{row.uploaded_by}</div>
                      </td>

                      <td style={tdStyle}>
                        <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                          {row.uploaded_at ? new Date(row.uploaded_at).toLocaleString() : '—'}
                        </div>
                      </td>

                      <td style={tdStyle}>
                        <StatusPill cfg={cfg} />
                        {isRejected && row.remarks && (
                          <div style={{ fontSize: 11, color: '#dc2626', marginTop: 3, maxWidth: 160, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} title={row.remarks}>
                            Rationale: {row.remarks}
                          </div>
                        )}
                      </td>

                      <td style={tdStyle}>
                        <span style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--text-muted)' }}>{row.version}</span>
                      </td>

                      <td style={{ ...tdStyle, textAlign: 'right' }}>
                        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                          {/* Approve Action */}
                          {row.uploaded && !isApproved && (
                            <button
                              onClick={() => setReviewing({ row, decision: 'approve' })}
                              disabled={busyDoc === row.document_id}
                              style={{ padding: '5px 10px', borderRadius: 6, background: '#10b981', color: '#fff', border: 'none', fontSize: 11.5, fontWeight: 700, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 4 }}
                            >
                              <CheckCircle size={13} /> Approve
                            </button>
                          )}

                          {/* Reject Action */}
                          {row.uploaded && !isRejected && (
                            <button
                              onClick={() => setReviewing({ row, decision: 'reject' })}
                              disabled={busyDoc === row.document_id}
                              style={{ padding: '5px 10px', borderRadius: 6, background: '#ef4444', color: '#fff', border: 'none', fontSize: 11.5, fontWeight: 700, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 4 }}
                            >
                              <XCircle size={13} /> Reject
                            </button>
                          )}

                          {/* View Preview Button */}
                          {row.uploaded && (
                            <button
                              onClick={() => handleViewDoc(row)}
                              style={{ padding: '5px 8px', borderRadius: 6, background: 'var(--bg-input)', color: 'var(--text-h)', border: '1px solid var(--border)', fontSize: 11.5, fontWeight: 600, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 4 }}
                            >
                              <Eye size={13} /> View
                            </button>
                          )}

                          {/* History Drawer Trigger */}
                          {row.uploaded && row.document_id && (
                            <button
                              onClick={() => setHistoryDoc(row.document_id)}
                              style={{ padding: '5px 8px', borderRadius: 6, background: 'var(--bg-input)', color: 'var(--text-h)', border: '1px solid var(--border)', fontSize: 11.5, fontWeight: 600, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 4 }}
                            >
                              <History size={13} /> History
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Review Modal (Approve / Reject) */}
      {reviewing && (
        <ReviewModal
          reviewing={reviewing}
          onClose={() => setReviewing(null)}
          onConfirm={handleReviewConfirm}
        />
      )}

      {/* Document History Drawer */}
      {historyDoc && (
        <VersionHistoryDrawer
          documentId={historyDoc}
          onClose={() => setHistoryDoc(null)}
          onRestored={() => { setHistoryDoc(null); load() }}
        />
      )}

      {/* Document File Preview Overlay */}
      {previewDoc && (
        <Overlay onClose={() => setPreviewDoc(null)} width={previewDoc.type === 'image' ? 680 : 850}>
          <div style={{ padding: '16px 20px', borderBottom: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <h3 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{previewDoc.name}</h3>
            <button onClick={() => setPreviewDoc(null)} style={{ border: 'none', background: 'none', cursor: 'pointer', fontSize: 18 }}>✕</button>
          </div>
          <div style={{ padding: 20, textAlign: 'center', maxHeight: 600, overflowY: 'auto' }}>
            {previewDoc.type === 'image' ? (
              <img src={previewDoc.url} alt={previewDoc.name} style={{ maxWidth: '100%', maxHeight: 520, borderRadius: 8, objectFit: 'contain' }} />
            ) : (
              <iframe src={previewDoc.url} style={{ width: '100%', height: 500, border: 'none', borderRadius: 8 }} title={previewDoc.name} />
            )}
          </div>
          <ModalFooter>
            <a href={previewDoc.url} target="_blank" download style={{ padding: '8px 16px', borderRadius: 8, background: '#7C3AED', color: '#fff', textDecoration: 'none', fontSize: 12, fontWeight: 700 }}>Download File</a>
          </ModalFooter>
        </Overlay>
      )}
    </div>
  )
}

function MetricCard({ label, value, color, icon: Icon, bg, border }) {
  return (
    <div style={{ padding: '14px 16px', borderRadius: 12, background: bg, border: `1px solid ${border}`, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
      <div>
        <div style={{ fontSize: 11.5, fontWeight: 700, color: '#475569' }}>{label}</div>
        <div style={{ fontSize: 22, fontWeight: 900, color, marginTop: 2 }}>{value}</div>
      </div>
      <Icon size={22} style={{ color }} />
    </div>
  )
}

function ReviewModal({ reviewing, onClose, onConfirm }) {
  const { row, decision } = reviewing
  const isApprove = decision === 'approve'
  const [remarks, setRemarks] = useState('')
  const [err, setErr] = useState(null)

  const handleSubmit = (e) => {
    e.preventDefault()
    if (!isApprove && !remarks.trim()) {
      setErr('A mandatory rejection reason is required.')
      return
    }
    onConfirm(remarks)
  }

  return (
    <Overlay onClose={onClose} width={480}>
      <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
          {isApprove ? <CheckCircle size={18} style={{ color: '#10b981' }} /> : <XCircle size={18} style={{ color: '#ef4444' }} />}
          {isApprove ? 'Approve Compliance Document' : 'Reject Compliance Document'}
        </h3>
        <button onClick={onClose} style={{ border: 'none', background: 'none', cursor: 'pointer', fontSize: 18 }}>✕</button>
      </div>

      <form onSubmit={handleSubmit} style={{ padding: 22 }}>
        <p style={{ marginTop: 0, fontSize: 13, color: 'var(--text-h)' }}>
          Document: <strong>{row.type_label}</strong>
        </p>
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)', marginBottom: 16 }}>
          {isApprove
            ? 'Approving this document will mark it as verified, send email & WhatsApp notifications to the vendor, and update onboarding progress.'
            : 'Rejecting this document requires a mandatory rationale. The vendor will receive an email & WhatsApp alert with instructions to re-upload.'}
        </p>

        <div style={{ marginBottom: 14 }}>
          <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-h)', marginBottom: 6 }}>
            {isApprove ? 'Approval Remarks (Optional)' : 'Rejection Reason *'}
          </label>
          <textarea
            value={remarks}
            onChange={e => { setRemarks(e.target.value); setErr(null) }}
            rows={3}
            placeholder={isApprove ? 'e.g. Verified against statutory records...' : 'Specify why this document is rejected...'}
            style={{ width: '100%', padding: 10, borderRadius: 8, border: `1px solid ${err ? '#ef4444' : 'var(--border)'}`, background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, outline: 'none', resize: 'vertical' }}
          />
          {err && <div style={{ color: '#ef4444', fontSize: 11.5, marginTop: 4 }}>{err}</div>}
        </div>

        <ModalFooter>
          <button type="button" onClick={onClose} style={{ padding: '8px 16px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)', fontSize: 12, fontWeight: 700, cursor: 'pointer' }}>
            Cancel
          </button>
          <button
            type="submit"
            style={{
              padding: '8px 20px', borderRadius: 8, border: 'none',
              background: isApprove ? 'linear-gradient(135deg, #10b981, #059669)' : 'linear-gradient(135deg, #ef4444, #dc2626)',
              color: '#fff', fontSize: 12.5, fontWeight: 800, cursor: 'pointer'
            }}
          >
            {isApprove ? 'Confirm Approval' : 'Confirm Rejection'}
          </button>
        </ModalFooter>
      </form>
    </Overlay>
  )
}

function VersionHistoryDrawer({ documentId, onClose, onRestored }) {
  const [versions, setVersions] = useState(null)
  const [busy, setBusy] = useState(null)

  useEffect(() => {
    tpvApi.documents.versions(documentId)
      .then(d => setVersions(d?.data ?? d ?? []))
      .catch(() => setVersions([]))
  }, [documentId])

  const handleDownload = async (v) => {
    setBusy(`d${v.id}`)
    try {
      const blob = await tpvApi.documents.downloadVersion(documentId, v.id)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch {
      alert('Could not download this version.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <Overlay onClose={onClose} width={520}>
      <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
          <History size={18} style={{ color: '#7C3AED' }} /> Document Version &amp; Audit History
        </h3>
        <button onClick={onClose} style={{ border: 'none', background: 'none', cursor: 'pointer', fontSize: 18 }}>✕</button>
      </div>

      <div style={{ padding: 22, maxHeight: 520, overflowY: 'auto' }}>
        {versions === null ? (
          <div style={{ color: 'var(--text-muted)', fontSize: 12.5 }}>Loading version history...</div>
        ) : versions.length === 0 ? (
          <div style={{ color: 'var(--text-muted)', fontSize: 12.5 }}>No previous version history recorded.</div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
            {versions.map(v => (
              <div key={v.id} style={{ padding: 14, borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
                  <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Version {v.version_no}</span>
                  <span style={{ fontSize: 11, fontWeight: 700, padding: '2px 8px', borderRadius: 6, background: 'rgba(124,58,237,0.1)', color: '#7C3AED' }}>
                    {v.status || 'Archived'}
                  </span>
                </div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 6 }}>
                  File: <strong>{v.original_name}</strong>
                </div>
                <div style={{ fontSize: 11.5, color: 'var(--text-muted)', display: 'flex', justifyContent: 'space-between' }}>
                  <span>Uploaded: {new Date(v.created_at).toLocaleString()}</span>
                  <button onClick={() => handleDownload(v)} disabled={busy === `d${v.id}`} style={{ border: 'none', background: 'none', color: '#7C3AED', fontWeight: 700, cursor: 'pointer', textDecoration: 'underline', padding: 0 }}>
                    Download
                  </button>
                </div>
                {v.remarks && (
                  <div style={{ marginTop: 6, padding: 8, borderRadius: 6, background: '#fef2f2', border: '1px solid #fca5a5', fontSize: 11.5, color: '#991b1b' }}>
                    <strong>Remarks:</strong> {v.remarks}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </Overlay>
  )
}

const thStyle = { padding: '12px 14px', fontWeight: 700 }
const tdStyle = { padding: '12px 14px', verticalAlign: 'middle' }
