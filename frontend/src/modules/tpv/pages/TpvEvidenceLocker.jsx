import { useState, useEffect, useCallback } from 'react'
import { FolderLock, Plus, RefreshCw, X, Loader2, ExternalLink, Trash2 } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'

const CATEGORIES = ['Insurance', 'License', 'Certificate', 'Audit', 'Training', 'Policy', 'Other']
const statusColor = (s) => ({ Valid: '#10b981', Expiring: '#f59e0b', Expired: '#ef4444', Open: '#94a3b8' }[s] || '#94a3b8')

/**
 * Evidence Locker (Doc 6). A central register of compliance evidence — insurance,
 * licences, certificates, audits, training — each with a validity window so the
 * locker surfaces what is expiring or lapsed at a glance.
 */
export default function TpvEvidenceLocker() {
  const [data, setData] = useState({ data: [], summary: {} })
  const [loading, setLoading] = useState(true)
  const [adding, setAdding] = useState(false)
  const [cat, setCat] = useState('')

  const load = useCallback(() => {
    setLoading(true)
    tpvApi.evidence.list(cat ? { category: cat } : {}).then(d => setData(d || { data: [], summary: {} })).catch(() => setData({ data: [], summary: {} })).finally(() => setLoading(false))
  }, [cat])
  useEffect(() => { load() }, [load])

  const remove = async (e) => { if (!confirm(`Remove "${e.title}" from the locker?`)) return; try { await tpvApi.evidence.remove(e.id); load() } catch { /* ignore */ } }

  const rows = data.data || []
  const s = data.summary || {}

  return (
    <div style={{ padding: 24, maxWidth: 1050, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <FolderLock size={22} style={{ color: '#7C3AED' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>Evidence Locker</h1>
            <p style={{ margin: '2px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>
              {s.total || 0} item(s){s.expiring ? ` · ${s.expiring} expiring` : ''}{s.expired ? ` · ${s.expired} expired` : ''}
            </p>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <select value={cat} onChange={e => setCat(e.target.value)} style={{ ...input, width: 'auto' }}><option value="">All categories</option>{CATEGORIES.map(c => <option key={c} value={c}>{c}</option>)}</select>
          <button onClick={load} style={btn}><RefreshCw size={14} /></button>
          <button onClick={() => setAdding(true)} style={primary}><Plus size={15} /> Add Evidence</button>
        </div>
      </div>

      {loading ? <div style={{ textAlign: 'center', padding: 48, color: 'var(--text-muted)' }}><Loader2 size={20} className="rfq-spin" /></div>
        : rows.length === 0 ? <div style={{ textAlign: 'center', padding: 48, color: 'var(--text-muted)' }}>No evidence recorded.</div>
        : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
            {rows.map(e => (
              <div key={e.id} style={row}>
                <span style={{ padding: '2px 8px', borderRadius: 6, background: 'rgba(124,58,237,0.12)', color: '#a78bfa', fontSize: 10.5, fontWeight: 700, flexShrink: 0 }}>{e.category}</span>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 6 }}>
                    {e.title}
                    {e.file_url && <a href={e.file_url} target="_blank" rel="noopener noreferrer" style={{ color: '#818cf8', display: 'inline-flex' }}><ExternalLink size={13} /></a>}
                  </div>
                  <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{e.vendor?.company_name || 'General'}{e.valid_until ? ` · valid to ${e.valid_until}` : ' · no expiry'}</div>
                </div>
                <Badge text={e.status} color={statusColor(e.status)} />
                <button onClick={() => remove(e)} title="Remove" style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', display: 'flex' }}><Trash2 size={15} /></button>
              </div>
            ))}
          </div>
        )}

      {adding && <AddModal onClose={() => setAdding(false)} onSaved={() => { setAdding(false); load() }} />}
    </div>
  )
}

function AddModal({ onClose, onSaved }) {
  const [vendors, setVendors] = useState([])
  const [f, setF] = useState({ category: 'Insurance', title: '', vendor_id: '', description: '', file_url: '', valid_from: '', valid_until: '' })
  const [busy, setBusy] = useState(false); const [err, setErr] = useState('')
  useEffect(() => { tpvApi.vendors.list().then(r => setVendors(Array.isArray(r?.data ?? r) ? (r.data ?? r) : [])).catch(() => {}) }, [])
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))
  const save = async () => {
    if (!f.title.trim()) { setErr('A title is required.'); return }
    setBusy(true); setErr('')
    try { await tpvApi.evidence.create({ ...f, vendor_id: f.vendor_id || null, file_url: f.file_url || null, valid_from: f.valid_from || null, valid_until: f.valid_until || null }); onSaved() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not save.') }
    finally { setBusy(false) }
  }
  return (
    <Overlay onClose={onClose} title="Add Evidence">
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Category"><select value={f.category} onChange={set('category')} style={input}>{CATEGORIES.map(c => <option key={c} value={c}>{c}</option>)}</select></Field>
        <Field label="Vendor"><select value={f.vendor_id} onChange={set('vendor_id')} style={input}><option value="">— General —</option>{vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}</select></Field>
        <Field label="Title *" full><input value={f.title} onChange={set('title')} style={input} /></Field>
        <Field label="File URL" full><input value={f.file_url} onChange={set('file_url')} style={input} placeholder="https://…" /></Field>
        <Field label="Valid from"><input type="date" value={f.valid_from} onChange={set('valid_from')} style={input} /></Field>
        <Field label="Valid until"><input type="date" value={f.valid_until} onChange={set('valid_until')} style={input} /></Field>
        <Field label="Description" full><textarea value={f.description} onChange={set('description')} rows={2} style={{ ...input, resize: 'vertical' }} /></Field>
      </div>
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}>
        <button onClick={onClose} style={btn}>Cancel</button>
        <button onClick={save} disabled={busy} style={primary}>{busy ? <Loader2 size={14} className="rfq-spin" /> : null} Save</button>
      </div>
    </Overlay>
  )
}

const input = { width: '100%', padding: '8px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13 }
const row = { display: 'flex', alignItems: 'center', gap: 12, padding: '12px 14px', borderRadius: 11, background: 'var(--bg-card)', border: '1px solid var(--border)' }
const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }
const primary = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }
function Badge({ text, color }) { return <span style={{ padding: '3px 10px', borderRadius: 999, background: `${color}22`, color, fontSize: 11, fontWeight: 700, whiteSpace: 'nowrap' }}>{text}</span> }
function Field({ label, children, full }) { return <label style={{ display: 'block', gridColumn: full ? '1/-1' : undefined }}><span style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 4 }}>{label}</span>{children}</label> }
function Overlay({ children, onClose, title }) {
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '8vh 16px 16px', backdropFilter: 'blur(2px)', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16, width: '100%', maxWidth: 560, boxShadow: '0 20px 60px rgba(0,0,0,0.4)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px', borderBottom: '1px solid var(--border)' }}>
          <FolderLock size={18} style={{ color: '#7C3AED' }} />
          <h2 style={{ flex: 1, margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h2>
          <button onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', display: 'flex' }}><X size={18} /></button>
        </div>
        <div style={{ padding: 20 }}>{children}</div>
      </div>
    </div>
  )
}
