import { useState, useEffect, useCallback, useMemo } from 'react'
import {
  FolderLock, Plus, RefreshCw, Loader2, ExternalLink, Trash2, Pencil, Info,
} from 'lucide-react'
import api from '@/lib/api'
import LoadError from '@/components/ui/LoadError'
import { Empty } from './PurchaseGateLog'
import {
  KIT3D_STYLE, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, StatusBadge,
} from '@/components/ui/kit3d'

/**
 * The Evidence Locker — the site's central compliance-evidence register.
 *
 * Insurance, licences, certificates, audits, training and policies, each with a
 * validity window, so what has lapsed or is about to is visible at a glance
 * rather than discovered at a gate.
 *
 * IMPORTANT — this register is SHARED, not a Purchase copy. /purchase/evidence
 * is an alias onto the same controller and the same `compliance_evidence` table
 * TPV reads: the table carries no `tpv_` prefix because it is scoped by TENANT,
 * not by the module you happened to be standing in. A certificate filed here is
 * the same certificate TPV sees, and one filed from TPV shows up here. Giving
 * Purchase its own copy would split one site's compliance record into two halves
 * that each looked complete, which is the failure this screen exists to prevent.
 * The route gate (role:admin,staff) is identical on both aliases, so nothing is
 * reachable here that was not already reachable from TPV.
 */

// purchaseApi has no evidence namespace — these are the shared-register aliases
// under /purchase, written in exactly the shape it uses
// (`api.<verb>(…).then(r => r.data)`) so they lift into services/purchaseApi.js
// unchanged the moment that namespace lands.
const evidenceApi = {
  list:   (params = {}) => api.get('/purchase/evidence', { params }).then(r => r.data),
  create: (data)        => api.post('/purchase/evidence', data).then(r => r.data),
  update: (id, data)    => api.patch(`/purchase/evidence/${id}`, data).then(r => r.data),
  remove: (id)          => api.delete(`/purchase/evidence/${id}`).then(r => r.data),
}

// ComplianceEvidence::CATEGORIES — the exact strings Rule::in() validates
// against on both store and update. Anything else is a 422.
const CATEGORIES = ['Insurance', 'License', 'Certificate', 'Audit', 'Training', 'Policy', 'Other']

