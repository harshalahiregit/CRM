import { useState, useEffect, useCallback } from 'react'
import { AlertTriangle, Loader2, CheckCircle2, Clock, Info, Plus } from 'lucide-react'
import { useToast } from '@/components/ui/Toast'
import { transportExceptionApi } from '@/services/transportApi'
import { fmtDateTime } from '../constants'

/**
 * The exception register on a trip — SNG-TRN-013, step 9 of MS-001 §14.
 *
 * ── WHAT THIS PANEL IS FOR ───────────────────────────────────────────────
 * "Something went wrong and somebody owns it." Two acts and no others:
 * acknowledge it (STT-015, which names an owner and starts the SLA clock) and
 * resolve it (STT-016, which requires evidence and stops the clock). The four
 * statuses between them are declared and unreachable, so the panel never offers
 * a control that reaches one.
 *
 * ── THE VOCABULARY COMES FROM THE SERVER ────────────────────────────────
 * Categories, severities and which statuses are reachable all arrive on the
 * payload. Nothing here hardcodes OPS §88's eight — D-37 records that the list
 * exists in exactly one document, and a client keeping its own copy has nothing
 * to check it against.
 *
 * ── THE SLA LABEL SAYS WHAT KIND OF CLOCK IT IS ─────────────────────────
 * Wall clock, per the owner's Q5. BRWM §58 wants seven elements for an SLA
 * including a business calendar and §59 wants that calendar to model working
 * hours, weekends, holidays, branch and customer; none has an entity (D-34). So
 * "overdue" here means elapsed time, and the panel says so rather than letting
 * a reader assume working hours were counted.
 *
 * ── AND THE WAIVER LINE ─────────────────────────────────────────────────
 * BR-P0-011 lets an Owner waive the evidence requirement. It is not built
 * (D-30), so the panel says NOT BUILT YET — never that no waiver exists. A user
 * told "this cannot be waived" when their own rule book says it can is being
 * misled about their own business.
 */
/**
 * @param {boolean} compact
 *   Rendered inline above the tracker rather than inside a drawer. An OPEN
 *   exception can refuse a close, so it has to be visible without a click — but
 *   a trip's resolved history does not, and showing it there pushed the tracker
 *   300px down a screen whose job is "what do I do next". Compact shows what is
 *   still open plus the way to report a new one, and folds everything resolved
 *   behind one line.
 */
