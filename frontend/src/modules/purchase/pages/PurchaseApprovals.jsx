import { useState, useEffect, useCallback } from 'react'
import { Plus, RefreshCw, X, Check, Ban, Slash } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import LoadError from '@/components/ui/LoadError'
import { useAuth } from '@/context/AuthContext'
import { KIT3D_STYLE as PURCHASE_STYLE } from '@/components/ui/kit3d'

/**
 * Purchase central approval register (Sangoe TPV §12) — the generic register of
 * ~18 governance approval types. Purchase-owned mirror of the TPV register;
 * hits only /api/purchase/approval-requests. Distinct from the onboarding stage
 * chain. Deciding is admin-only (enforced server-side too).
 */
const STATUS_TONE = { Pending: '#f59e0b', Approved: '#22c55e', Rejected: '#ef4444', Cancelled: '#6b7280' }
const PRIO_TONE = { Low: '#6b7280', Medium: '#0ea5e9', High: '#f59e0b', Urgent: '#ef4444' }
const PRIORITIES = ['Low', 'Medium', 'High', 'Urgent']
const STATUSES = ['Pending', 'Approved', 'Rejected', 'Cancelled']
const date = (d) => (d ? new Date(d).toLocaleDateString() : '—')

export default function PurchaseApprovals() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [types, setTypes] = useState([])
  const [vendors, setVendors] = useState([])
  const [statusF, setStatusF] = useState('')
  const [typeF, setTypeF] = useState('')
  const [modal, setModal] = useState(null)   // {} to raise
  const [decide, setDecide] = useState(null)  // { row, decision }

  const load = useCallback(() => {
    const params = {}
    if (statusF) params.status = statusF
    if (typeF) params.approval_type = typeF
    purchaseApi.approvalRequests.list(params)
      .then(d => { setLoadError(null); setRows(d?.data ?? []); setTypes(d?.types ?? []) })
      .catch(e => { setRows([]); setLoadError(e) })
  }, [statusF, typeF])
  useEffect(() => { load() }, [load])
  useEffect(() => { purchaseApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([])) }, [])

  const all = rows || []
  const cards = [
    { k: 'Total', v: all.length, c: '#38bdf8' },
    { k: 'Pending', v: all.filter(r => r.status === 'Pending').length, c: '#f59e0b' },
    { k: 'Approved', v: all.filter(r => r.status === 'Approved').length, c: '#22c55e' },
    { k: 'Rejected', v: all.filter(r => r.status === 'Rejected').length, c: '#ef4444' },
  ]

  return (
    <div style={{ padding: 4 }}>
      <style>{PURCHASE_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#38bdf8', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>GOVERNANCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Approval Register</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Central register of governance approvals across all types. Deciding is admin-only.</p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <select value={typeF} onChange={e => setTypeF(e.target.value)} style={inp}>
            <option value="">All types</option>
            {types.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
          </select>
          <select value={statusF} onChange={e => setStatusF(e.target.value)} style={inp}>
            <option value="">All statuses</option>
            {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
          <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setModal({})} style={btnPrimary}><Plus size={15} /> Raise approval</button>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', gap: 10, marginBottom: 14 }}>
        {cards.map(c => (
          <div key={c.k} className="pr-glass" style={{ padding: '12px 14px', borderRadius: 12 }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: c.c }}>{c.v}</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{c.k}</div>
          </div>
        ))}
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Reference', 'Type', 'Title', 'Vendor', 'Priority', 'Requested', 'Status', 'Decision', ''].map(h => <th key={h} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={9} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={9} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : all.length === 0 ? <tr><td colSpan={9} style={{ padding: 18, color: 'var(--text-muted)' }}>No approvals raised.</td></tr>
                : all.map(a => (
                  <tr key={a.id} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '10px 14px', fontWeight: 700, color: '#38bdf8' }}>{a.reference}</td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{a.type_label}</td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{a.title}</td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{a.vendor?.company_name || '—'}</td>
                    <td style={{ padding: '10px 14px' }}><Pill tone={PRIO_TONE[a.priority]} text={a.priority} /></td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{a.requester?.name || '—'}</td>
                    <td style={{ padding: '10px 14px' }}><Pill tone={STATUS_TONE[a.status]} text={a.status} /></td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>
                      {a.status === 'Pending' ? '—' : <>{a.decider?.name || '—'}<div style={{ fontSize: 11, opacity: 0.8 }}>{date(a.decided_at)}</div></>}
                    </td>
                    <td style={{ padding: '10px 14px', whiteSpace: 'nowrap' }}>
                      {isAdmin && a.status === 'Pending' && (
                        <>
                          <button onClick={() => setDecide({ row: a, decision: 'approve' })} style={{ ...miniBtn, color: '#22c55e' }} title="Approve"><Check size={12} /> Approve</button>
                          <button onClick={() => setDecide({ row: a, decision: 'reject' })} style={{ ...miniBtn, color: '#ef4444' }} title="Reject"><Ban size={12} /> Reject</button>
                          <button onClick={() => setDecide({ row: a, decision: 'cancel' })} style={{ ...miniBtn, color: '#6b7280' }} title="Cancel"><Slash size={12} /> Cancel</button>
                        </>
                      )}
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </div>

      {modal && <RaiseModal types={types} vendors={vendors} onClose={() => setModal(null)} onSaved={() => { setModal(null); load() }} />}
      {decide && <DecideModal payload={decide} onClose={() => setDecide(null)} onDone={() => { setDecide(null); load() }} />}
    </div>
  )
}

