import { useState, useEffect, useCallback, useRef } from 'react'
import { FileText, Upload, Eye, RefreshCw, CheckCircle2, XCircle, Clock } from 'lucide-react'
import { StatusBadge as StatusPill } from '@/components/ui/kit3d'

const DOC_STATUS = {
  Approved: { label: 'Approved', color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Rejected: { label: 'Rejected', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  Pending:  { label: 'Pending',  color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
}
const docCfg = (s) => DOC_STATUS[s] || { label: 'Not Uploaded', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }

/**
 * Purchase-vendor statutory documents — checklist + upload/view/resubmit and
 * (admin) approve/reject. Reuses the shared backend engine. The `api` prop is a
 * pre-bound object ({ checklist, upload, resubmit, review, open }) so the same
 * component serves the admin surface (/purchase/*) and the portal (/portal/purchase/*).
 */
export default function PurchaseVendorDocuments({ api, manage, admin, onChanged }) {
  const [data, setData]     = useState(null)
  const [loading, setLoad]  = useState(true)
  const [busy, setBusy]     = useState(null)   // "<type>:upload" | "<docId>:review"
  const fileRefs = useRef({})

  const load = useCallback(async () => {
    setLoad(true)
    try { setData(await api.checklist()) }
    catch (e) { console.error('Failed to load documents', e) }
    finally { setLoad(false) }
  }, [api])
  useEffect(() => { load() }, [load])

  const refresh = () => { load(); onChanged?.() }

  const doUpload = async (type, file) => {
    if (!file) return
    setBusy(`${type}:upload`)
    try { await api.upload(null, type, file); refresh() }
    catch (e) { alert(e?.response?.data?.message || 'Upload failed') }
    finally { setBusy(null) }
  }

  const doResubmit = async (docId, file) => {
    if (!file) return
    setBusy(`${docId}:upload`)
    try { await api.resubmit(docId, file); refresh() }
    catch (e) { alert(e?.response?.data?.message || 'Resubmit failed') }
    finally { setBusy(null) }
  }

  const doReview = async (docId, decision) => {
    let remarks = ''
    if (decision === 'reject') { remarks = prompt('Rejection remarks:') || ''; if (!remarks) return }
    setBusy(`${docId}:review`)
    try { await api.review(docId, decision, remarks); refresh() }
    catch (e) { alert(e?.response?.data?.message || 'Review failed') }
    finally { setBusy(null) }
  }

  const openDoc = async (docId) => {
    try { const url = await api.open(docId); window.open(url, '_blank', 'noopener') }
    catch { alert('Could not open the document.') }
  }

  if (loading) return <div style={{ padding: 40, textAlign: 'center', color: 'var(--text-muted)' }}>Loading documents…</div>
  if (!data) return <div style={{ padding: 40, textAlign: 'center', color: 'var(--text-muted)' }}>Could not load the document checklist.</div>

  const s = data.summary || {}
  const rows = data.required || []

  return (
    <div>
      {/* Summary bar */}
      <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', padding: '12px 16px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 14, fontSize: 12.5 }}>
        <span style={{ color: 'var(--text-muted)' }}>Required <strong style={{ color: 'var(--text-h)' }}>{s.required || 0}</strong></span>
        <span style={{ color: 'var(--text-muted)' }}>Uploaded <strong style={{ color: '#0ea5e9' }}>{s.uploaded || 0}</strong></span>
        <span style={{ color: 'var(--text-muted)' }}>Approved <strong style={{ color: '#10b981' }}>{s.approved || 0}</strong></span>
        <span style={{ color: 'var(--text-muted)' }}>Pending <strong style={{ color: '#f59e0b' }}>{s.pending || 0}</strong></span>
        <span style={{ color: 'var(--text-muted)' }}>Rejected <strong style={{ color: '#ef4444' }}>{s.rejected || 0}</strong></span>
        <span style={{ marginLeft: 'auto', color: 'var(--text-muted)' }}>{s.progress_percent || 0}% approved</span>
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
        {rows.map(r => {
          const uploading = busy === `${r.type}:upload` || (r.document_id && busy === `${r.document_id}:upload`)
          const reviewing = r.document_id && busy === `${r.document_id}:review`
          return (
            <div key={r.type} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '11px 14px', borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <FileText size={16} style={{ color: '#a78bfa', flexShrink: 0 }} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{r.type_label}</div>
                {r.original_name && <div style={{ fontSize: 11, color: 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis' }}>{r.original_name}</div>}
                {r.remarks && r.status === 'Rejected' && <div style={{ fontSize: 11, color: '#ef4444' }}>Remarks: {r.remarks}</div>}
              </div>
              <StatusPill cfg={docCfg(r.status)} />
              <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
                {r.uploaded && <button onClick={() => openDoc(r.document_id)} title="View" style={iconBtn}><Eye size={14} /></button>}

                {manage && !r.uploaded && (
                  <>
                    <input type="file" ref={el => (fileRefs.current[r.type] = el)} style={{ display: 'none' }}
                      onChange={e => doUpload(r.type, e.target.files?.[0])} accept=".pdf,.jpg,.jpeg,.png" />
                    <button onClick={() => fileRefs.current[r.type]?.click()} disabled={uploading} style={miniPrimary}>
                      <Upload size={13} /> {uploading ? 'Uploading…' : 'Upload'}
                    </button>
                  </>
                )}
                {manage && r.status === 'Rejected' && (
                  <>
                    <input type="file" ref={el => (fileRefs.current['re' + r.document_id] = el)} style={{ display: 'none' }}
                      onChange={e => doResubmit(r.document_id, e.target.files?.[0])} accept=".pdf,.jpg,.jpeg,.png" />
                    <button onClick={() => fileRefs.current['re' + r.document_id]?.click()} disabled={uploading} style={miniPrimary}>
                      <RefreshCw size={13} /> Resubmit
                    </button>
                  </>
                )}
                {admin && r.status === 'Pending' && (
                  <>
                    <button onClick={() => doReview(r.document_id, 'approve')} disabled={reviewing} title="Approve" style={{ ...iconBtn, color: '#10b981' }}><CheckCircle2 size={15} /></button>
                    <button onClick={() => doReview(r.document_id, 'reject')} disabled={reviewing} title="Reject" style={{ ...iconBtn, color: '#ef4444' }}><XCircle size={15} /></button>
                  </>
                )}
                {!r.uploaded && !manage && <Clock size={14} style={{ color: 'var(--text-muted)' }} />}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}

const iconBtn = { width: 30, height: 30, borderRadius: 8, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }
const miniPrimary = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 8, border: 'none', cursor: 'pointer', fontSize: 12, fontWeight: 700, color: '#fff', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)' }
