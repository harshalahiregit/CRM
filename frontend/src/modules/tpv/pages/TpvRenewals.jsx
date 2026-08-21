import { useState, useEffect, useCallback } from 'react'
import { RefreshCcw, Plus, RefreshCw, X, Trash2, Gavel } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { useAuth } from '@/context/AuthContext'
import { canApproveTpv } from '../constants'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// Sangoe TPV §28 — Renewal & Extension. Assessed from performance (VRS) + open
// governance items (Rule 10), decided Renew / Renew-with-Conditions / Extend /
// Requalify / Replace / Suspend / Exit.
const STATUS_TONE = { Pending: '#94a3b8', Assessed: '#0ea5e9', Decided: '#10b981' }
const DECISION_TONE = {
  Renew: '#10b981', Renew_With_Conditions: '#22c55e', Extend: '#0ea5e9', Requalify: '#f59e0b',
  Replace: '#f97316', Suspend: '#ef4444', Exit: '#7f1d1d',
}
const BAND_TONE = { A: '#10b981', B: '#22c55e', C: '#f59e0b', D: '#f97316', E: '#ef4444' }
const fmt = (s) => String(s || '').replace(/_/g, ' ')
const date = (d) => (d ? new Date(d).toLocaleDateString() : '—')

export default function TpvRenewals() {
  const { user } = useAuth()
  const admin = canApproveTpv(user)
  const [rows, setRows] = useState(null)
  const [decisions, setDecisions] = useState([])
  const [vendors, setVendors] = useState([])
  const [initModal, setInitModal] = useState(false)
  const [decideModal, setDecideModal] = useState(null)

  const load = useCallback(() => {
    tpvApi.renewals.list().then(d => { setRows(d?.data ?? []); setDecisions(d?.decisions ?? []) }).catch(() => setRows([]))
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { tpvApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([])) }, [])

  const remove = async (id) => { if (window.confirm('Delete this renewal?')) { await tpvApi.renewals.delete(id); load() } }
  const reassess = async (id) => { await tpvApi.renewals.reassess(id); load() }

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>PERFORMANCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Renewal &amp; Extension</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Assessed from performance + open governance items — then renew, extend, requalify, replace or exit.</p>
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
                {['Reference', 'Vendor', 'Due', 'VRS', 'Open items', 'Status', 'Decision', ''].map(h => <th key={h} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {rows === null ? <tr><td colSpan={8} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={8} style={{ padding: 18, color: 'var(--text-muted)' }}>No renewals initiated.</td></tr>
                : rows.map(r => {
                  const a = r.assessment || {}
                  const openItems = (a.open_ncrs || 0) + (a.open_capas || 0) + (a.active_strikes || 0)
                  return (
                    <tr key={r.id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={{ padding: '10px 14px', fontWeight: 700, color: '#a78bfa' }}>{r.reference}</td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{r.vendor?.company_name || '—'}</td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{date(r.due_date)}</td>
                      <td style={{ padding: '10px 14px' }}>
                        {a.vrs_score != null
                          ? <span style={{ fontWeight: 800, color: 'var(--text-h)' }}>{a.vrs_score} <span style={{ color: BAND_TONE[a.vrs_band] || 'var(--text-muted)', fontSize: 11 }}>({a.vrs_band})</span></span>
                          : '—'}
                      </td>
                      <td style={{ padding: '10px 14px', color: openItems > 0 ? '#f59e0b' : 'var(--text-muted)', fontSize: 12 }}>
                        {openItems > 0 ? `${a.open_ncrs || 0} NCR · ${a.open_capas || 0} CAPA · ${a.active_strikes || 0} strike` : 'clear'}
                      </td>
                      <td style={{ padding: '10px 14px' }}><Pill tone={STATUS_TONE[r.status]} text={r.status} /></td>
                      <td style={{ padding: '10px 14px' }}>{r.decision ? <Pill tone={DECISION_TONE[r.decision]} text={fmt(r.decision)} /> : <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>—</span>}</td>
                      <td style={{ padding: '10px 14px', whiteSpace: 'nowrap' }}>
                        {r.status !== 'Decided' && admin && <button onClick={() => setDecideModal(r)} style={{ ...miniBtn, color: '#a78bfa' }}><Gavel size={13} /> Decide</button>}
                        {r.status !== 'Decided' && <button onClick={() => reassess(r.id)} style={iconBtn} title="Re-assess"><RefreshCcw size={14} /></button>}
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
      {decideModal && <DecideModal renewal={decideModal} decisions={decisions} onClose={() => setDecideModal(null)} onSaved={() => { setDecideModal(null); load() }} />}
    </div>
  )
}

function Pill({ tone, text }) {
  const c = tone || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${c}1f`, color: c, fontSize: 11, fontWeight: 700 }}>{text}</span>
}

function InitiateModal({ vendors, onClose, onSaved }) {
  const [vendorId, setVendorId] = useState('')
  const [dueDate, setDueDate] = useState('')
  const [assessment, setAssessment] = useState(null)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)

  useEffect(() => {
    if (!vendorId) { setAssessment(null); return }
    tpvApi.renewals.assess(vendorId).then(setAssessment).catch(() => setAssessment(null))
  }, [vendorId])

  const save = async () => {
    setSaving(true); setErr(null)
    try { await tpvApi.renewals.initiate({ vendor_id: vendorId, due_date: dueDate || null }); onSaved() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not initiate.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Initiate Renewal</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 10 }}>
          <label style={lbl}>Vendor *
            <select value={vendorId} onChange={e => setVendorId(e.target.value)} style={inp}>
              <option value="">Select vendor…</option>
              {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
            </select>
          </label>
          <label style={lbl}>Renewal due
            <input type="date" value={dueDate} onChange={e => setDueDate(e.target.value)} style={inp} />
          </label>
        </div>
        {assessment && (
          <div className="pr-glass" style={{ marginTop: 12, padding: 12, borderRadius: 10 }}>
            <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 8 }}>Assessment (Rule 10)</div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 8, fontSize: 12 }}>
              <Stat label="VRS" value={assessment.vrs_score != null ? `${assessment.vrs_score} (${assessment.vrs_band})` : '—'} />
              <Stat label="Open NCRs" value={assessment.open_ncrs ?? 0} />
              <Stat label="Open CAPAs" value={assessment.open_capas ?? 0} />
              <Stat label="Active strikes" value={assessment.active_strikes ?? 0} />
              <Stat label="Violation level" value={fmt(assessment.violation_level)} />
              <Stat label="Status" value={fmt(assessment.vendor_status)} />
            </div>
          </div>
        )}
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={save} disabled={saving || !vendorId} style={{ ...btnPrimary, opacity: (saving || !vendorId) ? 0.6 : 1 }}>{saving ? 'Saving…' : 'Initiate'}</button>
        </div>
      </div>
    </div>
  )
}

function Stat({ label, value }) {
  return (
    <div>
      <div style={{ color: 'var(--text-muted)', fontSize: 10.5 }}>{label}</div>
      <div style={{ color: 'var(--text-h)', fontWeight: 700 }}>{value}</div>
    </div>
  )
}

function DecideModal({ renewal, decisions, onClose, onSaved }) {
  const [form, setForm] = useState({ decision: decisions[0] || 'Renew', conditions: '', new_end_date: '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))
  const showEnd = ['Renew', 'Renew_With_Conditions', 'Extend'].includes(form.decision)

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = { decision: form.decision, conditions: form.conditions || null }
      if (showEnd && form.new_end_date) payload.new_end_date = form.new_end_date
      await tpvApi.renewals.decide(renewal.id, payload); onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not decide.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Decide {renewal.reference}</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 10 }}>
          <label style={lbl}>Decision *
            <select value={form.decision} onChange={set('decision')} style={inp}>
              {decisions.map(d => <option key={d} value={d}>{fmt(d)}</option>)}
            </select>
          </label>
          {showEnd && (
            <label style={lbl}>New end date
              <input type="date" value={form.new_end_date} onChange={set('new_end_date')} style={inp} />
            </label>
          )}
          <label style={{ ...lbl, gridColumn: '1 / -1' }}>Conditions / reason
            <textarea value={form.conditions} onChange={set('conditions')} rows={3} style={{ ...inp, resize: 'vertical' }} />
          </label>
        </div>
        <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '8px 0 0' }}>Extend/Renew with a contract pushes its end date. Suspend changes the vendor status.</p>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '8px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={save} disabled={saving} style={{ ...btnPrimary, opacity: saving ? 0.6 : 1 }}>{saving ? 'Saving…' : 'Record decision'}</button>
        </div>
      </div>
    </div>
  )
}

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#8b5cf6,#7C3AED)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700, marginRight: 4 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 560, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
