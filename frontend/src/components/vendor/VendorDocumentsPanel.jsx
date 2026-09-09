import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import {
  Upload, RotateCcw, Eye, Trash2, CheckCircle, XCircle, FileText, Loader,
  AlertTriangle, Download, History, Info, HelpCircle, ChevronRight, Search,
} from 'lucide-react'
import { Overlay, ModalFooter, InfoBox, StatusBadge as StatusPill, PRIMARY_GRADIENT } from '@/components/ui/kit3d'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { readFieldErrors } from '@/services/apiError'
import { DOC_CATEGORY_ORDER, COMPLIANCE_PROVIDERS, categoryOf } from './documentCatalog'

/**
 * The vendor document surface — one panel, both engines.
 *
 * TPV's onboarding grew a thousand-line document step: grouped by category,
 * searchable, filterable, drag-and-drop, with preview, version history, delete,
 * a rejection rationale and a route to a compliance provider when the vendor
 * simply does not hold the paperwork yet. Purchase's was a flat list with an
 * Upload button, a browser alert for errors and a browser prompt for rejection
 * remarks — so a Purchase vendor could not preview what they had sent, could
 * not see what they had sent before, could not remove a wrong scan, and had no
 * way to tell which of eleven rows was the one still blocking them.
 *
 * The two screens were copies that drifted, which is how every Purchase defect
 * this month began. So this is one component and the difference between the
 * engines is data: an `api` object and a `catalog`. Neither is named here.
 *
 * ── On colour ───────────────────────────────────────────────────────────────
 * Every tinted surface is an `rgba()` wash composited over the themed card, and
 * every piece of text is a theme token. The screens this replaces used opaque
 * light hexes (#f0fdf4, #fff5f5, #f8fafc) under `var(--text-h)`, which in dark
 * mode is #edeaf8 — near-white text on a near-white ground. Approved and
 * rejected rows were literally unreadable.
 *
 * @param {object} api        { checklist, upload, resubmit, review, delete, versions, downloadVersion, open }
 * @param {Array}  catalog    the engine's document definitions (see documentCatalog.js)
 * @param {object} checklist  a pre-fetched checklist; omit and the panel loads its own
 */
