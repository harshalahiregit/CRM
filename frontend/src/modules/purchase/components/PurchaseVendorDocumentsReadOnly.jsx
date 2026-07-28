import { useState, useEffect, useCallback } from 'react'
import { FileText, Eye, Download, CheckCircle2, XCircle, Inbox } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'

/**
 * Admin's read-only view of a Purchase Vendor's onboarding documents.
 *
 * Documents are supplied by the vendor through the Purchase Portal during
 * onboarding — this screen never uploads. It reads the SAME checklist endpoint
 * (/purchase/vendors/{id}/documents) the portal writes to: no second table, no
 * second upload path, no copied files.
 *
 * The only writes here are review decisions (approve / reject), which are the
 * admin's own responsibility in the workflow.
 *
 * Purchase-owned; TPV has its own document surface.
 */
const STATUS = {
  Approved:  { label: 'Approved',       color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Pending:   { label: 'Pending Review', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Submitted: { label: 'Pending Review', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Rejected:  { label: 'Rejected',       color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  Expired:   { label: 'Expired',        color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
const cfgFor = (row) => STATUS[row.status] || { label: row.status_label || 'Not Uploaded', color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' }

const fmt = (d) => (d ? new Date(d).toLocaleString() : null)

export default function PurchaseVendorDocumentsReadOnly({ vendorId, canReview = true, onChanged }) {
  const [rows, setRows] = useState(null)
  const [busy, setBusy] = useState(null)
  const [err, setErr] = useState(null)

  const load = useCallback(() => {
    purchaseApi.documents.checklist(vendorId)
      .then((r) => setRows(Array.isArray(r) ? r : (r?.checklist ?? r?.data ?? [])))
      .catch(() => setRows([]))
  }, [vendorId])

  useEffect(() => { load() }, [load])

  const decide = async (row, decision) => {
    const remarks = decision === 'reject'
      ? window.prompt('Reason for rejection (the vendor will see this):')
      : ''
    if (decision === 'reject' && !remarks) return
    setBusy(row.document_id); setErr(null)
    try {
      await purchaseApi.documents.review(row.document_id, decision, remarks || '')
      load(); onChanged?.()
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not record the decision.')
    } finally { setBusy(null) }
  }

  const open = async (row) => {
    try { window.open(await purchaseApi.documents.open(row.document_id), '_blank', 'noopener') }
    catch { setErr('Could not open the file.') }
  }

  if (rows === null) return <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading documents…</p>
  if (!rows.length) {
    return (
      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, padding: '28px 0', color: 'var(--text-muted)' }}>
        <Inbox size={24} style={{ opacity: 0.6 }} />
        <span style={{ fontSize: 13 }}>No Documents</span>
      </div>
    )
  }

  return (
    <>
      <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 12px' }}>
        Uploaded by the vendor through the portal during onboarding. Admins review — they do not upload.
      </p>
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '0 0 10px' }}>{err}</p>}

      <div style={{ display: 'grid', gap: 8 }}>
        {rows.map((row) => {
          const cfg = cfgFor(row)
          return (
            <div key={row.type} style={{ border: '1px solid var(--border)', borderRadius: 10, padding: '12px 14px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <FileText size={15} style={{ color: 'var(--text-muted)' }} />
                <strong style={{ fontSize: 13, color: 'var(--text-h)' }}>{row.type_label || row.type}</strong>
                <span style={{ padding: '2px 9px', borderRadius: 999, fontSize: 10.5, fontWeight: 800, color: cfg.color, background: cfg.bg }}>{cfg.label}</span>

                {row.uploaded && (
                  <div style={{ marginLeft: 'auto', display: 'inline-flex', gap: 6 }}>
                    <button onClick={() => open(row)} style={miniBtn} title="View file"><Eye size={13} /> View</button>
                    <button onClick={() => open(row)} style={miniBtn} title="Download file"><Download size={13} /></button>
                    {canReview && row.status !== 'Approved' && (
                      <button onClick={() => decide(row, 'approve')} disabled={busy === row.document_id} style={{ ...miniBtn, color: '#10b981' }}><CheckCircle2 size={13} /> Approve</button>
                    )}
                    {canReview && row.status !== 'Rejected' && (
                      <button onClick={() => decide(row, 'reject')} disabled={busy === row.document_id} style={{ ...miniBtn, color: '#ef4444' }}><XCircle size={13} /> Reject</button>
                    )}
                  </div>
                )}
              </div>

              {/* Provenance — who supplied it, when, and who reviewed it */}
              <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', marginTop: 8, fontSize: 11.5, color: 'var(--text-muted)' }}>
                {row.uploaded ? (
                  <>
                    <span>Uploaded by <strong style={{ color: 'var(--text-h)' }}>Vendor</strong></span>
                    {row.uploaded_at && <span>Uploaded {fmt(row.uploaded_at)}</span>}
                    {row.original_name && <span>{row.original_name}</span>}
                    {row.reviewed_by && <span>Reviewed by <strong style={{ color: 'var(--text-h)' }}>{row.reviewed_by}</strong></span>}
                    {row.reviewed_at && <span>{row.status === 'Approved' ? 'Approved' : 'Reviewed'} {fmt(row.reviewed_at)}</span>}
                    {row.expires_at && <span>Expires {new Date(row.expires_at).toLocaleDateString()}</span>}
                  </>
                ) : (
                  <span>Awaiting upload from the vendor.</span>
                )}
              </div>

              {row.remarks && (
                <div style={{ marginTop: 8, fontSize: 12, color: row.status === 'Rejected' ? '#ef4444' : 'var(--text-muted)' }}>
                  {row.status === 'Rejected' ? <>Reason: {row.remarks}</> : row.remarks}
                </div>
              )}
            </div>
          )
        })}
      </div>
    </>
  )
}

const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '4px 9px', borderRadius: 7, background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700 }