function Pill({ tone, text }) {
  const c = tone || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${c}1f`, color: c, fontSize: 11, fontWeight: 700 }}>{text}</span>
}

function RaiseModal({ types, vendors, onClose, onSaved }) {
  const [form, setForm] = useState({ approval_type: types[0]?.value || 'other', title: '', description: '', purchase_vendor_id: '', priority: 'Medium' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null))
      await purchaseApi.approvalRequests.create(payload)
      onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not raise the approval.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Raise approval</h2>
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
            <input value={form.title} onChange={set('title')} style={inp} placeholder="What needs approval?" />
          </label>
          <label style={lbl}>Vendor
            <select value={form.purchase_vendor_id} onChange={set('purchase_vendor_id')} style={inp}>
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
          <button onClick={save} disabled={saving || !form.title} style={{ ...btnPrimary, opacity: (saving || !form.title) ? 0.6 : 1 }}>{saving ? 'Saving…' : 'Raise'}</button>
        </div>
      </div>
    </div>
  )
}

function DecideModal({ payload, onClose, onDone }) {
  const { row, decision } = payload
  const [remarks, setRemarks] = useState('')
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const label = { approve: 'Approve', reject: 'Reject', cancel: 'Cancel' }[decision]
  const tone = { approve: '#22c55e', reject: '#ef4444', cancel: '#6b7280' }[decision]
  const needsRemarks = decision === 'reject'

  const confirm = async () => {
    if (needsRemarks && !remarks.trim()) { setErr('A rejection needs a reason.'); return }
    setSaving(true); setErr(null)
    try {
      await purchaseApi.approvalRequests.decide(row.id, { decision, remarks: remarks || undefined })
      onDone()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not record the decision.'); setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={{ ...sheet, maxWidth: 440 }} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{label} approval</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '0 0 12px' }}>{row.reference} · {row.title}</p>
        <label style={lbl}>Remarks{needsRemarks ? ' *' : ' (optional)'}
          <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3} style={{ ...inp, resize: 'vertical' }} placeholder={needsRemarks ? 'Why is this rejected?' : 'Any notes…'} />
        </label>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={confirm} disabled={saving} style={{ ...btnPrimary, background: tone, opacity: saving ? 0.6 : 1 }}>{saving ? 'Saving…' : label}</button>
        </div>
      </div>
    </div>
  )
}

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#0ea5e9,#0284c7)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', fontSize: 11.5, fontWeight: 700, marginRight: 4, cursor: 'pointer' }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 600, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
