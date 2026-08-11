import { useState, useEffect, useRef } from 'react'
import {
  FileCheck, Upload, RotateCcw, Download, CheckCircle2, AlertTriangle, Clock,
  FileText, Loader2, ShieldCheck,
} from 'lucide-react'
import { portalApi } from '@/services/portalApi'
import { docStatusCfg, fmtDate } from './portalConstants'
import { KIT3D_STYLE } from '@/components/ui/kit3d'

const MAX_MB = 8

/**
 * Vendor compliance documents — the portal's write surface. The vendor uploads
 * their own statutory docs and resubmits rejected ones. Every call resolves the
 * vendor from the token server-side, so a vendor can only ever touch their own.
 */
export default function PortalDocuments() {
  const [data, setData] = useState(null)
  const [loading, setLoad] = useState(true)
  const [busy, setBusy] = useState(null)      // the doc type currently uploading
  const [err, setErr] = useState(null)
  const [flash, setFlash] = useState(null)

  // documents is a namespace, not a function — calling it threw a TypeError
  // synchronously, before any promise existed, so the .catch() below could not
  // catch it and the page never left its loading state.
  const load = () => { setLoad(true); portalApi.documents.checklist().then(d => { setData(d?.data ?? d); setLoad(false) }).catch(() => setLoad(false)) }
  useEffect(() => { load() }, [])

  const doUpload = async (type, file) => {
    if (!file) return
    if (file.size > MAX_MB * 1024 * 1024) { setErr(`${file.name} is ${(file.size / 1024 / 1024).toFixed(1)} MB — the limit is ${MAX_MB} MB.`); return }
    setBusy(type); setErr(null); setFlash(null)
    try {
      await portalApi.uploadDocument(type, file)
      setFlash(`Uploaded — ${file.name} is now under review.`)
      load()
    } catch (e) { setErr(e?.response?.data?.message || 'Upload failed. Please try again.') }
    finally { setBusy(null) }
  }

  const doResubmit = async (docId, type, file) => {
    if (!file) return
    if (file.size > MAX_MB * 1024 * 1024) { setErr(`${file.name} is too large (limit ${MAX_MB} MB).`); return }
    setBusy(type); setErr(null); setFlash(null)
    try {
      await portalApi.resubmitDocument(docId, file)
      setFlash('Resubmitted — the new file is under review.')
      load()
    } catch (e) { setErr(e?.response?.data?.message || 'Resubmit failed. Please try again.') }
    finally { setBusy(null) }
  }

  const doDownload = async (docId, name) => {
    try {
      const blob = await portalApi.downloadDocument(docId)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url; a.download = name || 'document'; a.click()
      URL.revokeObjectURL(url)
    } catch { setErr('Could not download that file.') }
  }

  if (loading) {
    return <div><style>{KIT3D_STYLE}</style>
      <div className="skeleton" style={{ height: 60, borderRadius: 16, background: 'var(--border)', marginBottom: 14 }} />
      {[1, 2, 3].map(i => <div key={i} className="skeleton" style={{ height: 68, borderRadius: 14, background: 'var(--border)', marginBottom: 10 }} />)}
    </div>
  }

  const required = data?.required || []
  const extras = data?.extras || []
  const s = data?.summary || {}

  return (
    <div>
      <style>{KIT3D_STYLE}</style>

      <div style={{ marginBottom: 18 }}>
        <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>COMPLIANCE</p>
        <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Your Documents</h1>
        <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Upload your statutory documents. Our team reviews each one and may ask for a resubmission.</p>
      </div>

      {/* Summary + completion */}
      <div className="pr-glass" style={{ padding: 18, marginBottom: 16, display: 'flex', alignItems: 'center', gap: 20, flexWrap: 'wrap' }}>
        <Stat label="Required" value={s.required ?? 0} />
        <Stat label="Uploaded" value={s.uploaded ?? 0} color="#0ea5e9" />
        <Stat label="Approved" value={s.approved ?? 0} color="#10b981" />
        <Stat label="Needs action" value={s.rejected ?? 0} color="#ef4444" danger={s.rejected > 0} />
        <div style={{ marginLeft: 'auto' }}>
          {data?.complete
            ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '8px 14px', borderRadius: 999, background: 'rgba(16,185,129,0.14)', color: '#10b981', fontSize: 13, fontWeight: 800 }}><ShieldCheck size={15} /> All documents approved</span>
            : <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '8px 14px', borderRadius: 999, background: 'rgba(245,158,11,0.12)', color: '#f59e0b', fontSize: 13, fontWeight: 700 }}><Clock size={15} /> {(s.required ?? 0) - (s.approved ?? 0)} still to approve</span>}
        </div>
      </div>

      {flash && <Banner tone="#10b981" icon={CheckCircle2}>{flash}</Banner>}
      {err && <Banner tone="#ef4444" icon={AlertTriangle}>{err}</Banner>}

      {/* Required documents */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        {required.map(row => (
          <DocRow key={row.type} row={row} busy={busy === row.type}
            onUpload={(f) => doUpload(row.type, f)}
            onResubmit={(f) => doResubmit(row.document_id, row.type, f)}
            onDownload={() => doDownload(row.document_id, row.original_name)} />
        ))}
      </div>

      {extras.length > 0 && (
        <>
          <h2 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', margin: '22px 0 10px' }}>Additional documents</h2>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {extras.map(row => (
              <DocRow key={row.document_id} row={{ ...row, uploaded: true }} busy={false} extra
                onDownload={() => doDownload(row.document_id, row.original_name)} />
            ))}
          </div>
        </>
      )}
    </div>
  )
}

