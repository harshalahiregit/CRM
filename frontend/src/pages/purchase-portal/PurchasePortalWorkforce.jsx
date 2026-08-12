import { useState, useEffect, useCallback } from 'react'
import { HardHat, Plus, Loader2, ChevronRight, ShieldCheck, AlertTriangle, Check } from 'lucide-react'
import { purchasePortalApi as api } from '@/services/purchasePortalApi'

const STEPS = [
  { step: 1, key: 'profile',   label: 'Profile' },
  { step: 2, key: 'medical',   label: 'Medical' },
  { step: 3, key: 'induction', label: 'Training & Induction' },
  { step: 4, key: 'ppe',       label: 'PPE' },
  { step: 5, key: 'badge',     label: 'Entry Badge' },
]

/**
 * Purchase Vendor workforce — the five-step worker lifecycle.
 *
 * The vendor supplies the evidence; the site decides who may walk in. Step 5 is
 * therefore READ ONLY here: activation lives on the admin route, and this screen
 * only reports whether it has happened.
 *
 * `current_step` comes from the server on every load, so the wizard resumes where
 * the worker actually is rather than where this component last rendered.
 */
export default function PurchasePortalWorkforce() {
  const [workers, setWorkers] = useState([])
  const [loading, setLoading] = useState(true)
  const [active, setActive]   = useState(null)   // worker id being worked on
  const [creating, setCreating] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    api.workers.list()
      .then(d => setWorkers(d?.data ?? d ?? []))
      .catch(() => setWorkers([]))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => { load() }, [load])

  if (loading) {
    return <div style={{ padding: 24, color: 'var(--text-muted)', fontSize: 13 }}>Loading your workforce…</div>
  }

  if (active) {
    return <WorkerWizard workerId={active} onBack={() => { setActive(null); load() }} />
  }

  return (
    <div className="animate-fade-in">
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 16, flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <HardHat size={19} style={{ color: '#0ea5e9' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>My Workforce</h1>
            <p style={{ margin: '2px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>
              Your workers and how far each has progressed.
            </p>
          </div>
        </div>
        <button onClick={() => setCreating(true)} style={primaryBtn}>
          <Plus size={15} /> Add Worker
        </button>
      </div>

      {creating && <CreateWorker onDone={(id) => { setCreating(false); load(); if (id) setActive(id) }} onCancel={() => setCreating(false)} />}

      {workers.length === 0 && !creating ? (
        <Empty />
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {workers.map(w => <WorkerRow key={w.id} worker={w} onOpen={() => setActive(w.id)} />)}
        </div>
      )}
    </div>
  )
}

function Empty() {
  return (
    <div style={{ ...card, textAlign: 'center', padding: '36px 24px' }}>
      <HardHat size={30} style={{ color: 'var(--text-muted)', marginBottom: 10 }} />
      <p style={{ margin: 0, fontSize: 13.5, color: 'var(--text-muted)' }}>
        No workers yet. Add one to start the five-step process.
      </p>
    </div>
  )
}

function WorkerRow({ worker, onOpen }) {
  const step = Number(worker.current_step || 1)
  const pct  = Math.round((step / STEPS.length) * 100)

  return (
    <button onClick={onOpen} style={{ ...card, display: 'flex', alignItems: 'center', gap: 14, cursor: 'pointer', textAlign: 'left', width: '100%' }}>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 14.5, fontWeight: 700, color: 'var(--text-h)' }}>{worker.full_name}</div>
        <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>
          {worker.worker_code} · {worker.designation || '—'}
        </div>
        <div style={{ marginTop: 8, height: 5, borderRadius: 3, background: 'var(--bg-input)', overflow: 'hidden' }}>
          <div style={{ width: `${pct}%`, height: '100%', background: step >= 5 ? '#10b981' : '#0ea5e9' }} />
        </div>
        <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 5 }}>
          Step {step} of {STEPS.length} — {STEPS[step - 1]?.label}
        </div>
      </div>
      {worker.badge_number
        ? <span style={badgePill('#10b981')}><ShieldCheck size={12} /> Badged</span>
        : <span style={badgePill('#6b7280')}>In progress</span>}
      <ChevronRight size={16} style={{ color: 'var(--text-muted)', flex: 'none' }} />
    </button>
  )
}

