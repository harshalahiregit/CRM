import { useState, useEffect, useCallback } from 'react'
import {
  Banknote, Loader2, AlertTriangle, CalendarClock, ShieldCheck, Ban, PhoneCall,
} from 'lucide-react'
import { useToast } from '@/components/ui/Toast'
import { transportCollectionApi } from '@/services/transportApi'
import { Chip } from './MasterFormFields'
import { fmtMoney, fmtDate, fmtDateTime, collectionStatusCfg } from '../constants'

/**
 * Receivable panel — SNG-TRN-016.
 *
 * ── THE FOUR NOUNS ARE THE LAYOUT ───────────────────────────────────────
 * "Due dates, blockers, follow-up and audit trail." The panel shows the balance
 * and the due date first, the blocker next if there is one, then the follow-up
 * controls. The audit trail is the trip's existing timeline, so it is not
 * repeated here — a second history beside the first is how two versions of
 * events come to disagree.
 *
 * ── IT RECORDS A RECEIPT; IT DOES NOT TAKE PAYMENT ──────────────────────
 * Accounts posts the money (EVT-011, CTR-014's "posting event generated"). So
 * the control says "Record a receipt", and nothing here offers to collect.
 * A button promising more than the module does is how somebody ends up waiting
 * for a posting that never happens.
 *
 * ── OVERDUE IS SHOWN, NOT COMPUTED ──────────────────────────────────────
 * Days overdue and the outstanding balance come from the server. They are
 * bcmath figures over DECIMAL columns and they drive an ageing report; a screen
 * arriving at a different number than the report is worse than one showing no
 * number.
 */
export default function CollectionPanel({ trip, canRecord, onChanged }) {
  const toast = useToast()
  const [c, setC] = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(null)
  const [form, setForm] = useState(null)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setC(await transportCollectionApi.get(trip.id))
    } catch {
      toast.error('Could not load the receivable')
    } finally {
      setLoading(false)
    }
  }, [trip.id, toast])

  useEffect(() => { load() }, [load])

  const act = async (label, fn) => {
    setError('')
    setBusy(label)
    try {
      const res = await fn()
      if (res && res.ok === false) { setError(res.message || 'That was refused.'); return false }
      await load(); onChanged?.()
      return true
    } catch {
      toast.error('Could not complete that')
      return false
    } finally { setBusy(null) }
  }

  if (loading) {
    return <p style={muted}><Loader2 size={13} className="spin" /> Loading receivable…</p>
  }

  /* Not opened yet. */
  if (!c) {
    return (
      <div style={{ marginTop: 12 }}>
        <p style={muted}>
          No receivable has been opened for this trip. It is created once Accounts
          has raised the invoice.
        </p>
        {error && <p style={{ ...muted, color: 'var(--danger)' }}><AlertTriangle size={13} /> {error}</p>}
        {canRecord && (
          <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginTop: 10, flexWrap: 'wrap' }}>
            <label style={field}>
              <span style={lbl}>Due date</span>
              <input type="date" style={input} value={form?.due ?? ''}
                onChange={(e) => setForm({ ...form, due: e.target.value })} />
            </label>
            <button type="button" style={btn} disabled={busy === 'open'}
              onClick={() => act('open', () => transportCollectionApi.open(trip.id, form?.due))}>
              {busy === 'open' ? <Loader2 size={14} className="spin" /> : <Banknote size={14} />}
              Open receivable
            </button>
          </div>
        )}
      </div>
    )
  }

  const overdue = c.days_overdue
  const settled = c.status === 'settled'

  return (
    <div style={{ marginTop: 12 }}>
      <div style={{ ...bar, borderLeft: `3px solid ${settled ? 'var(--success)' : '#fbbf24'}` }}>
        {settled
          ? <ShieldCheck size={16} style={{ color: 'var(--success)', flexShrink: 0 }} />
          : <Banknote size={16} style={{ color: '#fbbf24', flexShrink: 0 }} />}
        <div style={{ flex: 1, display: 'flex', gap: 20, flexWrap: 'wrap' }}>
          <Figure label="Invoiced" value={fmtMoney(c.amount_due, c.currency)} />
          <Figure label="Received" value={fmtMoney(c.amount_received, c.currency)} />
          <Figure label="Outstanding" value={fmtMoney(c.outstanding, c.currency)}
            tone={settled ? 'ok' : 'warn'} />
          <div>
            <div style={capLbl}>Status</div>
            <Chip cfg={collectionStatusCfg(c.status)} />
          </div>
        </div>
      </div>

      <div style={{ ...metaRow }}>
        <span><CalendarClock size={12} /> Due {c.due_date ? fmtDate(c.due_date) : 'not set'}</span>
        {/* Days overdue comes from the server — the same figure the ageing
            report buckets on. Recomputing it here would drift the moment the
            two sides disagreed about "today". */}
        {!settled && overdue !== null && overdue > 0 && (
          <span style={{ color: 'var(--danger)', fontWeight: 600 }}>
            {overdue} day{overdue === 1 ? '' : 's'} overdue
          </span>
        )}
        {c.last_followed_up_at && (
          <span><PhoneCall size={12} /> Last chased {fmtDateTime(c.last_followed_up_at)}</span>
        )}
        {c.next_follow_up_on && (
          <span><CalendarClock size={12} /> Next chase {fmtDate(c.next_follow_up_on)}</span>
        )}
      </div>

      {/* Blockers sit above the controls: if something is stuck, that is the
          thing to read before deciding what to do. */}
      {c.blocker_reason && (
        <div style={{ ...bar, borderLeft: '3px solid var(--danger)', marginTop: 8 }}>
          <Ban size={15} style={{ color: 'var(--danger)', flexShrink: 0 }} />
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 12, fontWeight: 600 }}>Blocked</div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{c.blocker_reason}</div>
          </div>
          {canRecord && (
            <button type="button" style={btnGhost} disabled={busy === 'unblock'}
              onClick={() => act('unblock', () =>
                transportCollectionApi.update(trip.id, { blocker_reason: null }))}>
              Clear
            </button>
          )}
        </div>
      )}

      {error && <p style={{ ...muted, color: 'var(--danger)' }}><AlertTriangle size={13} /> {error}</p>}

      {canRecord && !settled && (
        <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
          <button type="button" style={btn} onClick={() => setForm({ mode: 'receipt' })}>
            <Banknote size={14} /> Record a receipt
          </button>
          {!c.blocker_reason && (
            <button type="button" style={btn} onClick={() => setForm({ mode: 'block' })}>
              <Ban size={14} /> Flag a blocker
            </button>
          )}
          <button type="button" style={btn} onClick={() => setForm({ mode: 'follow' })}>
            <PhoneCall size={14} /> Log a chase
          </button>
        </div>
      )}

      {form?.mode === 'receipt' && (
        <form style={formBox} onSubmit={async (e) => {
          e.preventDefault()
          if (await act('receipt', () =>
            transportCollectionApi.record(trip.id, form.amount, form.reference))) setForm(null)
        }}>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            <label style={field}>
              <span style={lbl}>Amount received</span>
              <input required type="number" step="0.01" min="0.01" max={c.outstanding} style={input}
                value={form.amount ?? ''} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
            </label>
            <label style={field}>
              <span style={lbl}>Reference</span>
              <input style={input} maxLength={120} placeholder="UTR / cheque no."
                value={form.reference ?? ''} onChange={(e) => setForm({ ...form, reference: e.target.value })} />
            </label>
          </div>
          <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: 0 }}>
            At most {fmtMoney(c.outstanding, c.currency)}. Accounts posts the money; this records it.
          </p>
          <Actions busy={busy === 'receipt'} onCancel={() => { setForm(null); setError('') }} />
        </form>
      )}

      {form?.mode === 'block' && (
        <form style={formBox} onSubmit={async (e) => {
          e.preventDefault()
          if (await act('block', () =>
            transportCollectionApi.update(trip.id, { blocker_reason: form.reason }))) setForm(null)
        }}>
          <label style={{ ...field, width: '100%' }}>
            <span style={lbl}>Why is this stuck?</span>
            <input required style={input} maxLength={500} value={form.reason ?? ''}
              onChange={(e) => setForm({ ...form, reason: e.target.value })} />
          </label>
          <Actions busy={busy === 'block'} onCancel={() => { setForm(null); setError('') }} />
        </form>
      )}

      {form?.mode === 'follow' && (
        <form style={formBox} onSubmit={async (e) => {
          e.preventDefault()
          if (await act('follow', () => transportCollectionApi.update(trip.id, {
            note: form.note || null, next_follow_up_on: form.next || null,
          }))) setForm(null)
        }}>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            <label style={{ ...field, flex: 1, minWidth: 200 }}>
              <span style={lbl}>What happened?</span>
              <input style={input} maxLength={500} value={form.note ?? ''}
                onChange={(e) => setForm({ ...form, note: e.target.value })} />
            </label>
            <label style={field}>
              <span style={lbl}>Chase again on</span>
              <input type="date" style={input} value={form.next ?? ''}
                onChange={(e) => setForm({ ...form, next: e.target.value })} />
            </label>
          </div>
          <Actions busy={busy === 'follow'} onCancel={() => { setForm(null); setError('') }} />
        </form>
      )}
    </div>
  )
}