function DocRow({ row, busy, onUpload, onResubmit, onDownload, extra }) {
  const uploadRef = useRef(null)
  const resubmitRef = useRef(null)
  const cfg = docStatusCfg(row.status)
  const rejected = row.status === 'Rejected'

  return (
    <div className="pr-glass" style={{ padding: 16, display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap',
      outline: rejected ? '1.5px solid rgba(239,68,68,0.35)' : 'none' }}>
      <span style={{ width: 40, height: 40, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
        background: row.uploaded ? `${cfg.color}1f` : 'var(--bg-input)' }}>
        {row.uploaded ? <FileText size={18} style={{ color: cfg.color }} /> : <FileCheck size={18} style={{ color: 'var(--text-muted)' }} />}
      </span>

      <div style={{ flex: 1, minWidth: 180 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <span style={{ fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)' }}>{row.type_label}</span>
          <span style={{ padding: '2px 9px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 10.5, fontWeight: 800 }}>{row.status_label}</span>
        </div>
        {row.original_name && <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2 }}>{row.original_name}{row.reviewed_at ? ` · reviewed ${fmtDate(row.reviewed_at)}` : ''}</div>}
        {/* The reviewer's reason is the whole point of a rejection — show it. */}
        {rejected && row.remarks && (
          <div style={{ fontSize: 11.5, color: '#f87171', marginTop: 4, display: 'flex', alignItems: 'flex-start', gap: 5 }}>
            <AlertTriangle size={12} style={{ flexShrink: 0, marginTop: 1 }} /> {row.remarks}
          </div>
        )}
      </div>

      <div style={{ display: 'flex', gap: 8, flexShrink: 0 }}>
        {row.uploaded && (
          <button onClick={onDownload} title="Download" style={iconBtn}><Download size={15} /></button>
        )}

        {extra ? null : rejected ? (
          <>
            <input ref={resubmitRef} type="file" accept=".pdf,.jpg,.jpeg,.png" style={{ display: 'none' }}
              onChange={e => { onResubmit(e.target.files?.[0]); e.target.value = '' }} />
            <button onClick={() => resubmitRef.current?.click()} disabled={busy} style={primaryBtn('#ef4444')}>
              {busy ? <Loader2 size={14} className="vp-spin" /> : <RotateCcw size={14} />} Resubmit
            </button>
          </>
        ) : (
          <>
            <input ref={uploadRef} type="file" accept=".pdf,.jpg,.jpeg,.png" style={{ display: 'none' }}
              onChange={e => { onUpload(e.target.files?.[0]); e.target.value = '' }} />
            <button onClick={() => uploadRef.current?.click()} disabled={busy} style={primaryBtn(row.uploaded ? '#7C3AED' : '#7C3AED')}>
              {busy ? <Loader2 size={14} className="vp-spin" /> : <Upload size={14} />} {row.uploaded ? 'Replace' : 'Upload'}
            </button>
          </>
        )}
      </div>
      <style>{`@keyframes vpSpin{to{transform:rotate(360deg)}}.vp-spin{animation:vpSpin .9s linear infinite}`}</style>
    </div>
  )
}

const Stat = ({ label, value, color = 'var(--text-h)', danger }) => (
  <div style={{ outline: danger ? '1.5px solid rgba(239,68,68,0.3)' : 'none', borderRadius: 10, padding: danger ? '4px 10px' : 0 }}>
    <div style={{ fontSize: 22, fontWeight: 900, color, lineHeight: 1 }}>{value}</div>
    <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 3, fontWeight: 600 }}>{label}</div>
  </div>
)

const Banner = ({ tone, icon: Icon, children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '11px 14px', borderRadius: 12, marginBottom: 14, background: `${tone}12`, border: `1px solid ${tone}55` }}>
    <Icon size={15} style={{ color: tone, flexShrink: 0 }} />
    <span style={{ fontSize: 13, color: 'var(--text-h)' }}>{children}</span>
  </div>
)

const iconBtn = { width: 36, height: 36, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }
const primaryBtn = (color) => ({
  display: 'inline-flex', alignItems: 'center', gap: 6, height: 36, padding: '0 14px', borderRadius: 10, cursor: 'pointer', fontSize: 12.5, fontWeight: 700,
  color: '#fff', border: 'none', background: `linear-gradient(145deg, ${color}dd, ${color})`, boxShadow: `0 8px 18px -6px ${color}88`,
})