export default function ExceptionsPanel({ trip, canRaise, canManage, onChanged, compact }) {
  const [showResolved, setShowResolved] = useState(false)
  const toast = useToast()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [adding, setAdding] = useState(false)
  const [form, setForm] = useState({ category: '', severity: 'medium', cause: '' })
  const [refusal, setRefusal] = useState(null)
  const [resolving, setResolving] = useState(null)
  const [note, setNote] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try { setData(await transportExceptionApi.forTrip(trip.id)) } finally { setLoading(false) }
  }, [trip.id])

  useEffect(() => { load() }, [load])

  const rows = data?.exceptions ?? []
  const vocab = data?.vocabulary
  const summary = data?.summary

  const raise = async () => {
    setBusy(true); setRefusal(null)
    try {
      const res = await transportExceptionApi.raise(trip.id, form)
      if (!res.ok) { setRefusal(res.message); return }
      toast.success('Exception raised.')
      setForm({ category: '', severity: 'medium', cause: '' }); setAdding(false)
      load(); onChanged?.()
    } finally { setBusy(false) }
  }

  const act = async (fn, ok) => {
    setBusy(true); setRefusal(null)
    try {
      const res = await fn()
      if (!res.ok) { setRefusal(res.message); return }
      toast.success(ok)
      setResolving(null); setNote('')
      load(); onChanged?.()
    } finally { setBusy(false) }
  }

  if (loading) {
    return (
      <p style={{ ...muted, display: 'flex', gap: 7, alignItems: 'center' }}>
        <Loader2 size={13} className="animate-spin" /> Checking…
      </p>
    )
  }

  // In compact mode the OPEN ones are the point; resolved history is one line
  // away rather than 300px of it above the tracker.
  const closedCount = rows.filter((e) => !e.is_open).length
  const visible = compact && !showResolved ? rows.filter((e) => e.is_open) : rows

  return (
    <div style={{ display: 'grid', gap: 12 }}>
      {summary?.total > 0 && !compact && (
        <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', fontSize: 11.5 }}>
          <Stat label="Open" value={summary.open} tone={summary.open ? 'var(--color-warning-500)' : 'var(--text-muted)'} />
          <Stat label="Critical open" value={summary.critical_open} tone={summary.critical_open ? 'var(--color-danger-500, #f87171)' : 'var(--text-muted)'} />
          <Stat label="Overdue" value={summary.overdue} tone={summary.overdue ? 'var(--color-danger-500, #f87171)' : 'var(--text-muted)'} />
        </div>
      )}

      {rows.length === 0 && !adding && !compact && (
        <p style={muted}>Nothing has gone wrong on this trip — or nothing has been recorded.</p>
      )}

      {compact && closedCount > 0 && !showResolved && (
        <button type="button" onClick={() => setShowResolved(true)}
          style={{ ...muted, textAlign: 'left', cursor: 'pointer', background: 'none', border: 0, padding: 0 }}>
          {closedCount} resolved — show
        </button>
      )}

      {visible.map((e) => (
        <div key={e.id} style={{
          padding: '11px 12px', borderRadius: 10, background: 'var(--bg-input)',
          border: `1px solid ${e.is_open ? 'var(--color-warning-500)' : 'var(--border)'}`,
        }}>
          <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', flexWrap: 'wrap' }}>
            <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)' }}>{e.exception_number}</span>
            <Chip text={e.severity_label} tone={e.severity === 'critical' || e.severity === 'high'
              ? 'var(--color-danger-500, #f87171)' : 'var(--text-muted)'} />
            <Chip text={e.category_label} tone="var(--text-muted)" />
            <Chip text={e.status_label} tone={e.is_open ? 'var(--color-warning-500)' : 'var(--color-success-500)'} />
            {e.sla_state === 'overdue' && <Chip text="Overdue" tone="var(--color-danger-500, #f87171)" />}
          </div>

          <p style={{ margin: '6px 0 0', fontSize: 12.5, color: 'var(--text-b)', lineHeight: 1.5 }}>{e.cause}</p>

          <p style={{ margin: '5px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>
            Raised {fmtDateTime(e.raised_at)}
            {e.due_at && e.is_open ? ` · due ${fmtDateTime(e.due_at)}` : ''}
            {e.resolved_at ? ` · resolved ${fmtDateTime(e.resolved_at)}` : ''}
          </p>

          {e.resolution_note && (
            <p style={{ margin: '6px 0 0', fontSize: 11.5, color: 'var(--text-b)', lineHeight: 1.5 }}>
              <CheckCircle2 size={11} style={{ color: 'var(--color-success-500)', verticalAlign: 'middle', marginRight: 4 }} />
              {e.resolution_note}
            </p>
          )}

          {/* Only the two acts that exist, and only when the API would allow them. */}
          {canManage && e.can_acknowledge && (
            <button disabled={busy} onClick={() => act(() => transportExceptionApi.acknowledge(e.id), 'Exception acknowledged.')}
              style={{ ...ghost, marginTop: 8 }}>
              <Clock size={12} /> Acknowledge &amp; own it
            </button>
          )}

          {canManage && e.can_resolve && resolving !== e.id && (
            <button disabled={busy} onClick={() => { setResolving(e.id); setNote('') }} style={{ ...ghost, marginTop: 8 }}>
              <CheckCircle2 size={12} /> Resolve
            </button>
          )}

          {resolving === e.id && (
            <div style={{ display: 'grid', gap: 7, marginTop: 9 }}>
              <textarea value={note} onChange={(ev) => setNote(ev.target.value)} rows={2}
                placeholder="How was it resolved?" style={field} />
              <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                BR-P0-011 requires resolution evidence, and a note is the evidence this build can take.
              </span>
              <div style={{ display: 'flex', gap: 7 }}>
                <button disabled={busy || note.trim().length < 12}
                  onClick={() => act(() => transportExceptionApi.resolve(e.id, note), 'Exception resolved.')}
                  style={{ ...primary, opacity: busy || note.trim().length < 12 ? 0.55 : 1 }}>
                  Resolve
                </button>
                <button disabled={busy} onClick={() => setResolving(null)} style={ghost}>Cancel</button>
              </div>
            </div>
          )}
        </div>
      ))}

      {refusal && (
        <div style={{
          display: 'flex', gap: 8, alignItems: 'flex-start', padding: '10px 12px', borderRadius: 10,
          background: 'rgba(248,113,113,0.10)', border: '1px solid rgba(248,113,113,0.30)',
        }}>
          <Info size={14} style={{ color: 'var(--color-danger-500, #f87171)', flexShrink: 0, marginTop: 1 }} />
          <p style={{ margin: 0, fontSize: 11.5, color: 'var(--text-b)', lineHeight: 1.5 }}>{refusal}</p>
        </div>
      )}

      {canRaise && !adding && (
        <button onClick={() => { setAdding(true); setRefusal(null) }} style={{ ...ghost, justifySelf: 'start' }}>
          <Plus size={12} /> Raise an exception
        </button>
      )}

      {adding && vocab && (
        <div style={{ display: 'grid', gap: 8, padding: '11px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <label style={lbl}>What kind?
            <select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} style={field}>
              <option value="">Choose a category…</option>
              {vocab.categories.map((c) => (
                <option key={c.value} value={c.value}>{c.label}{c.gloss ? ` — ${c.gloss}` : ''}</option>
              ))}
            </select>
          </label>

          <label style={lbl}>How bad?
            <select value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value })} style={field}>
              {vocab.severities.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
            </select>
          </label>

          <label style={lbl}>What went wrong?
            <textarea value={form.cause} onChange={(e) => setForm({ ...form, cause: e.target.value })} rows={2}
              placeholder="Whoever picks this up has only this sentence to go on." style={field} />
          </label>

          <div style={{ display: 'flex', gap: 7 }}>
            <button disabled={busy || !form.category || form.cause.trim().length < 8} onClick={raise}
              style={{ ...primary, opacity: busy || !form.category || form.cause.trim().length < 8 ? 0.55 : 1 }}>
              {busy ? <Loader2 size={12} className="animate-spin" /> : <AlertTriangle size={12} />} Raise it
            </button>
            <button disabled={busy} onClick={() => setAdding(false)} style={ghost}>Cancel</button>
          </div>
        </div>
      )}

      {/* Q5 and D-30, on screen rather than only in the code — but NOT in the
          compact view. It is a caveat about how due times are measured and it
          quotes a rule id; inline above the tracker it became the longest piece
          of text on a screen whose job is "what do I do next". It belongs where
          somebody is reading an exception, not where they are glancing at one. */}
      {!compact && (
      <p style={{ margin: 0, fontSize: 11, color: 'var(--text-muted)', lineHeight: 1.5, paddingTop: 8, borderTop: '1px solid var(--border)' }}>
        Due times are elapsed clock time — working hours, weekends and holidays are not modelled yet.
        {vocab?.waiver ? ` ${vocab.waiver}` : ''}
      </p>
      )}
    </div>
  )
}