// `status` is an APPENDED accessor on the model, not a column: Open when there
// is no expiry, Expired once valid_until is past, Expiring inside 30 days,
// Valid otherwise. That is why there is no ?status= server filter — see below.
const STATUS_CONFIG = {
  Valid:    { label: 'Valid',     color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Expiring: { label: 'Expiring',  color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Expired:  { label: 'Expired',   color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  Open:     { label: 'No expiry', color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
}
const statusCfg = (s) => STATUS_CONFIG[s] || STATUS_CONFIG.Open

// `rfq-spin` is NOT defined globally — PurchaseRfqDetail injects its own copy,
// so a page relying on that class renders a spinner that does not spin. This one
// brings its own keyframes rather than inherit a dead class.
const SPIN_STYLE = '@keyframes prEvSpin{to{transform:rotate(360deg)}}.pr-ev-spin{animation:prEvSpin .9s linear infinite}'

/**
 * A stored date, read as the day it actually is.
 *
 * valid_from / valid_until are DATE columns, but the model casts them as dates
 * and Laravel serialises those as full ISO instants ("2026-03-01T00:00:00Z").
 * Feeding that to `new Date()` and formatting it in local time shifts the day by
 * the UTC offset — on a compliance expiry that is the difference between
 * "expired" and "expires tomorrow" — so only the YYYY-MM-DD head is ever read,
 * and it is formatted from its own parts rather than through a timezone.
 */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const dayPart = (d) => (d ? String(d).slice(0, 10) : '')
const fmtDay = (d) => {
  const p = dayPart(d).split('-')
  return p.length === 3 && p[0] ? `${p[2]} ${MONTHS[Number(p[1]) - 1] || '?'} ${p[0]}` : '—'
}

// What the server actually said, rather than "Action failed".
const apiError = (e, fallback) => {
  const errors = e?.response?.data?.errors
  if (errors) return Object.values(errors).flat().join(' ')
  return e?.response?.data?.message || fallback
}

export default function PurchaseEvidenceLocker() {
  const [rows, setRows]         = useState([])
  const [summary, setSummary]   = useState({})
  const [loading, setLoading]   = useState(true)
  const [loadError, setError]   = useState(null)
  const [category, setCategory] = useState('')
  // Status is filtered in the browser because it CANNOT be filtered at the
  // server: it is derived from valid_until on read, so there is no column to
  // query and the controller offers no ?status=. Only ?category= and ?vendor_id=
  // reach the database.
  const [status, setStatus]     = useState('')
  const [editing, setEditing]   = useState(null)   // an evidence row, 'new', or null
  const [removing, setRemoving] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      // A blank category is "no filter", not a category whose name is the empty
      // string, so it is dropped rather than posted.
      const d = await evidenceApi.list(category ? { category } : {})
      setRows(d?.data ?? [])
      setSummary(d?.summary ?? {})
      setError(null)
    } catch (e) {
      setRows([]); setSummary({}); setError(e)
    } finally { setLoading(false) }
  }, [category])
  useEffect(() => { load() }, [load])

  // Valid and No-expiry are not in the server's summary, so they are counted
  // here. That is safe rather than a second source of truth: the controller
  // builds its summary from the SAME category-filtered collection it returns, so
  // every figure in the strip describes exactly the rows underneath it.
  const counts = useMemo(() => {
    const c = { Valid: 0, Expiring: 0, Expired: 0, Open: 0 }
    for (const r of rows) if (c[r.status] != null) c[r.status]++
    return c
  }, [rows])

  const visible = useMemo(
    () => (status ? rows.filter(r => r.status === status) : rows),
    [rows, status],
  )

  const statCards = [
    { label: 'Total',     value: summary.total ?? rows.length,        color: '#7C3AED', status: '' },
    { label: 'Valid',     value: counts.Valid,                        color: '#10b981', status: 'Valid' },
    { label: 'Expiring',  value: summary.expiring ?? counts.Expiring, color: '#f59e0b', status: 'Expiring' },
    { label: 'Expired',   value: summary.expired ?? counts.Expired,   color: '#ef4444', status: 'Expired' },
    { label: 'No expiry', value: counts.Open,                         color: '#94a3b8', status: 'Open' },
  ]

  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}{SPIN_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <FolderLock size={22} style={{ color: '#7C3AED' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Evidence Locker</h1>
            <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
              Insurance, licences, certificates, audits and policies — with the date each one runs out.
            </p>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /></button>
          <button onClick={() => setEditing('new')} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}>
            <Plus size={15} /> Add Evidence
          </button>
        </div>
      </div>

      {/* The one thing a user standing in Purchase must not get wrong about this
          screen: it is the site's locker, not the module's. */}
      <div className="pr-glass" style={{ display: 'flex', alignItems: 'flex-start', gap: 10, padding: '12px 16px', marginBottom: 18, borderRadius: 14 }}>
        <Info size={15} style={{ color: '#a78bfa', flexShrink: 0, marginTop: 1 }} />
        <p style={{ margin: 0, fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.6 }}>
          This is the <strong style={{ color: 'var(--text-h)' }}>site-wide</strong> compliance locker, shared with TPV — one
          register per tenant, not one per module. Anything filed here is visible to TPV, and TPV&rsquo;s filings are visible
          here. Removing an item removes it for everyone.
        </p>
      </div>

      {/* Status strip. Every figure is over the category-filtered set the server
          returned, so the counts and the rows can never disagree. */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(5,1fr)', gap: 10, marginBottom: 18 }}>
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" onClick={() => setStatus(s.status)}
            style={{
              textAlign: 'center',
              border: status === s.status && s.status ? `1.5px solid ${s.color}` : '1px solid var(--border)',
              background: status === s.status && s.status ? `${s.color}15` : undefined,
            }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: s.color, lineHeight: 1 }}>{s.value ?? 0}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap', borderRadius: 14 }}>
        <select value={category} onChange={e => setCategory(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="">All categories</option>
          {CATEGORIES.map(c => <option key={c} value={c}>{c}</option>)}
        </select>
        <select value={status} onChange={e => setStatus(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="">All statuses</option>
          {Object.keys(STATUS_CONFIG).map(s => <option key={s} value={s}>{STATUS_CONFIG[s].label}</option>)}
        </select>
        <span style={{ marginLeft: 'auto', fontSize: 11.5, color: 'var(--text-muted)' }}>
          {/* Said plainly whenever a client-side status filter is narrowing the
              server's set, so the strip above never looks like it is lying. */}
          {status
            ? `${visible.length} of ${rows.length} item${rows.length === 1 ? '' : 's'}`
            : `${rows.length} item${rows.length === 1 ? '' : 's'}`}
        </span>
      </div>

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load the evidence locker" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}><Loader2 size={18} className="pr-ev-spin" /> Loading evidence…</div>
      ) : visible.length === 0 ? (
        <Empty icon={FolderLock}
          title={rows.length ? 'Nothing in this view' : 'The locker is empty'}
          hint={rows.length
            ? 'No item in this category carries that status — clear the filters to see the rest.'
            : 'File the certificates, licences and policies the site is asked to produce, each with the date it runs out.'} />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Item', 'Category', 'Held against', 'Valid from', 'Valid until', 'Status', ''].map((h, i) => <th key={i} style={th}>{h}</th>)}</tr></thead>
              <tbody>
                {/* The server orders by expiry — soonest first, no-expiry last —
                    so the row that needs attention is already at the top. Nothing
                    is re-sorted here. */}
                {visible.map(e => (
                  <tr key={e.id} className="pr-li-row">
                    <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                        {e.title}
                        {e.file_url && (
                          <a href={e.file_url} target="_blank" rel="noopener noreferrer" title="Open the document"
                            style={{ color: '#818cf8', display: 'inline-flex' }}>
                            <ExternalLink size={13} />
                          </a>
                        )}
                      </span>
                      {e.description && <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500, maxWidth: 420 }}>{e.description}</div>}
                    </td>
                    <td style={td}>
                      <span style={{ padding: '2px 9px', borderRadius: 6, background: 'rgba(124,58,237,0.12)', color: '#a78bfa', fontSize: 10.5, fontWeight: 700, whiteSpace: 'nowrap' }}>{e.category}</span>
                    </td>
                    {/* A vendor here is a row of the shared `vendors` register —
                        the one TPV onboards into. Purchase's own vendors live in
                        purchase_vendors and are a different numbering entirely,
                        so this column only ever reports what the server sent. */}
                    <td style={{ ...td, color: 'var(--text-muted)' }}>{e.vendor?.company_name || 'The site'}</td>
                    <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{fmtDay(e.valid_from)}</td>
                    <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{e.valid_until ? fmtDay(e.valid_until) : '—'}</td>
                    <td style={td}><StatusBadge cfg={statusCfg(e.status)} /></td>
                    <td style={{ ...td, whiteSpace: 'nowrap' }}>
                      <button onClick={() => setEditing(e)} title="Edit" style={iconBtn}><Pencil size={14} /></button>
                      <button onClick={() => setRemoving(e)} title="Remove" style={{ ...iconBtn, color: '#ef4444' }}><Trash2 size={14} /></button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {editing && (
        <EvidenceModal row={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); load() }} />
      )}
      {removing && (
        <RemoveModal row={removing}
          onClose={() => setRemoving(null)}
          onRemoved={() => { setRemoving(null); load() }} />
      )}
    </div>
  )
}

/* ── File a new item, or correct one already filed ────────────────────────── */
function EvidenceModal({ row, onClose, onSaved }) {
  const isEdit = !!row
  const [f, setF] = useState({
    category:    row?.category || 'Insurance',
    title:       row?.title || '',
    description: row?.description || '',
    file_url:    row?.file_url || '',
    // <input type="date"> takes YYYY-MM-DD only; the ISO instant the API sends
    // back would be rejected outright and render as a blank field.
    valid_from:  dayPart(row?.valid_from),
    valid_until: dayPart(row?.valid_until),
  })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    // Mirrored from the controller's rules so the common mistakes are caught
    // before a round trip; the server still validates authoritatively.
    if (!f.title.trim()) { setErr('A title is required — an item nobody can name is not evidence.'); return }
    if (f.title.length > 200) { setErr('The title is limited to 200 characters.'); return }
    // store() enforces after_or_equal:valid_from; update() does NOT, so a
    // back-to-front window would be accepted on an edit and the item would read
    // as permanently expired. Refused here on both paths.
    if (f.valid_from && f.valid_until && f.valid_until < f.valid_from) {
      setErr('The validity cannot end before it starts.'); return
    }
    if (f.file_url && !/^https?:\/\//i.test(f.file_url.trim())) {
      setErr('The file link must be a full URL beginning http:// or https:// — the server rejects anything else.'); return
    }

    setBusy(true); setErr('')
    try {
      const payload = {
        category:    f.category,
        title:       f.title.trim(),
        // Empty strings are sent as null rather than '': the rules are nullable,
        // and clearing an expiry has to actually clear it.
        description: f.description.trim() || null,
        file_url:    f.file_url.trim() || null,
        valid_from:  f.valid_from || null,
        valid_until: f.valid_until || null,
      }
      // vendor_id is deliberately never sent. It points at the shared `vendors`
      // register, which Purchase does not maintain — sending a purchase_vendors
      // id would silently file this against an unrelated company, and the
      // controller's rule is a bare `nullable|integer` that would not catch it.
      // Omitting the key leaves an existing TPV-set link untouched on an edit,
      // where sending null would quietly detach it.
      if (isEdit) await evidenceApi.update(row.id, payload)
      else await evidenceApi.create({ ...payload, vendor_id: null })
      onSaved()
    } catch (e) { setErr(apiError(e, 'Could not save this item.')) }
    finally { setBusy(false) }
  }

  return (
    // closeOnBackdrop is left off: this form loses typed data on a stray outside
    // click, so it closes only via the ✕ or Cancel.
    <Overlay onClose={() => !busy && onClose()} width={620}>
      <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        <FolderLock size={18} style={{ color: '#7C3AED' }} /> {isEdit ? 'Edit Evidence' : 'Add Evidence'}
      </h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        {isEdit
          ? 'Correcting an item corrects it everywhere — TPV reads the same record.'
          : 'Filed against the site, and visible to TPV as well as Purchase.'}
      </p>

      {isEdit && row.vendor?.company_name && (
        <InfoBox>
          This item is held against <strong>{row.vendor.company_name}</strong> in the shared vendor register. That link is
          left exactly as it is — it is not editable from Purchase, which numbers its vendors separately.
        </InfoBox>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Title *" full>
          <TextInput value={f.title} onChange={set('title')} maxLength={200} placeholder="e.g. Public liability insurance 2026" />
        </Field>
        <Field label="Category *">
          <SelectInput value={f.category} onChange={set('category')} options={CATEGORIES} />
        </Field>
        <Field label="File link">
          <TextInput value={f.file_url} onChange={set('file_url')} maxLength={1000} placeholder="https://…" />
        </Field>
        {/* Both are DATE columns; a time typed here would be discarded on save. */}
        <Field label="Valid from"><TextInput type="date" value={f.valid_from} onChange={set('valid_from')} /></Field>
        <Field label="Valid until"><TextInput type="date" value={f.valid_until} onChange={set('valid_until')} min={f.valid_from || undefined} /></Field>
        <Field label="Description" full>
          <textarea value={f.description} onChange={set('description')} rows={2} style={{ ...inputStyle, resize: 'vertical' }}
            placeholder="What this covers, and anything the site should know when it is produced." />
        </Field>
      </div>

      {/* An item with no expiry is filed as Open forever — worth saying, because
          most of what belongs in a locker does in fact run out. */}
      {!f.valid_until && (
        <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '12px 0 0' }}>
          With no <em>valid until</em> date this item never expires and never appears on the renewal watch.
        </p>
      )}

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
      <ModalFooter onClose={onClose} onConfirm={save} loading={busy}
        disabled={!f.title.trim()} confirmLabel={isEdit ? 'Save Changes' : 'File Evidence'} />
    </Overlay>
  )
}

/* ── Removal, with the consequence named ──────────────────────────────────── */
function RemoveModal({ row, onClose, onRemoved }) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const go = async () => {
    setBusy(true); setErr('')
    try { await evidenceApi.remove(row.id); onRemoved() }
    catch (e) { setErr(apiError(e, 'Could not remove this item.')); setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={460}>
      <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-h)', margin: '0 0 10px', fontSize: 17, fontWeight: 800 }}>
        <Trash2 size={17} style={{ color: '#ef4444' }} /> Remove from the locker
      </h2>
      {/* The delete is a hard one — no soft deletes on ComplianceEvidence — and
          the register is shared, so this is spelled out rather than assumed. */}
      <InfoBox tone="danger">
        <strong>{row.title}</strong> will be deleted outright, for TPV as well as Purchase. There is no undo and no
        archive to recover it from.
      </InfoBox>
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '0 0 10px' }}>{err}</p>}
      <ModalFooter onClose={onClose} onConfirm={go} loading={busy} confirmLabel="Remove" color="#ef4444" />
    </Overlay>
  )
}

/* ── shared bits ── */
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const iconBtn = { background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', padding: '4px 6px', display: 'inline-flex', alignItems: 'center' }