export default function VendorDocumentsPanel({
  api,
  catalog,
  vendorId = null,
  checklist: checklistProp = undefined,
  onboarding = null,
  user = null,
  editable = true,
  manage = false,
  admin = false,
  reviewMode = false,
  providers = COMPLIANCE_PROVIDERS,
  onChanged,
  footer = null,
}) {
  // When no checklist is handed in, the panel owns the fetch. The onboarding
  // wizards pass theirs (they gate Continue on it); the standalone Documents
  // pages do not.
  const [ownChecklist, setOwnChecklist] = useState(null)
  const [loading, setLoading] = useState(checklistProp === undefined)
  const selfLoading = checklistProp === undefined

  const [busy, setBusy] = useState(null)
  const [progress, setProgress] = useState(0)
  const [err, setErr] = useState(null)
  const [flash, setFlash] = useState(null)
  const [reviewing, setReviewing] = useState(null)
  const [historyDoc, setHistoryDoc] = useState(null)
  const [previewDoc, setPreviewDoc] = useState(null)
  const [providerReq, setProviderReq] = useState(null)
  const [providerOpen, setProviderOpen] = useState({})
  const [providerSearch, setProviderSearch] = useState({})
  const [submittedRequests, setSubmittedRequests] = useState([])
  const [stagedFiles, setStagedFiles] = useState({})
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('ALL')
  const [sortBy, setSortBy] = useState('STATUS')
  const [openSections, setOpenSections] = useState(
    () => Object.fromEntries(DOC_CATEGORY_ORDER.map(c => [c, true])),
  )

  const inputs = useRef({})

  const reload = useCallback(async () => {
    if (!selfLoading) { onChanged?.(); return }
    setLoading(true)
    try { setOwnChecklist(await api.checklist(vendorId)) }
    catch (e) { setErr(readFieldErrors(e).summary) }
    finally { setLoading(false) }
    onChanged?.()
  }, [api, vendorId, selfLoading, onChanged])

  useEffect(() => { if (selfLoading) reload() }, [selfLoading]) // eslint-disable-line react-hooks/exhaustive-deps

  const checklist = selfLoading ? ownChecklist : checklistProp

  /*
   * The server's checklist is the authority on what THIS vendor must supply —
   * a temporary Purchase vendor is asked for three documents, not eleven. The
   * catalog only supplies the label, the grouping and the required default, so
   * a type the server did not ask for shows as optional rather than as a
   * missing obligation the vendor cannot discharge.
   */
  const rows = useMemo(() => {
    const backend = checklist?.required || []
    const byType = new Map(backend.map(r => [r.type, r]))
    const known = new Set(catalog.map(d => d.type))

    const merged = catalog.map((def) => {
      const hit = byType.get(def.type)
      return hit
        ? { ...hit, type_label: hit.type_label || def.label, required: true, category: def.category, sample: def.sample }
        : {
            type: def.type, type_label: def.label, required: false, category: def.category,
            sample: def.sample, uploaded: false, status: null, original_name: null, document_id: null,
          }
    })

    // Anything the server asks for that the catalog has never heard of, plus
    // the vendor's own extra uploads — shown, never silently dropped.
    backend.forEach((r) => {
      if (!known.has(r.type)) merged.push({ ...r, required: true, category: categoryOf(catalog, r.type) })
    })
    ;(checklist?.extras || []).forEach((r) => {
      if (!known.has(r.type)) merged.push({ ...r, required: false, uploaded: true, category: 'Other Documents' })
    })

    return merged
  }, [checklist, catalog])

  const s = checklist?.summary || {}
  const complete = !!checklist?.complete
  const totalRequired = s.required ?? rows.filter(r => r.required).length
  const totalUploaded = s.uploaded ?? rows.filter(r => r.uploaded).length
  const totalApproved = s.approved ?? rows.filter(r => r.status === 'Approved').length
  const totalRejected = s.rejected ?? rows.filter(r => r.status === 'Rejected').length
  const totalPending = s.pending ?? rows.filter(r => r.uploaded && r.status !== 'Approved' && r.status !== 'Rejected').length
  const totalMissing = Math.max(0, totalRequired - totalUploaded)
  const pct = s.progress_percent ?? (totalRequired ? Math.round((totalApproved / totalRequired) * 100) : 0)

  // While an admin is reviewing, the vendor may still be uploading. Never while
  // a dialog is open or an upload is in flight — refreshing under either would
  // throw away what the user is in the middle of.
  useEffect(() => {
    if (!reviewMode) return undefined
    const id = setInterval(() => {
      if (document.hidden || busy || reviewing || previewDoc || historyDoc) return
      reload()
    }, 15000)
    return () => clearInterval(id)
  }, [reviewMode, busy, reviewing, previewDoc, historyDoc, reload])

  /* ── Actions ─────────────────────────────────────────────────────────── */

  const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx']
  const MAX_MB = 10

  const onFile = async (row, file) => {
    if (!file) return
    setErr(null); setFlash(null)

    // Checked here as well as on the server, because a 10 MB upload that fails
    // validation has already cost the vendor the upload.
    const ext = (file.name || '').split('.').pop().toLowerCase()
    if (!ALLOWED.includes(ext)) {
      setErr(`${file.name} is a .${ext} file. Accepted formats are PDF, JPG, PNG, DOC, DOCX, XLS and XLSX.`)
      return
    }
    if (file.size > MAX_MB * 1024 * 1024) {
      setErr(`${file.name} is ${(file.size / 1024 / 1024).toFixed(1)} MB — the limit is ${MAX_MB} MB.`)
      return
    }

    setStagedFiles(prev => ({ ...prev, [row.type]: file.name }))
    setBusy(row.type)
    setProgress(25)
    const tick = setInterval(() => setProgress(p => (p < 90 ? p + 15 : p)), 200)

    try {
      // A rejected document goes back through resubmit, which keeps the version
      // history and clears the reviewer's remarks. Anything else is replaced in
      // place; the server refuses either once approved.
      if (row.document_id && row.status === 'Rejected') await api.resubmit(row.document_id, file)
      else await api.upload(vendorId, row.type, file)
      setProgress(100)
      setFlash(`${file.name} uploaded — ${row.type_label} is now under review.`)
      await reload()
    } catch (e) {
      setErr(readFieldErrors(e).summary)
      setStagedFiles(prev => { const n = { ...prev }; delete n[row.type]; return n })
    } finally {
      clearInterval(tick)
      setTimeout(() => { setBusy(null); setProgress(0) }, 400)
    }
  }

  const handleDrop = (e, row) => {
    e.preventDefault()
    if (!editable || row.status === 'Approved' || reviewMode) return
    const file = e.dataTransfer?.files?.[0]
    if (file) onFile(row, file)
  }

  const viewPreview = async (row) => {
    setErr(null)
    try {
      const url = await api.open(row.document_id)
      const ext = (row.original_name || '').split('.').pop().toLowerCase()
      if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) setPreviewDoc({ url, name: row.type_label, type: 'image' })
      else if (ext === 'pdf') setPreviewDoc({ url, name: row.type_label, type: 'pdf' })
      else window.open(url, '_blank', 'noopener')
    } catch { setErr('Could not open that document.') }
  }

  // Asked through the shared ConfirmDialog rather than window.confirm: a browser
  // dialog blocks the page, cannot be styled, and on a phone reads like a virus
  // warning. BannedPatternsTest enforces this across the front end.
  const del = async (row) => {
    setConfirmDelete(null)
    setErr(null)
    try {
      await api.delete(row.document_id)
      setStagedFiles(prev => { const n = { ...prev }; delete n[row.type]; return n })
      setFlash(`${row.type_label} removed.`)
      await reload()
    } catch (e) { setErr(readFieldErrors(e).summary) }
  }

  const runReview = async (remarks) => {
    const { row, decision } = reviewing
    try {
      await api.review(row.document_id, decision, remarks)
      setReviewing(null)
      setFlash(`${row.type_label} ${decision === 'approve' ? 'approved' : 'rejected'}. The vendor has been notified.`)
      await reload()
    } catch (e) { setErr(readFieldErrors(e).summary) }
  }

  const downloadSample = (row) => {
    const body = `${row.type_label}\n\nSample template. Complete, sign, and upload the signed copy.\n`
    const url = URL.createObjectURL(new Blob([body], { type: 'application/msword' }))
    const a = document.createElement('a')
    a.href = url
    a.download = row.sample || `${row.type}_sample.doc`
    document.body.appendChild(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 10000)
  }

  /* ── Filter, sort, group ─────────────────────────────────────────────── */

  const visible = useMemo(() => {
    const q = search.trim().toLowerCase()
    const filtered = rows.filter((r) => {
      const matches = !q
        || (r.type_label || '').toLowerCase().includes(q)
        || (r.original_name || '').toLowerCase().includes(q)
      if (!matches) return false
      if (statusFilter === 'APPROVED') return r.status === 'Approved'
      if (statusFilter === 'REJECTED') return r.status === 'Rejected'
      if (statusFilter === 'PENDING') return r.uploaded && r.status !== 'Approved' && r.status !== 'Rejected'
      if (statusFilter === 'MISSING') return !r.uploaded
      return true
    })

    // Default order puts what the vendor must act on at the top: rejected
    // first, then never uploaded, then awaiting review, approved last.
    const rank = (r) => (r.status === 'Rejected' ? 0 : !r.uploaded ? 1 : r.status === 'Approved' ? 3 : 2)
    return [...filtered].sort((a, b) => (
      sortBy === 'NAME' ? (a.type_label || '').localeCompare(b.type_label || '') : rank(a) - rank(b)
    ))
  }, [rows, search, statusFilter, sortBy])

  const grouped = useMemo(() => DOC_CATEGORY_ORDER
    .map(cat => [cat, visible.filter(r => (r.category || 'Other Documents') === cat)])
    .filter(([, items]) => items.length > 0), [visible])

  if (loading && !checklist) {
    return <div style={{ padding: 34, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>Loading documents…</div>
  }
  if (!checklist) {
    return <div style={{ padding: 34, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>Could not load the document checklist.</div>
  }

  return (
    <div>
      {reviewMode && !admin && <InfoBox>Only an admin can approve or reject documents. Review status here updates on its own.</InfoBox>}
      {!reviewMode && !editable && <InfoBox>This onboarding is locked — documents can no longer be changed.</InfoBox>}

      {/* What is accepted, before the vendor picks a file rather than after. */}
      {!reviewMode && (
        <div style={{ ...tinted('#3b82f6', 0.07), borderRadius: 12, padding: 15, marginBottom: 18 }}>
          <h4 style={{ margin: '0 0 6px', fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 6 }}>
            <Info size={15} style={{ color: '#3b82f6' }} /> Document upload guidelines
          </h4>
          <p style={{ margin: '0 0 4px', fontSize: 12.5, color: 'var(--text-muted)' }}>
            Accepted formats: <strong style={{ color: 'var(--text-h)' }}>PDF, JPG, PNG, DOC, DOCX, XLS, XLSX</strong> — up to {MAX_MB} MB each.
          </p>
          <p style={{ margin: 0, fontSize: 12, color: 'var(--text-muted)' }}>
            Documents marked <span style={{ color: '#ef4444', fontWeight: 800 }}>*</span> are mandatory. Don't hold one yet? Open
            {' '}<strong style={{ color: 'var(--text-h)' }}>“Don't have this document?”</strong> on that row to reach a provider.
          </p>
        </div>
      )}

      {busy && (
        <div style={{ ...tinted('#0ea5e9', 0.1), marginBottom: 14, padding: '11px 15px', borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
          <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', display: 'inline-flex', alignItems: 'center', gap: 8 }}>
            <Loader size={14} className="vdp-spin" /> Uploading… {progress}%
          </span>
          <div style={{ width: 150, height: 6, borderRadius: 999, background: 'rgba(14,165,233,0.22)', overflow: 'hidden' }}>
            <div style={{ width: `${progress}%`, height: '100%', background: '#0ea5e9', transition: 'width .2s' }} />
          </div>
        </div>
      )}

      {flash && <Banner tone="#10b981" icon={CheckCircle} onDismiss={() => setFlash(null)}>{flash}</Banner>}
      {err && <Banner tone="#ef4444" icon={AlertTriangle} onDismiss={() => setErr(null)}>{err}</Banner>}

      {/* Progress overview */}
      <div style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16, padding: 18, marginBottom: 20 }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 13, flexWrap: 'wrap', gap: 12 }}>
          <div>
            <h3 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Document progress</h3>
            <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>
              {totalApproved} of {totalRequired} mandatory documents approved ({pct}%)
            </span>
          </div>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <Stat label="Required" value={totalRequired} color="#a78bfa" />
            <Stat label="Uploaded" value={totalUploaded} color="#0ea5e9" />
            <Stat label="Approved" value={totalApproved} color="#10b981" />
            <Stat label="Pending" value={totalPending} color="#f59e0b" />
            <Stat label="Rejected" value={totalRejected} color="#ef4444" />
            <Stat label="Missing" value={totalMissing} color="var(--text-muted)" />
          </div>
        </div>
        <div style={{ height: 9, borderRadius: 999, background: 'var(--bg-input)', overflow: 'hidden', border: '1px solid var(--border)' }}>
          <div style={{ width: `${pct}%`, height: '100%', background: 'linear-gradient(90deg,#0ea5e9,#10b981)', borderRadius: 999, transition: 'width .4s ease' }} />
        </div>
      </div>

      {/* Search / filter / sort */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16, flexWrap: 'wrap' }}>
        <div style={{ position: 'relative', flex: 1, minWidth: 220 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: 10, color: 'var(--text-muted)' }} />
          <input
            type="text" value={search} onChange={e => setSearch(e.target.value)}
            placeholder="Search documents by name or file…"
            style={{ width: '100%', padding: '8px 12px 8px 32px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, outline: 'none' }}
          />
        </div>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {['ALL', 'APPROVED', 'PENDING', 'REJECTED', 'MISSING'].map(f => (
            <button key={f} type="button" onClick={() => setStatusFilter(f)}
              style={{
                padding: '6px 12px', borderRadius: 8, fontSize: 11.5, fontWeight: 700, cursor: 'pointer',
                border: statusFilter === f ? '1px solid transparent' : '1px solid var(--border)',
                background: statusFilter === f ? PRIMARY_GRADIENT : 'var(--bg-card)',
                color: statusFilter === f ? '#fff' : 'var(--text-muted)',
              }}>
              {f.charAt(0) + f.slice(1).toLowerCase()}
            </button>
          ))}
        </div>
        <select value={sortBy} onChange={e => setSortBy(e.target.value)}
          style={{ padding: '7px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12 }}>
          <option value="STATUS">Sort: needs attention first</option>
          <option value="NAME">Sort: document name</option>
        </select>
      </div>

      {grouped.length === 0 && (
        <div style={{ padding: 30, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13, border: '1px solid var(--border)', borderRadius: 12 }}>
          No documents match that search or filter.
        </div>
      )}

      {grouped.map(([category, items]) => {
        const open = openSections[category]
        const approvedHere = items.filter(i => i.status === 'Approved').length
        return (
          <div key={category} style={{ marginBottom: 16, border: '1px solid var(--border)', borderRadius: 14, overflow: 'hidden', background: 'var(--bg-card)' }}>
            <button type="button" onClick={() => setOpenSections(p => ({ ...p, [category]: !p[category] }))}
              style={{ width: '100%', display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '13px 17px', background: 'var(--bg-input)', border: 'none', cursor: 'pointer', textAlign: 'left' }}>
              <span style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <FileText size={17} style={{ color: '#a78bfa' }} />
                <span style={{ fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)' }}>{category}</span>
                <span style={{ fontSize: 11.5, fontWeight: 700, background: 'rgba(124,58,237,0.16)', color: '#a78bfa', padding: '2px 8px', borderRadius: 12 }}>
                  {approvedHere} / {items.length} approved
                </span>
              </span>
              <span style={{ fontSize: 12, color: 'var(--text-muted)', fontWeight: 600 }}>{open ? 'Collapse ▲' : 'Expand ▼'}</span>
            </button>

            {open && (
              <div style={{ padding: 13, display: 'flex', flexDirection: 'column', gap: 13 }}>
                {items.map(row => (
                  <DocumentRow
                    key={row.type}
                    row={row}
                    busy={busy === row.type}
                    staged={stagedFiles[row.type]}
                    editable={editable}
                    manage={manage}
                    admin={admin}
                    reviewMode={reviewMode}
                    providers={providers}
                    providerOpen={!!providerOpen[row.type]}
                    providerSearch={providerSearch[row.type] || ''}
                    providerRequest={submittedRequests.find(r => r.docType === row.type)}
                    hasHistory={typeof api.versions === 'function'}
                    canDelete={typeof api.delete === 'function'}
                    onToggleProviders={() => setProviderOpen(p => ({ ...p, [row.type]: !p[row.type] }))}
                    onProviderSearch={(v) => setProviderSearch(p => ({ ...p, [row.type]: v }))}
                    onPickProvider={(provider) => setProviderReq({ provider, row })}
                    onPick={() => inputs.current[row.type]?.click()}
                    onDrop={(e) => handleDrop(e, row)}
                    onView={() => viewPreview(row)}
                    onHistory={() => setHistoryDoc(row.document_id)}
                    onDelete={() => setConfirmDelete(row)}
                    onReview={(decision) => setReviewing({ row, decision })}
                    onSample={() => downloadSample(row)}
                    inputRef={(el) => { inputs.current[row.type] = el }}
                    onFile={(f) => onFile(row, f)}
                  />
                ))}
              </div>
            )}
          </div>
        )
      })}

      {footer?.({ complete, totalMissing, totalRejected })}

      {confirmDelete && (
        <ConfirmDialog
          title={`Remove ${confirmDelete.type_label}?`}
          message="The upload is removed and you can upload the document again afterwards. Anything already approved cannot be removed."
          confirmLabel="Remove upload"
          onConfirm={() => del(confirmDelete)}
          onCancel={() => setConfirmDelete(null)}
        />
      )}

      {reviewing && <ReviewModal reviewing={reviewing} onClose={() => setReviewing(null)} onConfirm={runReview} />}

      {historyDoc && (
        <VersionHistoryDrawer documentId={historyDoc} api={api} onClose={() => setHistoryDoc(null)} />
      )}

      {previewDoc && (
        <Overlay onClose={() => setPreviewDoc(null)} width={previewDoc.type === 'image' ? 680 : 860} showClose={false}>
          <div style={{ padding: '2px 0 14px', borderBottom: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <h3 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{previewDoc.name}</h3>
            <button onClick={() => setPreviewDoc(null)} aria-label="Close" style={closeX}>✕</button>
          </div>
          <div style={{ padding: '18px 0', textAlign: 'center', maxHeight: 600, overflowY: 'auto' }}>
            {previewDoc.type === 'image'
              ? <img src={previewDoc.url} alt={previewDoc.name} style={{ maxWidth: '100%', maxHeight: 520, borderRadius: 8, objectFit: 'contain' }} />
              : <iframe src={previewDoc.url} title={previewDoc.name} style={{ width: '100%', height: 520, border: 'none', borderRadius: 8, background: '#fff' }} />}
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
            <a href={previewDoc.url} target="_blank" rel="noreferrer" download
              style={{ padding: '8px 16px', borderRadius: 8, background: PRIMARY_GRADIENT, color: '#fff', textDecoration: 'none', fontSize: 12, fontWeight: 700 }}>
              Download file
            </a>
          </div>
        </Overlay>
      )}

      {providerReq && (
        <ProviderRequestModal
          provider={providerReq.provider}
          row={providerReq.row}
          onboarding={onboarding}
          user={user}
          onClose={() => setProviderReq(null)}
          onSubmitted={(req) => setSubmittedRequests(prev => [...prev, req])}
        />
      )}

      <style>{'@keyframes vdpSpin{to{transform:rotate(360deg)}}.vdp-spin{animation:vdpSpin .9s linear infinite}'}</style>
    </div>
  )
}

/* ── One document ─────────────────────────────────────────────────────────── */

function DocumentRow({
  row, busy, staged, editable, manage, admin, reviewMode, providers, providerOpen, providerSearch,
  providerRequest, hasHistory, canDelete, onToggleProviders, onProviderSearch, onPickProvider,
  onPick, onDrop, onView, onHistory, onDelete, onReview, onSample, inputRef, onFile,
}) {
  const approved = row.status === 'Approved'
  const rejected = row.status === 'Rejected'
  const shown = row.original_name || staged || null
  const cfg = statusCfg(row.status, row.status_label, !!(row.uploaded || staged))
  const canUpload = manage && editable && !reviewMode && !approved

  // Tints, not fills — see the note at the top of the file. Each is an alpha
  // wash over var(--bg-card), so the row reads correctly in both themes.
  const tint = rejected ? 'rgba(239,68,68,0.07)' : approved ? 'rgba(16,185,129,0.07)' : 'var(--bg-card)'
  const edge = rejected ? 'rgba(239,68,68,0.38)' : approved ? 'rgba(16,185,129,0.34)' : 'var(--border)'

  const providerList = providers.filter(p =>
    p.name.toLowerCase().includes(providerSearch.toLowerCase())
    || p.desc.toLowerCase().includes(providerSearch.toLowerCase()))

  return (
    <div style={{ padding: 16, borderRadius: 14, border: `1.5px solid ${edge}`, background: tint }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
        <div style={{ flex: 1, minWidth: 250 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
            <span style={{ fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)' }}>
              {row.type_label}{row.required && <span style={{ color: '#ef4444' }}> *</span>}
            </span>
            <span style={{
              fontSize: 10, fontWeight: 800, padding: '1px 7px', borderRadius: 6,
              ...(row.required
                ? { color: '#ef4444', border: '1px solid rgba(239,68,68,0.45)', background: 'rgba(239,68,68,0.09)' }
                : { color: 'var(--text-muted)', border: '1px solid var(--border)', background: 'var(--bg-input)' }),
            }}>
              {row.required ? 'Required' : 'Optional'}
            </span>
            <StatusPill cfg={cfg} />
          </div>

          {canUpload && (
            <div onDragOver={e => e.preventDefault()} onDrop={onDrop}
              style={{
                marginTop: 11, padding: '13px 15px', borderRadius: 10, border: '2px dashed var(--border)',
                background: 'var(--bg-input)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap',
              }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <Upload size={17} style={{ color: '#a78bfa' }} />
                <div>
                  <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>Drag &amp; drop a file here, or</div>
                  <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                    {shown ? `Selected: ${shown}` : 'No file selected — PDF, JPG, PNG, DOC or XLS up to 10 MB'}
                  </div>
                </div>
              </div>
              <button type="button" onClick={onPick} disabled={busy}
                style={{ padding: '7px 14px', borderRadius: 8, background: PRIMARY_GRADIENT, color: '#fff', border: 'none', fontSize: 12, fontWeight: 700, cursor: busy ? 'wait' : 'pointer' }}>
                {busy ? 'Uploading…' : 'Choose file'}
              </button>
            </div>
          )}

          {shown && (
            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 8, display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
              <FileText size={13} style={{ color: '#10b981' }} />
              <span>File: <strong style={{ color: 'var(--text-h)' }}>{shown}</strong></span>
              {row.uploaded_at && <span>· uploaded {fmt(row.uploaded_at)}</span>}
              {row.reviewed_by && <span>· reviewed by <strong style={{ color: 'var(--text-h)' }}>{row.reviewed_by}</strong></span>}
              {row.expires_at && <span>· expires {new Date(row.expires_at).toLocaleDateString()}</span>}
            </div>
          )}

          {providerRequest && (
            <div style={{ ...tinted('#f97316', 0.1), marginTop: 8, padding: '8px 12px', borderRadius: 8, fontSize: 12, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 6 }}>
              <Info size={13} style={{ color: '#f97316' }} /> Callback requested from {providerRequest.providerName} — pending
            </div>
          )}

          {row.sample && canUpload && (
            <div style={{ ...tinted('#7C3AED', 0.07), marginTop: 10, padding: '9px 13px', borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
              <span style={{ fontSize: 12, color: 'var(--text-h)', fontWeight: 600 }}>Download the sample, fill it in, and upload the signed copy.</span>
              <button type="button" onClick={onSample}
                style={{ padding: '6px 13px', borderRadius: 8, background: PRIMARY_GRADIENT, color: '#fff', border: 'none', fontSize: 11.5, fontWeight: 700, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                <Download size={12} /> Download sample
              </button>
            </div>
          )}

          {canUpload && !row.uploaded && (
            <div style={{ marginTop: 10 }}>
              <button type="button" onClick={onToggleProviders}
                style={{
                  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '5px 12px', borderRadius: 20,
                  border: '1px solid var(--border)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700,
                  background: providerOpen ? 'rgba(59,130,246,0.12)' : 'var(--bg-input)',
                  color: providerOpen ? '#60a5fa' : 'var(--text-muted)',
                }}>
                <HelpCircle size={12} /> Don't have this document? {providerOpen ? '▲' : '▼'}
              </button>
            </div>
          )}
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
          <input type="file" ref={inputRef} style={{ display: 'none' }}
            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
            onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; onFile(f) }} />

          {admin && row.uploaded && !approved && (
            <MiniBtn onClick={() => onReview('approve')} color="#10b981" icon={CheckCircle}>Approve</MiniBtn>
          )}
          {admin && row.uploaded && !rejected && (
            <MiniBtn onClick={() => onReview('reject')} color="#ef4444" icon={XCircle}>Reject</MiniBtn>
          )}

          {canUpload && (
            <MiniBtn onClick={onPick} color={rejected ? '#f59e0b' : '#7C3AED'} icon={rejected ? RotateCcw : Upload} disabled={busy}>
              {busy ? 'Uploading…' : rejected ? 'Upload new version' : row.uploaded ? 'Replace file' : 'Browse file'}
            </MiniBtn>
          )}
          {row.uploaded && <MiniBtn onClick={onView} icon={Eye} ghost>View</MiniBtn>}
          {row.uploaded && row.document_id && hasHistory && <MiniBtn onClick={onHistory} icon={History} ghost>History</MiniBtn>}
          {canUpload && row.uploaded && canDelete && <MiniBtn onClick={onDelete} icon={Trash2} ghost danger title="Remove this upload" />}
        </div>
      </div>

      {/* The reviewer's reason is the only thing that tells the vendor what to
          fix, so it is stated in full rather than truncated to one line. */}
      {rejected && row.remarks && (
        <div style={{ ...tinted('#ef4444', 0.1), marginTop: 12, padding: 11, borderRadius: 8, display: 'flex', alignItems: 'flex-start', gap: 8 }}>
          <AlertTriangle size={15} style={{ color: '#ef4444', flexShrink: 0, marginTop: 1 }} />
          <div style={{ fontSize: 12, color: 'var(--text-h)', lineHeight: 1.55 }}>
            <strong>Why this was rejected:</strong> {row.remarks}
          </div>
        </div>
      )}

      {providerOpen && (
        <div style={{ marginTop: 13, padding: 15, borderRadius: 12, border: '1px solid var(--border)', background: 'var(--bg-input)' }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 11, gap: 10, flexWrap: 'wrap' }}>
            <span style={{ fontSize: 11.5, fontWeight: 900, letterSpacing: '0.05em', color: 'var(--text-h)' }}>SERVICE PROVIDERS</span>
            <span style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)', padding: '2px 8px', borderRadius: 10 }}>powered by our network</span>
          </div>
          <input type="text" value={providerSearch} onChange={e => onProviderSearch(e.target.value)}
            placeholder="Search providers…"
            style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)', fontSize: 12, outline: 'none', marginBottom: 11 }} />
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {providerList.map(p => (
              <button key={p.id} type="button" onClick={() => onPickProvider(p)}
                style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, padding: '10px 13px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', cursor: 'pointer', textAlign: 'left', width: '100%' }}>
                <span style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                  <span style={{ width: 36, height: 36, borderRadius: 10, background: p.logoBg, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontWeight: 900, fontSize: 13, flexShrink: 0 }}>{p.logoText}</span>
                  <span>
                    <span style={{ display: 'block', fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>{p.name}</span>
                    <span style={{ display: 'block', fontSize: 11.5, color: 'var(--text-muted)' }}>{p.desc}</span>
                  </span>
                </span>
                <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <span style={{ fontSize: 10.5, fontWeight: 800, padding: '3px 8px', borderRadius: 6, ...providerBadge(p.badgeTone) }}>{p.badge}</span>
                  <ChevronRight size={15} style={{ color: 'var(--text-muted)' }} />
                </span>
              </button>
            ))}
            {providerList.length === 0 && (
              <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>No provider matches that search.</span>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

/* ── Dialogs ──────────────────────────────────────────────────────────────── */

/**
 * Approve or reject, with a reason.
 *
 * Purchase collected rejection remarks through `window.prompt()` — no context,
 * no validation, unstyled, and impossible on some mobile browsers. A rejection
 * without a usable reason is the vendor's problem to solve blind, so the reason
 * is mandatory and the field says so.
 */
function ReviewModal({ reviewing, onClose, onConfirm }) {
  const { row, decision } = reviewing
  const approve = decision === 'approve'
  const [remarks, setRemarks] = useState('')
  const [err, setErr] = useState(null)
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    if (!approve && !remarks.trim()) { setErr('Tell the vendor why — this is the only thing they will see.'); return }
    setBusy(true)
    try { await onConfirm(remarks) } finally { setBusy(false) }
  }

  return (
    <Overlay onClose={onClose} width={470}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 6 }}>
        {approve ? <CheckCircle size={19} style={{ color: '#10b981' }} /> : <XCircle size={19} style={{ color: '#ef4444' }} />}
        <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>
          {approve ? 'Approve' : 'Reject'} {row.type_label}
        </h3>
      </div>
      <p style={{ margin: '0 0 14px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        {approve
          ? 'The vendor is notified and this document counts towards their onboarding.'
          : 'The vendor is notified and asked to upload a new version. Their previous file is kept in the version history.'}
      </p>

      <label style={{ display: 'block', fontSize: 11.5, fontWeight: 800, color: 'var(--text-muted)', marginBottom: 5, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
        {approve ? 'Remarks (optional)' : 'Reason for rejection (required)'}
      </label>
      <textarea value={remarks} onChange={e => { setRemarks(e.target.value); setErr(null) }} rows={4}
        placeholder={approve ? 'Anything worth recording…' : 'e.g. the certificate expired in March — please upload the current one.'}
        style={{ width: '100%', padding: '10px 12px', borderRadius: 9, border: `1px solid ${err ? '#ef4444' : 'var(--border)'}`, background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, resize: 'vertical', outline: 'none', fontFamily: 'inherit' }} />
      {err && <div style={{ color: '#ef4444', fontSize: 11.5, marginTop: 5 }}>{err}</div>}

      <ModalFooter onClose={onClose} onConfirm={submit} loading={busy}
        confirmLabel={approve ? 'Approve document' : 'Reject document'} color={approve ? '#10b981' : '#ef4444'} />
    </Overlay>
  )
}

/** Everything the vendor has sent for this document, newest first. */
function VersionHistoryDrawer({ documentId, api, onClose }) {
  const [versions, setVersions] = useState(null)
  const [busy, setBusy] = useState(null)
  const [err, setErr] = useState(null)

  useEffect(() => {
    let alive = true
    Promise.resolve(api.versions(documentId))
      .then(v => { if (alive) setVersions(Array.isArray(v) ? v : (v?.data ?? [])) })
      .catch(() => { if (alive) { setVersions([]); setErr('Could not load the version history.') } })
    return () => { alive = false }
  }, [documentId, api])

  const download = async (v) => {
    setBusy(v.id); setErr(null)
    try {
      const blob = await api.downloadVersion(documentId, v.id)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url; a.download = v.original_name || `version-${v.version_no}`
      document.body.appendChild(a); a.click(); a.remove()
      setTimeout(() => URL.revokeObjectURL(url), 10000)
    } catch { setErr('Could not download that version.') }
    finally { setBusy(null) }
  }

  return (
    <Overlay onClose={onClose} width={560}>
      <h3 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Version history</h3>
      <p style={{ margin: '0 0 16px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        Every replacement keeps the file it displaced. Nothing here is ever overwritten.
      </p>

      {err && <div style={{ ...tinted('#ef4444', 0.1), padding: '9px 12px', borderRadius: 8, fontSize: 12, color: 'var(--text-h)', marginBottom: 12 }}>{err}</div>}

      {versions === null ? (
        <div style={{ color: 'var(--text-muted)', fontSize: 12.5 }}>Loading version history…</div>
      ) : versions.length === 0 ? (
        <div style={{ color: 'var(--text-muted)', fontSize: 12.5 }}>
          Only the current file exists — this document has not been replaced.
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12, maxHeight: 460, overflowY: 'auto' }}>
          {versions.map(v => (
            <div key={v.id} style={{ padding: 13, borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)' }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, marginBottom: 6 }}>
                <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Version {v.version_no}</span>
                <span style={{ fontSize: 11, fontWeight: 700, padding: '2px 8px', borderRadius: 6, background: 'rgba(124,58,237,0.14)', color: '#a78bfa' }}>
                  {v.status_at_capture || v.status || 'Archived'}
                </span>
              </div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 6 }}>
                File: <strong style={{ color: 'var(--text-h)' }}>{v.original_name}</strong>
              </div>
              <div style={{ fontSize: 11.5, color: 'var(--text-muted)', display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
                <span>Uploaded {v.created_at ? new Date(v.created_at).toLocaleString() : '—'}</span>
                <button type="button" onClick={() => download(v)} disabled={busy === v.id}
                  style={{ border: 'none', background: 'none', color: '#a78bfa', fontWeight: 700, cursor: 'pointer', textDecoration: 'underline', padding: 0, fontSize: 11.5 }}>
                  {busy === v.id ? 'Preparing…' : 'Download'}
                </button>
              </div>
              {v.remarks && (
                <div style={{ ...tinted('#ef4444', 0.1), marginTop: 7, padding: 8, borderRadius: 6, fontSize: 11.5, color: 'var(--text-h)' }}>
                  <strong>Remarks:</strong> {v.remarks}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </Overlay>
  )
}

/**
 * Hand the vendor off to somebody who can produce the document.
 *
 * Deliberately local: no request is sent anywhere yet, and the confirmation
 * says a partner "will contact you", which is what the vendor is told either
 * way. When a real referral endpoint exists this is the one place to wire it.
 */
function ProviderRequestModal({ provider, row, onboarding, user, onClose, onSubmitted }) {
  const p = onboarding?.profile || {}
  const v = onboarding?.vendor || {}
  const u = user || {}

  const [form, setForm] = useState({
    fullName: p.full_name || p.contact_person || v.vendor_name || v.company_name || u.name || '',
    email: p.email || p.contact_email || v.email || u.email || '',
    mobile: p.mobile || p.contact_mobile || v.phone || u.phone || '',
    company: p.company_name || v.company_name || '',
    notes: '',
  })
  const [done, setDone] = useState(false)
  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.value }))

  const submit = (e) => {
    e.preventDefault()
    onSubmitted({
      providerId: provider.id, providerName: provider.name,
      docType: row.type, docLabel: row.type_label,
      ...form, createdAt: new Date().toISOString(), status: 'Pending',
    })
    setDone(true)
  }

  return (
    <Overlay onClose={onClose} width={520} showClose={false}>
      <div style={{ margin: '-28px -28px 0', padding: '22px 24px 18px', background: provider.logoBg, borderTopLeftRadius: 16, borderTopRightRadius: 16, position: 'relative' }}>
        <button onClick={onClose} aria-label="Close"
          style={{ position: 'absolute', top: 14, right: 14, border: 'none', background: 'rgba(255,255,255,0.22)', color: '#fff', width: 28, height: 28, borderRadius: '50%', cursor: 'pointer', fontSize: 15, fontWeight: 700 }}>✕</button>
        <div style={{ display: 'flex', alignItems: 'center', gap: 13 }}>
          <div style={{ width: 46, height: 46, borderRadius: 12, background: 'rgba(255,255,255,0.22)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontWeight: 900, fontSize: 17 }}>{provider.logoText}</div>
          <div>
            <h3 style={{ margin: 0, fontSize: 17, fontWeight: 800, color: '#fff' }}>{provider.name}</h3>
            <p style={{ margin: '2px 0 0', fontSize: 12.5, color: 'rgba(255,255,255,0.92)' }}>{provider.desc}</p>
          </div>
        </div>
        <span style={{ display: 'inline-block', marginTop: 13, padding: '4px 10px', borderRadius: 20, background: 'rgba(255,255,255,0.25)', color: '#fff', fontSize: 11.5, fontWeight: 700 }}>
          {row.type_label}
        </span>
      </div>

      <div style={{ paddingTop: 20 }}>
        {done ? (
          <div style={{ ...tinted('#10b981', 0.1), padding: 20, borderRadius: 12, textAlign: 'center' }}>
            <CheckCircle size={32} style={{ color: '#10b981' }} />
            <h4 style={{ margin: '10px 0 6px', fontSize: 15.5, fontWeight: 800, color: 'var(--text-h)' }}>Request submitted</h4>
            <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-muted)', lineHeight: 1.6 }}>
              <strong style={{ color: 'var(--text-h)' }}>{provider.name}</strong> will contact you about your {row.type_label}.
              You can still upload the document yourself at any time.
            </p>
            <button onClick={onClose}
              style={{ marginTop: 16, padding: '9px 22px', borderRadius: 9, background: 'linear-gradient(135deg,#10b981,#059669)', color: '#fff', border: 'none', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>
              Close
            </button>
          </div>
        ) : (
          <form onSubmit={submit}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 12 }}>
              <Labelled label="Full name"><input required value={form.fullName} onChange={set('fullName')} style={fieldStyle} /></Labelled>
              <Labelled label="Company"><input value={form.company} onChange={set('company')} style={fieldStyle} /></Labelled>
              <Labelled label="Email"><input required type="email" value={form.email} onChange={set('email')} style={fieldStyle} /></Labelled>
              <Labelled label="Mobile"><input required value={form.mobile} onChange={set('mobile')} style={fieldStyle} /></Labelled>
            </div>
            <div style={{ marginTop: 12 }}>
              <Labelled label="Anything they should know (optional)">
                <textarea rows={3} value={form.notes} onChange={set('notes')} style={{ ...fieldStyle, resize: 'vertical', fontFamily: 'inherit' }} />
              </Labelled>
            </div>
            <div style={{ display: 'flex', gap: 10, marginTop: 18, justifyContent: 'flex-end' }}>
              <button type="button" onClick={onClose} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Cancel</button>
              <button type="submit" style={{ padding: '9px 22px', borderRadius: 9, background: provider.logoBg, color: '#fff', border: 'none', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Request a callback</button>
            </div>
          </form>
        )}
      </div>
    </Overlay>
  )
}

/* ── Small pieces ─────────────────────────────────────────────────────────── */

/**
 * A status tint that survives both themes.
 *
 * Falls back to the server's own `status_label`, so a status this build has not
 * been taught shows its real name instead of claiming nothing was uploaded —
 * the mistake that once labelled every freshly uploaded Purchase document
 * "Not Uploaded" and hid the admin's approve button behind the same wrong word.
 */
function statusCfg(status, label, uploaded) {
  const map = {
    Under_Review: { label: 'Under Review', color: '#f59e0b', bg: 'rgba(245,158,11,0.16)' },
    Approved: { label: 'Approved', color: '#10b981', bg: 'rgba(16,185,129,0.16)' },
    Rejected: { label: 'Rejected', color: '#ef4444', bg: 'rgba(239,68,68,0.16)' },
    Expired: { label: 'Expired', color: '#f97316', bg: 'rgba(249,115,22,0.16)' },
  }
  if (map[status]) return map[status]
  if (label && label !== 'Not Uploaded') return { label, color: '#94a3b8', bg: 'rgba(148,163,184,0.16)' }
  return uploaded
    ? { label: 'Uploaded', color: '#0ea5e9', bg: 'rgba(14,165,233,0.16)' }
    : { label: 'Not Uploaded', color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' }
}

/** An alpha wash over the themed surface — never an opaque light fill. */
const tinted = (color, alpha) => ({
  background: hexA(color, alpha),
  border: `1px solid ${hexA(color, 0.32)}`,
})

function hexA(hex, alpha) {
  const h = hex.replace('#', '')
  const n = parseInt(h.length === 3 ? h.split('').map(c => c + c).join('') : h, 16)
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`
}

const fmt = (d) => (d ? new Date(d).toLocaleString() : null)

const providerBadge = (tone) => (
  tone === 'orange' ? { background: 'rgba(249,115,22,0.14)', color: '#f97316', border: '1px solid rgba(249,115,22,0.34)' }
    : tone === 'green' ? { background: 'rgba(16,185,129,0.14)', color: '#10b981', border: '1px solid rgba(16,185,129,0.34)' }
      : { background: 'rgba(124,58,237,0.14)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.34)' }
)

const Stat = ({ label, value, color }) => (
  <div style={{ minWidth: 62, padding: '6px 10px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
    <div style={{ fontSize: 18, fontWeight: 900, color, lineHeight: 1.1 }}>{value}</div>
    <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 700, marginTop: 2 }}>{label}</div>
  </div>
)

const Banner = ({ tone, icon: Icon, children, onDismiss }) => (
  <div style={{ ...tinted(tone, 0.1), display: 'flex', alignItems: 'flex-start', gap: 9, padding: '11px 14px', borderRadius: 11, marginBottom: 13 }}>
    <Icon size={15} style={{ color: tone, flexShrink: 0, marginTop: 1 }} />
    <span style={{ fontSize: 12.5, color: 'var(--text-h)', flex: 1, lineHeight: 1.55 }}>{children}</span>
    {onDismiss && <button onClick={onDismiss} aria-label="Dismiss" style={{ ...closeX, fontSize: 14 }}>✕</button>}
  </div>
)

function MiniBtn({ onClick, color = '#7C3AED', icon: Icon, children, disabled, ghost, danger, title }) {
  const base = {
    display: 'inline-flex', alignItems: 'center', gap: 5, padding: children ? '6px 11px' : '6px 8px',
    borderRadius: 8, fontSize: 11.5, fontWeight: 700, cursor: disabled ? 'not-allowed' : 'pointer',
    opacity: disabled ? 0.6 : 1,
  }
  const skin = ghost
    ? { border: '1px solid var(--border)', background: 'var(--bg-input)', color: danger ? '#ef4444' : 'var(--text-muted)' }
    : { border: 'none', background: `linear-gradient(145deg, ${color}dd, ${color})`, color: '#fff' }
  return (
    <button type="button" onClick={onClick} disabled={disabled} title={title} style={{ ...base, ...skin }}>
      {Icon && <Icon size={12.5} />}{children}
    </button>
  )
}

const Labelled = ({ label, children }) => (
  <label style={{ display: 'block' }}>
    <span style={{ display: 'block', fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', marginBottom: 5, textTransform: 'uppercase', letterSpacing: '0.04em' }}>{label}</span>
    {children}
  </label>
)

const fieldStyle = {
  width: '100%', padding: '9px 11px', borderRadius: 9, border: '1px solid var(--border)',
  background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, outline: 'none',
}

const closeX = {
  border: 'none', background: 'none', cursor: 'pointer', fontSize: 17,
  color: 'var(--text-muted)', lineHeight: 1, padding: 0,
}
