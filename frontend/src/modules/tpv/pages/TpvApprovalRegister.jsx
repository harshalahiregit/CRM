import { useState, useEffect, useCallback } from 'react'
import { ShieldCheck, Plus, RefreshCw, X, CheckCircle, XCircle, Ban } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import LoadError from '@/components/ui/LoadError'
import { useAuth } from '@/context/AuthContext'
import { canApproveTpv } from '../constants'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// Sangoe TPV §12 — central Approval register. Generic across the ~18 approval
// types; distinct from the onboarding-approval chain on /app/tpv/approvals.
const STATUS_TONE = { Pending: '#f59e0b', Approved: '#10b981', Rejected: '#ef4444', Cancelled: '#6b7280' }
const PRIORITY_TONE = { Low: '#94a3b8', Medium: '#0ea5e9', High: '#f59e0b', Urgent: '#ef4444' }
const PRIORITIES = ['Low', 'Medium', 'High', 'Urgent']

export default function TpvApprovalRegister() {
  const { user } = useAuth()
  const admin = canApproveTpv(user)
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [types, setTypes] = useState([])
  const [vendors, setVendors] = useState([])
  const [statusF, setStatusF] = useState('')
  const [raising, setRaising] = useState(false)

  const load = useCallback(() => {
    tpvApi.approvalRegister.list(statusF ? { status: statusF } : {})
      .then(d => { setLoadError(null); setRows(d?.data ?? []); if (d?.types) setTypes(d.types) })
      .catch(e => { setRows([]); setLoadError(e) })
  }, [statusF])
  useEffect(() => { load() }, [load])
  useEffect(() => { tpvApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([])) }, [])

  const decide = async (row, decision) => {
    let remarks = null
    if (decision === 'reject') { remarks = window.prompt('Reason for rejection?'); if (!remarks) return }
    await tpvApi.approvalRegister.decide(row.id, { decision, remarks })
    load()
  }

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>MOBILISATION</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Approval Register</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Every TPV approval in one queue — the onboarding chain lives under Approvals.</p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <select value={statusF} onChange={e => setStatusF(e.target.value)} style={inp}>
            <option value="">All statuses</option>
            {['Pending', 'Approved', 'Rejected', 'Cancelled'].map(s => <option key={s} value={s}>{s}</option>)}
          </select>
          <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setRaising(true)} style={btnPrimary}><Plus size={15} /> Raise</button>
        </div>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Reference', 'Type', 'Title', 'Vendor', 'Priority', 'Status', 'Raised by', ''].map(h => <th key={h} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={8} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={8} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={8} style={{ padding: 18, color: 'var(--text-muted)' }}>No approval requests.</td></tr>
                : rows.map(r => (
                  <tr key={r.id} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '10px 14px', fontWeight: 700, color: '#a78bfa' }}>{r.reference}</td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{r.type_label}</td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{r.title}</td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{r.vendor?.company_name || '—'}</td>
                    <td style={{ padding: '10px 14px' }}><Pill tone={PRIORITY_TONE[r.priority]} text={r.priority} /></td>
                    <td style={{ padding: '10px 14px' }}><Pill tone={STATUS_TONE[r.status]} text={r.status} /></td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{r.requester?.name || '—'}</td>
                    <td style={{ padding: '10px 14px', whiteSpace: 'nowrap' }}>
                      {r.status === 'Pending' && admin && (
                        <>
                          <button onClick={() => decide(r, 'approve')} style={{ ...iconBtn, color: '#10b981' }} title="Approve"><CheckCircle size={16} /></button>
                          <button onClick={() => decide(r, 'reject')} style={{ ...iconBtn, color: '#ef4444' }} title="Reject"><XCircle size={16} /></button>
                          <button onClick={() => decide(r, 'cancel')} style={{ ...iconBtn, color: 'var(--text-muted)' }} title="Cancel"><Ban size={15} /></button>
                        </>
                      )}
                      {r.status !== 'Pending' && r.decision_remarks && (
                        <span style={{ fontSize: 11, color: 'var(--text-muted)' }} title={r.decision_remarks}>note</span>
                      )}
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </div>

      {raising && <RaiseModal types={types} vendors={vendors} onClose={() => setRaising(false)} onSaved={() => { setRaising(false); load() }} />}
    </div>
  )
}

function Pill({ tone, text }) {
  const c = tone || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${c}1f`, color: c, fontSize: 11, fontWeight: 700 }}>{text}</span>
}

function RaiseModal({ types, vendors, onClose, onSaved }) {
  const [form, setForm] = useState({ approval_type: types[0]?.value || 'other', title: '', vendor_id: '', priority: 'Medium', description: '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
      await tpvApi.approvalRegister.raise(payload); onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not raise.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Raise Approval</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 10 }}>
          <label style={lbl}>Type *
            <select value={form.approval_type} onChange={set('approval_type')} style={inp}>
              {types.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
          </label>
          <label style={lbl}>Priority
            <select value={form.priority} onChange={set('priority')} style={inp}>
              {PRIORITIES.map(p => <option key={p} value={p}>{p}</option>)}
            </select>
          </label>
          <label style={{ ...lbl, gridColumn: '1 / -1' }}>Title *
            <input value={form.title} onChange={set('title')} style={inp} />
          </label>
          <label style={lbl}>Vendor
            <select value={form.vendor_id} onChange={set('vendor_id')} style={inp}>
              <option value="">None</option>
              {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
            </select>
          </label>
          <label style={{ ...lbl, gridColumn: '1 / -1' }}>Description
            <textarea value={form.description} onChange={set('description')} rows={3} style={{ ...inp, resize: 'vertical' }} />
          </label>
        </div>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={save} disabled={saving || !form.title} style={{ ...btnPrimary, opacity: (saving || !form.title) ? 0.6 : 1 }}>{saving ? 'Raising…' : 'Raise'}</button>
        </div>
      </div>
    </div>
  )
}

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#8b5cf6,#7C3AED)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 560, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
