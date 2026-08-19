import { useState, useEffect, useCallback } from 'react'
import { ArrowLeft, Loader2, Check, ShieldCheck, ShieldAlert, AlertTriangle, DoorOpen, DoorClosed } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import { canApprovePR } from '../constants'

const STEPS = [
  { step: 1, key: 'profile',   label: 'Profile' },
  { step: 2, key: 'medical',   label: 'Medical' },
  { step: 3, key: 'induction', label: 'Training & Induction' },
  { step: 4, key: 'ppe',       label: 'PPE' },
  { step: 5, key: 'badge',     label: 'Badge & Gate' },
]

/**
 * Admin/staff detail for one Purchase worker — the review side of the five steps.
 *
 * Everything shown here is server state: readiness, current_step, the PPE issues
 * and the gate decision all come from the API, so this screen cannot disagree
 * with the vendor's portal or with the inventory ledger.
 *
 * Activating a worker admits a person to the site, so it is admin-only. The
 * button is hidden for staff; the endpoint (role:admin) is the real boundary and
 * refuses them regardless of what the UI renders.
 */
export default function PurchaseWorkerDetail({ workerId, onBack }) {
  const { user } = useAuth()
  const isAdmin = canApprovePR(user)

  const [data, setData] = useState(null)
  const [ppe, setPpe] = useState(null)
  const [gate, setGate] = useState(null)
  const [tab, setTab] = useState(1)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    const [d, p, g] = await Promise.all([
      purchaseApi.workforce.worker(workerId).catch(() => null),
      purchaseApi.workforce.ppe(workerId).catch(() => null),
      purchaseApi.workforce.gate(workerId).catch(() => null),
    ])
    setData(d); setPpe(p); setGate(g)
    setTab(Math.min(Number(d?.worker?.current_step || 1), STEPS.length))
    setLoading(false)
  }, [workerId])

  useEffect(() => { load() }, [load])

  const activate = async () => {
    setBusy(true); setErr(null)
    try { await purchaseApi.workforce.activate(workerId); await load() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not activate this worker.') }
    finally { setBusy(false) }
  }

  // Worker lifecycle — suspend/reinstate/terminate withhold or restore site access.
  const suspendWorker = async () => {
    const reason = window.prompt('Reason for suspending this worker (optional):') ?? ''
    setBusy(true); setErr(null)
    try { await purchaseApi.workforce.suspend(workerId, reason.trim() || null); await load() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not suspend this worker.') }
    finally { setBusy(false) }
  }
  const reinstateWorker = async () => {
    setBusy(true); setErr(null)
    try { await purchaseApi.workforce.reinstate(workerId); await load() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not reinstate this worker.') }
    finally { setBusy(false) }
  }
  const terminateWorker = async () => {
    if (!confirm('Terminate this worker? This is permanent and disables their badge at the gate.')) return
    const reason = window.prompt('Reason for termination (optional):') ?? ''
    setBusy(true); setErr(null)
    try { await purchaseApi.workforce.terminate(workerId, reason.trim() || null); await load() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not terminate this worker.') }
    finally { setBusy(false) }
  }

  const giveBack = async (issueId, condition) => {
    setBusy(true); setErr(null)
    try { await purchaseApi.workforce.returnPpe(issueId, { condition }); await load() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not record the return.') }
    finally { setBusy(false) }
  }

  if (loading) return <div style={{ padding: 24, color: 'var(--text-muted)', fontSize: 13 }}>Loading worker…</div>
  if (!data?.worker) {
    return (
      <div style={{ padding: 12 }}>
        <button onClick={onBack} style={ghostBtn}><ArrowLeft size={14} /> Back</button>
        <p style={{ color: '#ef4444', fontSize: 13, marginTop: 12 }}>Worker not found.</p>
      </div>
    )
  }

  const w = data.worker
  const r = data.readiness || {}
  const b = data.badge || {}
  const step = Number(w.current_step || 1)

  return (
    <div className="animate-fade-in" style={{ padding: 4 }}>
      <button onClick={onBack} style={{ ...ghostBtn, marginBottom: 12 }}><ArrowLeft size={14} /> All workers</button>

      <div style={{ ...card, marginBottom: 14, display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap' }}>
        <div style={{ flex: 1, minWidth: 200 }}>
          <div style={{ fontSize: 17, fontWeight: 800, color: 'var(--text-h)' }}>{w.full_name}</div>
          <div style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 2 }}>
            {w.worker_code} · {w.vendor?.company_name || '—'} · Step {step} of 5
          </div>
        </div>
        <GateChip gate={gate} />
      </div>

      {/* Backend-owned progress — never recomputed here. */}
      <div style={{ display: 'flex', gap: 6, overflowX: 'auto', marginBottom: 14, paddingBottom: 4 }}>
        {STEPS.map(s => {
          const done = step >= s.step
          const cur = tab === s.step
          return (
            <button key={s.key} onClick={() => setTab(s.step)} style={{
              flex: '1 0 auto', padding: '9px 14px', borderRadius: 10, fontSize: 12.5, fontWeight: 700,
              cursor: 'pointer', whiteSpace: 'nowrap',
              border: `1px solid ${cur ? '#0ea5e9' : 'var(--border)'}`,
              background: cur ? 'rgba(14,165,233,.10)' : 'var(--bg-card)',
              color: cur ? '#0ea5e9' : (done ? 'var(--text-h)' : 'var(--text-muted)'),
            }}>
              {done && <Check size={12} style={{ marginRight: 5, verticalAlign: -1 }} />}
              {s.step}. {s.label}
            </button>
          )
        })}
      </div>

      {err && <div style={{ ...card, borderColor: '#ef4444', color: '#ef4444', fontSize: 12.5, marginBottom: 12 }}>{err}</div>}

      {tab === 1 && (
        <Panel title="Step 1 — Profile">
          <div style={grid}>
            <Read label="Full name" value={w.full_name} />
            <Read label="Worker code" value={w.worker_code} />
            <Read label="Designation" value={w.designation} />
            <Read label="Mobile" value={w.phone} />
            <Read label="Email" value={w.email} />
            <Read label="Date of birth" value={w.dob} />
            <Read label="ID proof" value={w.id_proof_type ? `${w.id_proof_type} · ${w.id_proof_number || '—'}` : '—'} />
            <Read label="Status" value={w.status} />
          </div>
          <Check2 ok={r.documents_ok} label="Documents on file" />
        </Panel>
      )}

      {tab === 2 && (
        <Panel title="Step 2 — Medical">
          <Check2 ok={r.medical_ok} label="Fit and unexpired medical on file" />
          <RecordTable
            rows={w.medicals || []}
            cols={[
              ['Exam date', m => m.exam_date],
              ['Result', m => m.fitness_status],
              ['Expires', m => m.expiry_date || '—'],
              ['Remarks', m => m.remarks || '—'],
            ]}
            empty="No medical examinations recorded."
          />
        </Panel>
      )}

      {tab === 3 && (
        <Panel title="Step 3 — Training & Induction">
          <Check2 ok={r.training_ok} label="Completed training on file" />
          <RecordTable
            rows={w.trainings || []}
            cols={[
              ['Title', t => t.title],
              ['Date', t => t.training_date || '—'],
              ['Status', t => t.status],
              ['Score', t => t.score ?? '—'],
            ]}
            empty="No training recorded."
          />
          <div style={{ height: 14 }} />
          <Check2 ok={r.induction_ok} label="Completed induction on file" />
          <RecordTable
            rows={w.inductions || []}
            cols={[
              ['Date', i => i.induction_date || '—'],
              ['Status', i => i.status],
              ['Conducted by', i => i.conducted_by || '—'],
              ['Remarks', i => i.remarks || '—'],
            ]}
            empty="No induction recorded."
          />
        </Panel>
      )}

      {tab === 4 && (
        <Panel title="Step 4 — PPE">
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '0 0 12px' }}>
            Issued from the central Inventory — every line below has a matching stock movement.
          </p>
          {(ppe?.issues ?? []).length === 0 ? (
            <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0 }}>Nothing issued to this worker.</p>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table style={table}>
                <thead>
                  <tr style={{ textAlign: 'left', color: 'var(--text-muted)' }}>
                    <th style={th}>Item</th><th style={th}>Issued</th><th style={th}>Outstanding</th>
                    <th style={th}>Date</th><th style={th}>Status</th><th style={th}>Action</th>
                  </tr>
                </thead>
                <tbody>
                  {ppe.issues.map(i => {
                    const out = Number(i.qty) - Number(i.returned_qty || 0)
                    return (
                      <tr key={i.id} style={{ borderTop: '1px solid var(--border)' }}>
                        <td style={td}>{i.item}</td>
                        <td style={td}>{i.qty}</td>
                        <td style={td}>{out}</td>
                        <td style={td}>{i.issued_date || '—'}</td>
                        <td style={td}><span style={pillFor(i.status)}>{i.status}</span></td>
                        <td style={td}>
                          {i.status === 'issued' && (
                            <span style={{ display: 'inline-flex', gap: 6, flexWrap: 'wrap' }}>
                              <button onClick={() => giveBack(i.id, 'returned')} disabled={busy} style={miniBtn}>Return</button>
                              <button onClick={() => giveBack(i.id, 'lost')} disabled={busy} style={miniBtn}>Lost</button>
                              <button onClick={() => giveBack(i.id, 'damaged')} disabled={busy} style={miniBtn}>Damaged</button>
                            </span>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
          <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '12px 0 0', lineHeight: 1.6 }}>
            A genuine return puts stock back in the warehouse it left. Lost and damaged do not —
            those items already left inventory when they were issued.
          </p>
        </Panel>
      )}

      {tab === 5 && (
        <Panel title="Step 5 — Badge & Gate">
          {b.activated ? (
            <>
              <Note tone={w.status === 'Active' ? 'ok' : w.status === 'Terminated' ? 'warn' : 'info'}>
                {w.status === 'Active' ? 'This worker is activated and cleared for site entry.'
                  : w.status === 'Suspended' ? 'This worker is suspended — site access is withheld until reinstated.'
                  : w.status === 'Terminated' ? 'This worker is terminated — the badge no longer scans at the gate.'
                  : `Worker status: ${w.status}.`}
              </Note>
              <div style={{ ...grid, marginTop: 12 }}>
                <Read label="Badge number" value={b.badge_number} />
                <Read label="Issued" value={b.badge_issued_at ? new Date(b.badge_issued_at).toLocaleString() : '—'} />
                <Read label="Valid until" value={b.badge_valid_until || 'No expiry'} />
                <Read label="Worker status" value={w.status} />
              </div>

              {isAdmin && w.status !== 'Terminated' && (
                <div style={{ display: 'flex', gap: 8, marginTop: 16, flexWrap: 'wrap' }}>
                  {w.status === 'Active' && (
                    <button onClick={suspendWorker} disabled={busy}
                      style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, border: '1px solid rgba(249,115,22,0.4)', background: 'transparent', color: '#f97316', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>
                      Suspend
                    </button>
                  )}
                  {w.status === 'Suspended' && (
                    <button onClick={reinstateWorker} disabled={busy}
                      style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, border: '1px solid rgba(16,185,129,0.4)', background: 'transparent', color: '#10b981', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>
                      Reinstate
                    </button>
                  )}
                  <button onClick={terminateWorker} disabled={busy}
                    style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, border: '1px solid rgba(239,68,68,0.4)', background: 'transparent', color: '#ef4444', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>
                    Terminate
                  </button>
                </div>
              )}
            </>
          ) : (
            <>
              <Note tone="warn">
                No badge issued yet. All four readiness checks and issued PPE are required first.
              </Note>
              <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 7 }}>
                <Check2 ok={r.documents_ok} label="Documents" />
                <Check2 ok={r.medical_ok} label="Medical" />
                <Check2 ok={r.training_ok} label="Training" />
                <Check2 ok={r.induction_ok} label="Induction" />
                <Check2 ok={step >= 4} label="PPE issued" />
              </div>

              {isAdmin ? (
                <button onClick={activate} disabled={busy || !r.ready || step < 4}
                  style={{ ...primaryBtn, marginTop: 16, opacity: (!r.ready || step < 4) ? 0.5 : 1 }}>
                  {busy && <Loader2 size={14} className="animate-spin" />} Activate Worker
                </button>
              ) : (
                // Staff review but do not admit. The endpoint is role:admin too.
                <Note tone="info" style={{ marginTop: 16 }}>
                  Activation is restricted to administrators.
                </Note>
              )}
            </>
          )}
        </Panel>
      )}
    </div>
  )
}

function GateChip({ gate }) {
  if (!gate) return null
  const admit = gate.admit === true
  const c = admit ? '#10b981' : '#ef4444'
  const Icon = admit ? DoorOpen : DoorClosed

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 12px', borderRadius: 10, background: `${c}14`, color: c, flex: 'none' }}>
      <Icon size={16} />
      <div>
        <div style={{ fontSize: 12.5, fontWeight: 800 }}>Gate: {admit ? 'ADMIT' : 'DENIED'}</div>
        {gate.reason && <div style={{ fontSize: 11, opacity: .85 }}>{gate.reason}</div>}
      </div>
    </div>
  )
}

function Panel({ title, children }) {
  return (
    <div style={card}>
      <h2 style={{ margin: '0 0 14px', fontSize: 15.5, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h2>
      {children}
    </div>
  )
}

function RecordTable({ rows, cols, empty }) {
  if (!rows.length) return <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '10px 0 0' }}>{empty}</p>
  return (
    <div style={{ overflowX: 'auto', marginTop: 10 }}>
      <table style={table}>
        <thead>
          <tr style={{ textAlign: 'left', color: 'var(--text-muted)' }}>
            {cols.map(([h]) => <th key={h} style={th}>{h}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => (
            <tr key={row.id ?? i} style={{ borderTop: '1px solid var(--border)' }}>
              {cols.map(([h, fn]) => <td key={h} style={td}>{fn(row)}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function Check2({ ok, label }) {
  const c = ok ? '#10b981' : '#b45309'
  const Icon = ok ? ShieldCheck : ShieldAlert
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5, color: c, marginTop: 10 }}>
      <Icon size={14} /> {label} — {ok ? 'done' : 'outstanding'}
    </div>
  )
}

function Read({ label, value }) {
  return (
    <div>
      <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '.04em' }}>{label}</div>
      <div style={{ fontSize: 13.5, color: 'var(--text-h)', marginTop: 3 }}>{value || '—'}</div>
    </div>
  )
}

function Note({ children, tone = 'info', style = {} }) {
  const map = { info: ['#0ea5e9', 'rgba(14,165,233,.08)'], warn: ['#b45309', 'rgba(245,158,11,.10)'], ok: ['#047857', 'rgba(16,185,129,.10)'] }
  const [c, bg] = map[tone] || map.info
  const Icon = tone === 'ok' ? ShieldCheck : AlertTriangle
  return (
    <div style={{ display: 'flex', gap: 9, alignItems: 'flex-start', padding: '10px 12px', borderRadius: 10, background: bg, color: c, fontSize: 12.5, lineHeight: 1.55, ...style }}>
      <Icon size={15} style={{ flex: 'none', marginTop: 1 }} />
      <div>{children}</div>
    </div>
  )
}

const card = { background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 18 }
const grid = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 12 }
const table = { width: '100%', borderCollapse: 'collapse', fontSize: 12.5, minWidth: 560 }
const th = { padding: '9px 11px', fontWeight: 700, fontSize: 11.5, whiteSpace: 'nowrap' }
const td = { padding: '9px 11px', color: 'var(--text-h)' }
const primaryBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 18px', borderRadius: 9, border: 'none', cursor: 'pointer', background: '#10b981', color: '#fff', fontSize: 13.5, fontWeight: 700 }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 12.5, fontWeight: 700 }
const miniBtn = { padding: '5px 10px', borderRadius: 7, cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 11.5, fontWeight: 700 }

function pillFor(status) {
  const c = status === 'returned' ? '#10b981' : status === 'issued' ? '#0ea5e9' : '#b45309'
  return { display: 'inline-flex', padding: '3px 9px', borderRadius: 20, fontSize: 11, fontWeight: 700, color: c, background: `${c}1a` }
}
