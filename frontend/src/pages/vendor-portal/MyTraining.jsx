import { useCallback, useEffect, useMemo, useState } from 'react'
import { GraduationCap, Plus, RefreshCw, Search, X } from 'lucide-react'
import { portalApi } from '@/services/portalApi'
import LoadError from '@/components/ui/LoadError'
import Modal from '@/components/ui/Modal'
import { useToast } from '@/hooks/useToast'

/**
 * Training, in the vendor portal.
 *
 * This was a placeholder in BOTH portals — the nav listed it and the page said
 * "coming soon" — while the register behind it had existed for months. A vendor
 * could not file a Work-at-Height certificate for their own worker, nor see one
 * an admin had filed for them, which is exactly the record a site asks for at
 * the gate.
 *
 * One component for both portals, parameterised by `api`, the way My Work and
 * My Performance already are. The two registers keep their own tables; the
 * screen does not need to know which it is looking at.
 *
 * Adding is deliberately allowed here. Training is a fact about a worker the
 * VENDOR holds the evidence for — unlike a fitness verdict, which needs a
 * doctor, or a safety strike, which is the site's authority to issue.
 */

/** The catalogue both registers share; kept in step with the models' TYPES. */
const TYPES = [
  'Site_Induction', 'HSE_Induction', 'Toolbox', 'Fire', 'Work_At_Height',
  'Electrical', 'Confined_Space', 'Lifting', 'Equipment', 'Emergency_Response',
  'Job_Specific', 'Other',
]

const humanise = (s) => String(s || '—').replace(/_/g, ' ')

const fmtDate = (d) => {
  if (!d) return '—'
  const at = new Date(d)
  return Number.isNaN(at.getTime()) ? '—' : at.toLocaleDateString()
}

/** Expired / expiring / current, from the validity window alone. */
function standing(row) {
  const until = row.valid_until || row.expiry_date
  if (!until) return { label: 'No expiry', tone: '#6b7280' }

  const days = Math.round((new Date(until) - Date.now()) / 864e5)
  if (days < 0) return { label: 'Expired', tone: '#ef4444' }
  if (days <= 30) return { label: `${days}d left`, tone: '#f59e0b' }
  return { label: 'Current', tone: '#10b981' }
}