function Actions({ busy, onCancel }) {
  return (
    <div style={{ display: 'flex', gap: 8 }}>
      <button type="submit" style={btn} disabled={busy}>
        {busy ? <Loader2 size={14} className="spin" /> : null} Save
      </button>
      <button type="button" style={btnGhost} onClick={onCancel}>Cancel</button>
    </div>
  )
}

function Figure({ label, value, tone }) {
  return (
    <div>
      <div style={capLbl}>{label}</div>
      <div style={{
        fontSize: 15, fontWeight: 600, fontVariantNumeric: 'tabular-nums',
        color: tone === 'warn' ? '#fbbf24' : tone === 'ok' ? 'var(--success)' : 'inherit',
      }}>{value}</div>
    </div>
  )
}

const muted = { fontSize: 12, color: 'var(--text-muted)', margin: '12px 0 0', display: 'flex', alignItems: 'center', gap: 6 }
const bar = { display: 'flex', gap: 10, alignItems: 'center', padding: '10px 12px', background: 'var(--surface-2)', borderRadius: 8 }
const metaRow = { display: 'flex', gap: 16, flexWrap: 'wrap', fontSize: 11, color: 'var(--text-muted)', marginTop: 8, alignItems: 'center' }
const capLbl = { fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: .4 }
const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', fontSize: 13, borderRadius: 7, border: '1px solid var(--border)', background: 'var(--surface)', cursor: 'pointer' }
const btnGhost = { ...btn, background: 'transparent' }
const formBox = { marginTop: 10, padding: 12, border: '1px solid var(--border)', borderRadius: 8, display: 'flex', flexDirection: 'column', gap: 10 }
const field = { display: 'flex', flexDirection: 'column', gap: 4, minWidth: 150 }
const lbl = { fontSize: 11, color: 'var(--text-muted)' }
const input = { padding: '6px 8px', fontSize: 13, borderRadius: 6, border: '1px solid var(--border)', background: 'var(--surface)', color: 'inherit' }
