import { useState, useEffect, useCallback } from 'react'
import { RefreshCcw, Plus, RefreshCw, X, Trash2, Gavel } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import LoadError from '@/components/ui/LoadError'
import { useAuth } from '@/context/AuthContext'
import { KIT3D_STYLE as PURCHASE_STYLE } from '@/components/ui/kit3d'

// Purchase Renewal & Extension — the Purchase-side mirror of the TPV engine
// (parity). Assessed from the Performance Index + open governance items, then
// decided: Renew / Renew With Conditions / Extend / Requalify / Replace / Suspend / Exit.
const STATUS_TONE = { Pending: '#f59e0b', Assessed: '#0ea5e9', Decided: '#22c55e' }
const BAND_TONE = { A: '#22c55e', B: '#0ea5e9', C: '#f59e0b', D: '#f97316', E: '#ef4444' }
const fmt = (s) => String(s || '').replace(/_/g, ' ')
const date = (d) => (d ? new Date(d).toLocaleDateString() : '—')

export default function PurchaseRenewals() {
  const { user } = useAuth()
  const admin = user?.role === 'admin'
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [decisions, setDecisions] = useState([])
  const [vendors, setVendors] = useState([])
  const [initModal, setInitModal] = useState(false)
  const [decideRow, setDecideRow] = useState(null)

  const load = useCallback(() => {
    purchaseApi.renewals.list().then(d => { setLoadError(null); setRows(d?.data ?? []); setDecisions(d?.decisions ?? []) }).catch(e => { setRows([]); setLoadError(e) })
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { purchaseApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([])) }, [])

  const remove = async (id) => { if (window.confirm('Delete this renewal?')) { await purchaseApi.renewals.delete(id); load() } }
  const reassess = async (id) => { await purchaseApi.renewals.reassess(id); load() }

  return (
    <div style={{ padding: 4 }}>
      <style>{PURCHASE_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#38bdf8', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>PERFORMANCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Renewal &amp; Extension</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Assess from performance + open governance, then decide.</p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setInitModal(true)} style={btnPrimary}><Plus size={15} /> Initiate Renewal</button>
        </div>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Reference', 'Vendor', 'Due', 'Index', 'Open NCR/CAPA', 'Status', 'Decision', ''].map(h => <th key={h} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={8} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={8} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={8} style={{ padding: 18, color: 'var(--text-muted)' }}>No renewals.</td></tr>
                : rows.map(r => {
                  const a = r.assessment || {}
                  return (
                    <tr key={r.id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={{ padding: '10px 14px', fontWeight: 700, color: '#38bdf8' }}>{r.reference}</td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{r.vendor?.company_name || '—'}</td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{date(r.due_date)}</td>
                      <td style={{ padding: '10px 14px' }}>
                        {a.vpi_score != null ? <span><b style={{ color: BAND_TONE[a.vpi_band] || 'var(--text-h)' }}>{a.vpi_score}</b> <span style={{ fontSize: 11, color: BAND_TONE[a.vpi_band] }}>{a.vpi_band}</span></span> : '—'}
                      </td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{a.open_ncrs ?? 0} / {a.open_capas ?? 0}{a.violation_points ? ` · ${a.violation_points}pts` : ''}</td>
                      <td style={{ padding: '10px 14px' }}><Pill tone={STATUS_TONE[r.status]} text={r.status} /></td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{r.decision ? fmt(r.decision) : '—'}</td>
                      <td style={{ padding: '10px 14px', whiteSpace: 'nowrap' }}>
                        {r.status !== 'Decided' && <button onClick={() => reassess(r.id)} style={miniBtn} title="Re-assess"><RefreshCcw size={13} /></button>}
                        {admin && r.status !== 'Decided' && <button onClick={() => setDecideRow(r)} style={{ ...miniBtn, color: '#22c55e' }}><Gavel size={13} /> Decide</button>}
                        <button onClick={() => remove(r.id)} style={iconBtn}><Trash2 size={14} /></button>
                      </td>
                    </tr>
                  )
                })}
            </tbody>
          </table>
        </div>
      </div>

      {initModal && <InitiateModal vendors={vendors} onClose={() => setInitModal(false)} onSaved={() => { setInitModal(false); load() }} />}
      {decideRow && <DecideModal row={decideRow} decisions={decisions} onClose={() => setDecideRow(null)} onSaved={() => { setDecideRow(null); load() }} />}
    </div>
  )
}

function Pill({ tone, text }) {
  const c = tone || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${c}1f`, color: c, fontSize: 11, fontWeight: 700 }}>{text}</span>
}

function InitiateModal({ vendors, onClose, onSaved }) {
  const [form, setForm] = useState({ purchase_vendor_id: '', due_date: '', notes: '' })
  const [preview, setPreview] = useState(null)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))

  useEffect(() => {
    if (!form.purchase_vendor_id) { setPreview(null); return }
    purchaseApi.renewals.assess(form.purchase_vendor_id).then(setPreview).catch(() => setPreview(null))
  }, [form.purchase_vendor_id])

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
      await purchaseApi.renewals.initiate(payload); onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not save.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Initiate Renewal</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <label style={lbl}>Vendor *
          <select value={form.purchase_vendor_id} onChange={set('purchase_vendor_id')} style={inp}>
            <option value="">Select vendor…</option>
            {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
          </select>
        </label>
        {preview && (
          <div style={{ margin: '10px 0', padding: 12, borderRadius: 10, background: 'var(--bg-card)', border: '1px solid var(--border)', fontSize: 12.5, color: 'var(--text-muted)' }}>
            <b style={{ color: 'var(--text-h)' }}>Assessment</b> · Index {preview.vpi_score ?? '—'} ({preview.vpi_band ?? '—'}) · Open NCR {preview.open_ncrs ?? 0} · Open CAPA {preview.open_capas ?? 0} · Violation {preview.violation_points ?? 0}pts ({fmt(preview.violation_level)})
          </div>
        )}
        <label style={{ ...lbl, marginTop: 10 }}>Due date
          <input type="date" value={form.due_date} onChange={set('due_date')} style={inp} />
        </label>
        <label style={{ ...lbl, marginTop: 10 }}>Notes
          <textarea value={form.notes} onChange={set('notes')} rows={2} style={{ ...inp, resize: 'vertical' }} />
        </label>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={save} disabled={saving || !form.purchase_vendor_id} style={{ ...btnPrimary, opacity: (saving || !form.purchase_vendor_id) ? 0.6 : 1 }}>{saving ? 'Saving…' : 'Assess & Initiate'}</button>
        </div>
      </div>
    </div>
  )
}

function DecideModal({ row, decisions, onClose, onSaved }) {
  const [form, setForm] = useState({ decision: decisions[0] || 'Renew', conditions: '', new_end_date: '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))
  const needsDate = ['Renew', 'Renew_With_Conditions', 'Extend'].includes(form.decision)

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
      await purchaseApi.renewals.decide(row.id, payload); onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not save.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Decide {row.reference}</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <label style={lbl}>Decision *
          <select value={form.decision} onChange={set('decision')} style={inp}>
            {(decisions.length ? decisions : ['Renew']).map(d => <option key={d} value={d}>{fmt(d)}</option>)}
          </select>
        </label>
        {needsDate && (
          <label style={{ ...lbl, marginTop: 10 }}>New end date (pushes the linked contract)
            <input type="date" value={form.new_end_date} onChange={set('new_end_date')} style={inp} />
          </label>
        )}
        <label style={{ ...lbl, marginTop: 10 }}>Conditions / reason
          <textarea value={form.conditions} onChange={set('conditions')} rows={3} style={{ ...inp, resize: 'vertical' }} />
        </label>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={save} disabled={saving} style={{ ...btnPrimary, opacity: saving ? 0.6 : 1 }}>{saving ? 'Saving…' : 'Record Decision'}</button>
        </div>
      </div>
    </div>
  )
}

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#0ea5e9,#0284c7)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#38bdf8', cursor: 'pointer', fontSize: 11.5, fontWeight: 700, marginRight: 4 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 520, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