export default function MyTraining({ api = portalApi }) {
  const [rows, setRows] = useState(null)
  const [error, setError] = useState(null)
  const [search, setSearch] = useState('')
  const [adding, setAdding] = useState(false)

  const load = useCallback(() => {
    setError(null)
    api.trainings()
      .then(d => setRows(Array.isArray(d) ? d : (d?.data ?? [])))
      .catch(e => { setRows([]); setError(e) })
  }, [api])

  useEffect(() => { load() }, [load])

  const shown = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return rows ?? []
    return (rows ?? []).filter(r => [
      r.worker?.full_name, r.worker?.name, r.worker?.worker_code,
      r.title, r.training_type, r.provider,
    ].some(v => String(v ?? '').toLowerCase().includes(q)))
  }, [rows, search])

  return (
    <div>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 21, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
            <GraduationCap size={19} /> Training
          </h1>
          <p style={{ margin: '4px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>
            What your workers hold, and when it runs out. Attach the certificate so the site never has to ask for it.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={load} style={BTN}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setAdding(true)} style={BTN_PRIMARY}><Plus size={15} /> Record training</button>
        </div>
      </header>

      <div style={{ position: 'relative', marginBottom: 12, maxWidth: 380 }}>
        <Search size={15} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
        <input value={search} onChange={e => setSearch(e.target.value)}
          placeholder="Search by worker, code or course…"
          style={{ ...INPUT, paddingLeft: 34, minHeight: 42 }} />
      </div>

      {error ? (
        <LoadError error={error} onRetry={load} />
      ) : (
        <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em' }}>
                  {['Worker', 'Training', 'Provider', 'Completed', 'Valid until', 'Standing'].map(h => (
                    <th key={h} style={TH}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows === null ? (
                  <tr><td colSpan={6} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                ) : shown.length === 0 ? (
                  <tr><td colSpan={6} style={{ padding: 18, color: 'var(--text-muted)' }}>
                    {search ? `Nothing matches “${search}”.` : 'No training recorded yet. Record one so the site can see it.'}
                  </td></tr>
                ) : shown.map(r => {
                  const state = standing(r)
                  return (
                    <tr key={r.id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={{ ...TD, color: 'var(--text-h)', fontWeight: 700 }}>
                        {r.worker?.full_name || r.worker?.name || '—'}
                        <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{r.worker?.worker_code}</div>
                      </td>
                      <td style={TD}>{r.title || humanise(r.training_type)}</td>
                      <td style={TD}>{r.provider || '—'}</td>
                      <td style={TD}>{fmtDate(r.completed_date || r.training_date || r.completed_at)}</td>
                      <td style={TD}>{fmtDate(r.valid_until || r.expiry_date)}</td>
                      <td style={TD}>
                        <span style={{ fontSize: 11, fontWeight: 800, padding: '3px 9px', borderRadius: 999, color: state.tone, background: `${state.tone}22` }}>
                          {state.label}
                        </span>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {adding && (
        <RecordTraining api={api} onClose={() => setAdding(false)} onSaved={() => { setAdding(false); load() }} />
      )}
    </div>
  )
}

/**
 * The form.
 *
 * The worker comes from this vendor's own roster, so a training can never be
 * filed against somebody else's worker — the server checks ownership too, but
 * a list that cannot offer the wrong answer is better than an error afterwards.
 */
function RecordTraining({ api, onClose, onSaved }) {
  const toast = useToast()
  const [workers, setWorkers] = useState([])
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({
    worker_id: '', training_type: '', provider: '',
    completed_date: '', valid_until: '', score: '', notes: '',
  })
  const [file, setFile] = useState(null)

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  useEffect(() => {
    api.workers.list()
      .then(d => setWorkers(d?.data ?? d ?? []))
      .catch(() => setWorkers([]))
  }, [api])

  const save = async () => {
    if (!form.worker_id) return toast.error('Choose the worker this training belongs to.')
    if (!form.training_type) return toast.error('Choose the course.')

    setBusy(true)
    try {
      // Blank fields are dropped rather than sent as "", which a nullable|date
      // rule rejects for no visible reason.
      const filled = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
      const { worker_id, ...data } = filled

      let payload = data
      if (file) {
        payload = new FormData()
        Object.entries(data).forEach(([k, v]) => payload.append(k, v))
        payload.append('certificate_file', file)
      }

      await api.workers.saveTraining(worker_id, payload)
      toast.success('Training recorded.')
      onSaved()
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  return (
    <Modal open onClose={onClose} style={{ width: 'min(560px, 96vw)' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '14px 16px', borderBottom: '1px solid var(--border)' }}>
        <GraduationCap size={17} style={{ color: '#a78bfa' }} />
        <h3 style={{ margin: 0, fontSize: 15, fontWeight: 900, color: 'var(--text-h)' }}>Record training</h3>
        <button onClick={onClose} aria-label="Close" style={{ marginLeft: 'auto', ...BTN, minHeight: 34, padding: '5px 9px' }}>
          <X size={15} />
        </button>
      </div>

      <div style={{ padding: 16, display: 'grid', gap: 12 }}>
        <Field label="Worker *">
          <select value={form.worker_id} onChange={e => set('worker_id', e.target.value)} style={INPUT}>
            <option value="">Choose…</option>
            {workers.map(w => (
              <option key={w.id} value={w.id}>
                {(w.full_name || w.name)}{w.worker_code ? ` · ${w.worker_code}` : ''}
              </option>
            ))}
          </select>
          {workers.length === 0 && (
            <span style={HINT}>You have no workers registered yet — add one under My Workforce first.</span>
          )}
        </Field>

        <Field label="Course *">
          <select value={form.training_type} onChange={e => set('training_type', e.target.value)} style={INPUT}>
            <option value="">Choose…</option>
            {TYPES.map(t => <option key={t} value={t}>{humanise(t)}</option>)}
          </select>
        </Field>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 12 }}>
          <Field label="Provider">
            <input value={form.provider} onChange={e => set('provider', e.target.value)} style={INPUT} />
          </Field>
          <Field label="Score">
            <input type="number" min="0" max="100" value={form.score} onChange={e => set('score', e.target.value)} style={INPUT} />
          </Field>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 12 }}>
          <Field label="Completed on">
            <input type="date" value={form.completed_date} onChange={e => set('completed_date', e.target.value)} style={INPUT} />
          </Field>
          <Field label="Valid until">
            <input type="date" value={form.valid_until} onChange={e => set('valid_until', e.target.value)} style={INPUT} />
          </Field>
        </div>

        <Field label="Certificate">
          <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={e => setFile(e.target.files?.[0] ?? null)}
            style={{ ...INPUT, padding: 6 }} />
          <span style={HINT}>Attach it now and the site never has to ask you for it later.</span>
        </Field>

        <Field label="Notes">
          <textarea rows={3} value={form.notes} onChange={e => set('notes', e.target.value)}
            style={{ ...INPUT, resize: 'vertical' }} />
        </Field>
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, padding: '12px 16px', borderTop: '1px solid var(--border)' }}>
        <button onClick={onClose} style={{ ...BTN, minHeight: 42 }}>Cancel</button>
        <button onClick={save} disabled={busy} style={{ ...BTN_PRIMARY, minHeight: 42, opacity: busy ? 0.6 : 1 }}>
          {busy ? 'Saving…' : 'Save training'}
        </button>
      </div>
    </Modal>
  )
}

const Field = ({ label, children }) => (
  <label style={{ display: 'block' }}>
    <span style={{ display: 'block', fontSize: 11.5, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 4 }}>{label}</span>
    {children}
  </label>
)

const HINT = { display: 'block', fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }

const INPUT = {
  width: '100%', padding: '9px 11px', borderRadius: 10, fontSize: 13,
  background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)',
}

const BTN = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 13px', borderRadius: 9,
  background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)',
  cursor: 'pointer', fontSize: 12.5, fontWeight: 700,
}

const BTN_PRIMARY = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 9,
  background: '#7C3AED', border: 'none', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 800,
}

const TH = { textAlign: 'left', padding: '10px 12px', fontWeight: 700, whiteSpace: 'nowrap' }
const TD = { padding: '10px 12px', color: 'var(--text-muted)', whiteSpace: 'nowrap' }