function Stat({ label, value, tone }) {
  return (
    <span style={{ color: 'var(--text-muted)' }}>
      {label} <strong style={{ color: tone, fontSize: 13 }}>{value}</strong>
    </span>
  )
}

function Chip({ text, tone }) {
  return (
    <span style={{ fontSize: 10.5, fontWeight: 800, color: tone, border: `1px solid ${tone}`, padding: '1px 7px', borderRadius: 999 }}>
      {text}
    </span>
  )
}

const muted = { fontSize: 12, color: 'var(--text-muted)', margin: 0, lineHeight: 1.5 }
const lbl = { display: 'grid', gap: 4, fontSize: 11.5, fontWeight: 700, color: 'var(--text-muted)' }
const field = {
  padding: '8px 10px', borderRadius: 8, fontSize: 12.5, resize: 'vertical',
  border: '1px solid var(--border)', background: 'var(--bg-card, var(--bg-input))', color: 'var(--text-b)',
}
const primary = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9,
  border: 'none', background: 'var(--accent)', color: '#fff', fontSize: 12.5, fontWeight: 800, cursor: 'pointer',
}
const ghost = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9,
  border: '1px solid var(--border)', background: 'var(--bg-card, var(--bg-input))', color: 'var(--text-p)',
  fontSize: 12, fontWeight: 700, cursor: 'pointer',
}