function CreateWorker({ onDone, onCancel }) {
  const [f, setF] = useState({ full_name: '', dob: '', designation: '', phone: '', email: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const set = k => e => setF(p => ({ ...p, [k]: e.target.value }))

  const submit = async () => {
    setBusy(true); setErr(null)
    try {
      // No vendor_id is sent — the server takes it from the token.
      const res = await api.workers.create(f)
      onDone(res?.id ?? res?.data?.id ?? null)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not create the worker.')
      setBusy(false)
    }
  }

  return (
    <div style={{ ...card, marginBottom: 14 }}>
      <h2 style={h2}>New worker</h2>
      <div style={grid}>
        <Field label="Full name"><input style={input} value={f.full_name} onChange={set('full_name')} /></Field>
        <Field label="Date of birth"><input type="date" style={input} value={f.dob} onChange={set('dob')} /></Field>
        <Field label="Designation"><input style={input} value={f.designation} onChange={set('designation')} /></Field>
        <Field label="Mobile"><input style={input} value={f.phone} onChange={set('phone')} /></Field>
        <Field label="Email"><input type="email" style={input} value={f.email} onChange={set('email')} /></Field>
      </div>
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
      <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
        <button onClick={submit} disabled={busy || !f.full_name} style={primaryBtn}>
          {busy && <Loader2 size={14} className="animate-spin" />} Create
        </button>
        <button onClick={onCancel} style={ghostBtn}>Cancel</button>
      </div>
    </div>
  )
}

/** The five steps for one worker. Step is server state, re-read after every save. */
function WorkerWizard({ workerId, onBack }) {
  const [worker, setWorker] = useState(null)
  const [readiness, setReadiness] = useState(null)
  const [badge, setBadge] = useState(null)
  const [tab, setTab] = useState(1)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    const [w, r, b] = await Promise.all([
      api.workers.get(workerId).catch(() => null),
      api.workers.readiness(workerId).catch(() => null),
      api.workers.badge(workerId).catch(() => null),
    ])
    const rec = w?.worker ?? w
    setWorker(rec); setReadiness(r?.readiness ?? r); setBadge(b)
    // Resume where the worker actually is, not where this component last was.
    setTab(Math.min(Number(rec?.current_step || 1), STEPS.length))
    setLoading(false)
  }, [workerId])

  useEffect(() => { load() }, [load])

  if (loading) return <div style={{ padding: 24, color: 'var(--text-muted)', fontSize: 13 }}>Loading worker…</div>
  if (!worker) return <div style={{ padding: 24 }}><button onClick={onBack} style={ghostBtn}>← Back</button><p style={{ color: '#ef4444' }}>Worker not found.</p></div>

  const step = Number(worker.current_step || 1)

  return (
    <div className="animate-fade-in">
      <button onClick={onBack} style={{ ...ghostBtn, marginBottom: 12 }}>← All workers</button>

      <div style={{ ...card, marginBottom: 14 }}>
        <div style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{worker.full_name}</div>
        <div style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 2 }}>
          {worker.worker_code} · {worker.designation || '—'} · Step {step} of {STEPS.length}
        </div>
      </div>

      <div style={{ display: 'flex', gap: 6, overflowX: 'auto', marginBottom: 14, paddingBottom: 4 }}>
        {STEPS.map(s => {
          const done = step >= s.step
          const cur  = tab === s.step
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

      {tab === 1 && <ProfileStep worker={worker} onSaved={load} />}
      {tab === 2 && <MedicalStep workerId={workerId} readiness={readiness} onSaved={load} />}
      {tab === 3 && <InductionStep workerId={workerId} readiness={readiness} onSaved={load} />}
      {tab === 4 && <PpeStep workerId={workerId} onChanged={load} />}
      {tab === 5 && <BadgeStep badge={badge} readiness={readiness} />}
    </div>
  )
}

function ProfileStep({ worker }) {
  return (
    <div style={card}>
      <h2 style={h2}>Step 1 — Profile</h2>
      <div style={grid}>
        <Read label="Full name" value={worker.full_name} />
        <Read label="Worker code" value={worker.worker_code} />
        <Read label="Designation" value={worker.designation} />
        <Read label="Mobile" value={worker.phone} />
        <Read label="Date of birth" value={worker.dob} />
        <Read label="Status" value={worker.status} />
      </div>
    </div>
  )
}

function MedicalStep({ workerId, readiness, onSaved }) {
  const [f, setF] = useState({ fitness_status: 'Fit', exam_date: '', expiry_date: '', remarks: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const set = k => e => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    setBusy(true); setErr(null)
    try { await api.workers.medical(workerId, f); await onSaved() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not save the medical record.') }
    finally { setBusy(false) }
  }

  return (
    <div style={card}>
      <h2 style={h2}>Step 2 — Medical</h2>
      {readiness && !readiness.medical_ok && (
        <Note tone="warn">A <strong>Fit</strong> examination is required before this step is cleared.</Note>
      )}
      <div style={grid}>
        <Field label="Fitness result">
          <select style={input} value={f.fitness_status} onChange={set('fitness_status')}>
            <option>Fit</option><option>Unfit</option><option>Pending</option>
          </select>
        </Field>
        <Field label="Examination date"><input type="date" style={input} value={f.exam_date} onChange={set('exam_date')} /></Field>
        <Field label="Expiry date"><input type="date" style={input} value={f.expiry_date} onChange={set('expiry_date')} /></Field>
        <Field label="Remarks"><input style={input} value={f.remarks} onChange={set('remarks')} /></Field>
      </div>
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
      <button onClick={save} disabled={busy} style={{ ...primaryBtn, marginTop: 14 }}>
        {busy && <Loader2 size={14} className="animate-spin" />} Save medical
      </button>
    </div>
  )
}

function InductionStep({ workerId, readiness, onSaved }) {
  const [t, setT] = useState({ title: '', status: 'Completed', training_date: '' })
  const [i, setI] = useState({ status: 'Completed', induction_date: '', conducted_by: '' })
  const [busy, setBusy] = useState(null)
  const [err, setErr] = useState(null)

  const run = async (which) => {
    setBusy(which); setErr(null)
    try {
      if (which === 'training') await api.workers.training(workerId, t)
      else await api.workers.induction(workerId, i)
      await onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not save.') }
    finally { setBusy(null) }
  }

  return (
    <div style={card}>
      <h2 style={h2}>Step 3 — Training &amp; Induction</h2>
      {readiness && (!readiness.training_ok || !readiness.induction_ok) && (
        <Note tone="warn">Both a completed training and a completed induction are needed to clear this step.</Note>
      )}

      <h3 style={h3}>Training</h3>
      <div style={grid}>
        <Field label="Title"><input style={input} value={t.title} onChange={e => setT(p => ({ ...p, title: e.target.value }))} /></Field>
        <Field label="Date"><input type="date" style={input} value={t.training_date} onChange={e => setT(p => ({ ...p, training_date: e.target.value }))} /></Field>
        <Field label="Result">
          <select style={input} value={t.status} onChange={e => setT(p => ({ ...p, status: e.target.value }))}>
            <option>Completed</option><option>Pending</option><option>Failed</option>
          </select>
        </Field>
      </div>
      <button onClick={() => run('training')} disabled={busy || !t.title} style={{ ...primaryBtn, marginTop: 10 }}>
        {busy === 'training' && <Loader2 size={14} className="animate-spin" />} Save training
      </button>

      <h3 style={{ ...h3, marginTop: 22 }}>Induction</h3>
      <div style={grid}>
        <Field label="Date"><input type="date" style={input} value={i.induction_date} onChange={e => setI(p => ({ ...p, induction_date: e.target.value }))} /></Field>
        <Field label="Conducted by"><input style={input} value={i.conducted_by} onChange={e => setI(p => ({ ...p, conducted_by: e.target.value }))} /></Field>
        <Field label="Result">
          <select style={input} value={i.status} onChange={e => setI(p => ({ ...p, status: e.target.value }))}>
            <option>Completed</option><option>Pending</option><option>Failed</option>
          </select>
        </Field>
      </div>
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
      <button onClick={() => run('induction')} disabled={busy} style={{ ...primaryBtn, marginTop: 10 }}>
        {busy === 'induction' && <Loader2 size={14} className="animate-spin" />} Save induction
      </button>
    </div>
  )
}

/** Step 4 — issue from the central Inventory shelf; no warehouse is ever chosen here. */
function PpeStep({ workerId, onChanged }) {
  const [cat, setCat] = useState([])
  const [held, setHeld] = useState([])
  const [sel, setSel] = useState({ inventory_item_id: '', qty: 1, size: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  const load = useCallback(async () => {
    const [c, h] = await Promise.all([
      api.ppe.catalogue().catch(() => []),
      api.ppe.forWorker(workerId).catch(() => []),
    ])
    setCat(Array.isArray(c) ? c : (c?.data ?? []))
    setHeld(Array.isArray(h) ? h : (h?.data ?? []))
  }, [workerId])

  useEffect(() => { load() }, [load])

  const issue = async () => {
    setBusy(true); setErr(null)
    try {
      await api.ppe.issue(workerId, { ...sel, qty: Number(sel.qty) })
      setSel({ inventory_item_id: '', qty: 1, size: '' })
      await load(); await onChanged()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not issue the item.') }
    finally { setBusy(false) }
  }

  const giveBack = async (issueId, condition) => {
    setBusy(true); setErr(null)
    try { await api.ppe.return(issueId, { condition }); await load(); await onChanged() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not record the return.') }
    finally { setBusy(false) }
  }

  return (
    <div style={card}>
      <h2 style={h2}>Step 4 — PPE</h2>
      <Note>Stock comes from the central Inventory. Issuing here reduces the shared on-hand balance.</Note>

      <div style={{ ...grid, marginTop: 12 }}>
        <Field label="Item">
          <select style={input} value={sel.inventory_item_id} onChange={e => setSel(p => ({ ...p, inventory_item_id: e.target.value }))}>
            <option value="">Select PPE…</option>
            {cat.map(c => (
              <option key={c.product_id ?? c.id} value={c.product_id ?? c.id}>
                {c.name} — {c.available} available
              </option>
            ))}
          </select>
        </Field>
        <Field label="Quantity"><input type="number" min="1" style={input} value={sel.qty} onChange={e => setSel(p => ({ ...p, qty: e.target.value }))} /></Field>
        <Field label="Size"><input style={input} value={sel.size} onChange={e => setSel(p => ({ ...p, size: e.target.value }))} /></Field>
      </div>
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
      <button onClick={issue} disabled={busy || !sel.inventory_item_id} style={{ ...primaryBtn, marginTop: 12 }}>
        {busy && <Loader2 size={14} className="animate-spin" />} Issue PPE
      </button>

      <h3 style={{ ...h3, marginTop: 22 }}>Issued to this worker</h3>
      {held.length === 0 ? (
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0 }}>Nothing issued yet.</p>
      ) : (
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)' }}>
                <th style={th}>Item</th><th style={th}>Qty</th><th style={th}>Status</th><th style={th}>Action</th>
              </tr>
            </thead>
            <tbody>
              {held.map(h => (
                <tr key={h.id} style={{ borderTop: '1px solid var(--border)' }}>
                  <td style={td}>{h.item}</td>
                  <td style={td}>{Number(h.qty) - Number(h.returned_qty || 0)}</td>
                  <td style={td}>{h.status}</td>
                  <td style={td}>
                    {h.status === 'issued' && (
                      <span style={{ display: 'inline-flex', gap: 6, flexWrap: 'wrap' }}>
                        <button onClick={() => giveBack(h.id, 'returned')} disabled={busy} style={miniBtn}>Return</button>
                        <button onClick={() => giveBack(h.id, 'lost')} disabled={busy} style={miniBtn}>Lost</button>
                        <button onClick={() => giveBack(h.id, 'damaged')} disabled={busy} style={miniBtn}>Damaged</button>
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

/** Step 5 — status only. Activation is the site's decision, not the vendor's. */
function BadgeStep({ badge, readiness }) {
  const activated = badge?.activated

  return (
    <div style={card}>
      <h2 style={h2}>Step 5 — Entry Badge</h2>

      {activated ? (
        <>
          <Note tone="ok">This worker is activated and cleared for site entry.</Note>
          <div style={{ ...grid, marginTop: 12 }}>
            <Read label="Badge number" value={badge.badge_number} />
            <Read label="Issued" value={badge.badge_issued_at ? new Date(badge.badge_issued_at).toLocaleString() : '—'} />
            <Read label="Valid until" value={badge.badge_valid_until || 'No expiry'} />
            <Read label="Status" value={badge.status} />
          </div>
        </>
      ) : (
        <>
          <Note tone="warn">
            Awaiting activation by the site administrator. You cannot activate a worker yourself.
          </Note>
          {readiness && (
            <ul style={{ margin: '12px 0 0', padding: '0 0 0 18px', fontSize: 12.5, color: 'var(--text-muted)', lineHeight: 1.9 }}>
              <li>Documents — {readiness.documents_ok ? 'done' : 'outstanding'}</li>
              <li>Medical — {readiness.medical_ok ? 'done' : 'outstanding'}</li>
              <li>Training — {readiness.training_ok ? 'done' : 'outstanding'}</li>
              <li>Induction — {readiness.induction_ok ? 'done' : 'outstanding'}</li>
            </ul>
          )}
        </>
      )}
    </div>
  )
}

/* ── bits ─────────────────────────────────────────────────────────── */

const card = { background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 18 }
const h2 = { margin: '0 0 12px', fontSize: 15.5, fontWeight: 800, color: 'var(--text-h)' }
const h3 = { margin: '0 0 8px', fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }
const grid = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12 }
const input = { width: '100%', padding: '8px 10px', borderRadius: 9, fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }
const th = { padding: '8px 10px', fontWeight: 700, fontSize: 11.5 }
const td = { padding: '8px 10px', color: 'var(--text-h)' }
const primaryBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 9, border: 'none', cursor: 'pointer', background: '#0ea5e9', color: '#fff', fontSize: 13, fontWeight: 700 }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 12.5, fontWeight: 700 }
const miniBtn = { padding: '5px 10px', borderRadius: 7, cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 11.5, fontWeight: 700 }
const badgePill = (c) => ({ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '4px 10px', borderRadius: 20, fontSize: 11.5, fontWeight: 700, color: c, background: `${c}1a`, flex: 'none' })

function Field({ label, children }) {
  return (
    <label style={{ display: 'block' }}>
      <span style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '.04em', marginBottom: 5 }}>{label}</span>
      {children}
    </label>
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

function Note({ children, tone = 'info' }) {
  const map = { info: ['#0ea5e9', 'rgba(14,165,233,.08)'], warn: ['#b45309', 'rgba(245,158,11,.10)'], ok: ['#047857', 'rgba(16,185,129,.10)'] }
  const [c, bg] = map[tone] || map.info
  const Icon = tone === 'ok' ? ShieldCheck : AlertTriangle

  return (
    <div style={{ display: 'flex', gap: 9, alignItems: 'flex-start', padding: '10px 12px', borderRadius: 10, background: bg, color: c, fontSize: 12.5, lineHeight: 1.55 }}>
      <Icon size={15} style={{ flex: 'none', marginTop: 1 }} />
      <div>{children}</div>
    </div>
  )
}
