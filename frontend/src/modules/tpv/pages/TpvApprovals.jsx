import { useState, useEffect } from 'react'
import {
  ShieldCheck, RefreshCw, AlertTriangle, CheckCircle, XCircle, PauseCircle, Clock,
  UserPlus, Loader, ArrowRight,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { useAuth } from '@/context/AuthContext'
import { canApproveTpv, fmtDate } from '../constants'
import {
  KIT3D_STYLE, Overlay, ModalFooter, Field, TextInput, SelectInput, InfoBox,
} from '@/components/ui/kit3d'

const DECISIONS = [['approve', 'Approve'], ['reject', 'Reject'], ['hold', 'Hold']]
const DEC_CFG = {
  approve: { color: '#10b981', icon: CheckCircle }, reject: { color: '#ef4444', icon: XCircle },
  hold: { color: '#f59e0b', icon: PauseCircle }, pending: { color: '#94a3b8', icon: Clock },
}

export default function TpvApprovals() {
  const { user } = useAuth()
  const admin = canApproveTpv(user)
  const [rows, setRows] = useState([])
  const [loading, setLoad] = useState(true)
  const [modal, setModal] = useState(null) // { type, row }
  const [busy, setBusy] = useState(false)

  const load = () => {
    setLoad(true)
    tpvApi.approvals.list().then((d) => { setRows(d?.data ?? d ?? []); setLoad(false) }).catch(() => setLoad(false))
  }
  useEffect(() => { load() }, [])

  const runEscalation = async () => {
    setBusy(true)
    try { const r = await tpvApi.approvals.escalate(); alert(`${r.escalated} level(s) escalated.`); load() }
    catch { alert('Escalation failed.') } finally { setBusy(false) }
  }

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>MULTI-LEVEL WORKFLOW</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Approvals</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Onboardings awaiting a decision, with level, SLA and escalation status.</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          {admin && <button onClick={runEscalation} disabled={busy} style={ghostBtn}><AlertTriangle size={14} /> Run Escalation</button>}
          {admin && <button onClick={() => setModal({ type: 'delegate' })} style={solidBtn}><UserPlus size={15} /> Delegate</button>}
        </div>
      </div>

      {loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>{[1, 2, 3].map(i => <div key={i} className="skeleton" style={{ height: 56, borderRadius: 12, background: 'var(--border)' }} />)}</div>
      ) : rows.length === 0 ? (
        <div className="pr-glass" style={{ padding: '44px 24px', textAlign: 'center' }}>
          <ShieldCheck size={26} style={{ color: '#a78bfa' }} />
          <h3 style={{ margin: '10px 0 4px', fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Nothing awaiting approval</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>Submitted onboardings appear here for review.</p>
        </div>
      ) : (
        <div className="pr-glass" style={{ padding: 0, borderRadius: 16, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 820, fontSize: 12.5 }}>
              <thead><tr>
                {['Vendor', 'Level', 'SLA Due', 'SLA', 'Submitted', 'Actions'].map((h, i) => (
                  <th key={h} style={{ ...th, textAlign: i === 5 ? 'right' : 'left' }}>{h}</th>
                ))}
              </tr></thead>
              <tbody>
                {rows.map((r) => {
                  const overdue = r.sla_due_at && new Date(r.sla_due_at) < new Date()
                  return (
                    <tr key={r.id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={td}>
                        <div style={{ fontWeight: 700, color: 'var(--text-h)' }}>{r.vendor?.company_name}</div>
                        <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{r.vendor?.vendor_code}</div>
                      </td>
                      <td style={td}>
                        <span style={{ fontWeight: 700 }}>L{r.current_level || '—'} / {r.total_levels}</span>
                        <span style={{ fontSize: 11, color: 'var(--text-muted)', marginLeft: 6 }}>{r.current_role}</span>
                      </td>
                      <td style={td}>{fmtDate(r.sla_due_at)}</td>
                      <td style={td}>
                        {r.escalated
                          ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: '#ef4444', fontWeight: 800 }}><AlertTriangle size={12} /> Escalated</span>
                          : overdue
                            ? <span style={{ color: '#f59e0b', fontWeight: 700 }}>Overdue</span>
                            : <span style={{ color: '#10b981', fontWeight: 700 }}>On track</span>}
                      </td>
                      <td style={td}>{fmtDate(r.submitted_at)}</td>
                      <td style={{ ...td, textAlign: 'right' }}>
                        <button onClick={() => setModal({ type: 'review', row: r })} style={{ ...ghostBtn, padding: '6px 12px' }}>Review <ArrowRight size={13} /></button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {modal?.type === 'review' && <ReviewDrawer row={modal.row} onClose={() => setModal(null)} onDone={() => { setModal(null); load() }} />}
      {modal?.type === 'delegate' && <DelegateModal onClose={() => setModal(null)} onDone={() => setModal(null)} />}
    </div>
  )
}

function ReviewDrawer({ row, onClose, onDone }) {
  const [data, setData] = useState(null)
  const [decision, setDecision] = useState('approve')
  const [remarks, setRemarks] = useState('')
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)

  const load = () => tpvApi.approvals.history(row.id).then(setData).catch(() => setData({ chain: [] }))
  useEffect(() => { load() }, [row.id]) // eslint-disable-line react-hooks/exhaustive-deps

  const submit = async () => {
    if ((decision === 'reject' || decision === 'hold') && !remarks.trim()) { setErr('A reason is required.'); return }
    setSaving(true); setErr(null)
    try { await tpvApi.approvals.decide(row.id, { decision, remarks }); onDone() }
    catch (e) { setErr(e?.response?.data?.message || 'Decision failed.'); setSaving(false) }
  }

  return (
    <Overlay onClose={onClose} width={620}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Approval — {row.vendor?.company_name}</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>{data?.mode === 'multi_level' ? 'Multi-level chain' : 'Single approval'} · current level L{data?.current || row.current_level}</p>
      </div>
      <div style={{ padding: '10px 22px', maxHeight: 420, overflowY: 'auto' }}>
        <p style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', color: '#a78bfa', margin: '0 0 8px' }}>Approval History</p>
        {!data ? <div style={{ padding: 20, textAlign: 'center' }}><Loader size={18} /></div> : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 16 }}>
            {(data.chain || []).map((lvl) => {
              const cfg = DEC_CFG[lvl.decision || 'pending']; const Icon = cfg.icon
              return (
                <div key={lvl.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 12px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                  <div style={{ width: 30, height: 30, borderRadius: 9, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)', color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>L{lvl.level}</div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{lvl.approver_role}{lvl.approver?.name ? ` · ${lvl.approver.name}` : ''}{lvl.delegated_by ? ' (delegated)' : ''}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{lvl.remarks || '—'}{lvl.decided_at ? ` · ${fmtDate(lvl.decided_at)}` : ''}{lvl.escalated_at ? ' · escalated' : ''}</div>
                  </div>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11.5, fontWeight: 800, color: cfg.color }}><Icon size={13} /> {lvl.decision || 'Pending'}</span>
                </div>
              )
            })}
          </div>
        )}

        <p style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', color: '#a78bfa', margin: '4px 0 8px' }}>Your Decision</p>
        <Field label="Decision" full><SelectInput value={decision} onChange={e => setDecision(e.target.value)} options={DECISIONS} pairs /></Field>
        <Field label={decision === 'approve' ? 'Remarks (optional)' : 'Reason (required)'} full>
          <TextInput value={remarks} onChange={e => setRemarks(e.target.value)} placeholder="Add a note…" />
        </Field>
        {err && <InfoBox tone="danger">{err}</InfoBox>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={submit} loading={saving} confirmLabel="Submit Decision" color={DEC_CFG[decision]?.color || '#7C3AED'} />
    </Overlay>
  )
}

function DelegateModal({ onClose, onDone }) {
  const [f, setF] = useState({ delegator_id: '', delegate_id: '', reason: '', starts_at: '', ends_at: '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setF((p) => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      await tpvApi.approvals.delegations.create({
        delegator_id: Number(f.delegator_id), delegate_id: Number(f.delegate_id),
        reason: f.reason || undefined, starts_at: f.starts_at, ends_at: f.ends_at,
      })
      onDone()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not create delegation.'); setSaving(false) }
  }

  return (
    <Overlay onClose={onClose} width={480}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Delegate Approval</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Grant an approver's authority to another user for a window.</p>
      </div>
      <div style={{ padding: '10px 22px' }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <Field label="Delegator (user ID)"><TextInput type="number" value={f.delegator_id} onChange={set('delegator_id')} /></Field>
          <Field label="Delegate (user ID)"><TextInput type="number" value={f.delegate_id} onChange={set('delegate_id')} /></Field>
        </div>
        <Field label="Reason" full><TextInput value={f.reason} onChange={set('reason')} placeholder="On leave, covering approvals" /></Field>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <Field label="Starts"><TextInput type="date" value={f.starts_at} onChange={set('starts_at')} /></Field>
          <Field label="Ends"><TextInput type="date" value={f.ends_at} onChange={set('ends_at')} /></Field>
        </div>
        {err && <InfoBox tone="danger">{err}</InfoBox>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel="Create Delegation" color="#7C3AED" />
    </Overlay>
  )
}

const th = { padding: '11px 14px', fontSize: 10.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)', whiteSpace: 'nowrap', background: 'var(--bg-card)' }
const td = { padding: '11px 14px', color: 'var(--text-h)', whiteSpace: 'nowrap', verticalAlign: 'middle' }
const solidBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }
