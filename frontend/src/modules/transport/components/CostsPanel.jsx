import { useState, useEffect, useCallback } from 'react'
import { IndianRupee, Loader2, Plus, Trash2, AlertTriangle } from 'lucide-react'
import { useToast } from '@/components/ui/Toast'
import { transportCostApi } from '@/services/transportApi'
import { fmtMoney, fmtDate } from '../constants'

/**
 * Trip costs panel — SNG-TRN-012.
 *
 * ── THE BREAKDOWN IS THE POINT ──────────────────────────────────────────
 * IDX-005 exists "for profitability calculations" and SNG-TRN-018's acceptance
 * is "revenue, cost and margin reconcile to source transactions". The per-type
 * breakdown is what that reconciliation will read, so it is shown here — a flat
 * list of rows leaves somebody grouping fuel against tolls by eye.
 *
 * `total` and `breakdown` come from the SERVER and nothing is summed in this
 * file. They are bcmath sums of a DECIMAL column; adding JavaScript numbers
 * would drift from the figure 018 reports, and a screen that disagrees with the
 * margin is worse than one that shows no margin.
 *
 * ── THE TYPE PICKER SUGGESTS, IT DOES NOT CONSTRAIN ─────────────────────
 * `cost_type` has no registered vocabulary — CST-001 is a dangling pointer
 * (D-58) — so the server accepts any text and folds case and spacing so that
 * `Fuel`, `FUEL` and `fuel` group together. The input is a datalist rather than
 * a select for exactly that reason: it offers the common spellings and still
 * lets somebody record a ferry charge nobody listed.
 *
 * ── RETRACTING IS NOT DELETING ──────────────────────────────────────────
 * A cost leaves the margin but the row survives, because 018 has to be able to
 * explain a figure that changed. The button says "Retract" and asks for a
 * reason, so nobody expects the row to vanish.
 */
export default function CostsPanel({ trip, canRecord, canRetract, onChanged }) {
  const toast = useToast()
  const [state, setState] = useState({ costs: [], total: '0.00', breakdown: {}, known_types: [] })
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(null)
  const [form, setForm] = useState(null)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setState(await transportCostApi.list(trip.id))
    } catch {
      toast.error('Could not load trip costs')
    } finally {
      setLoading(false)
    }
  }, [trip.id, toast])

  useEffect(() => { load() }, [load])

  const currency = state.currency ?? 'INR'

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    setBusy('record')
    try {
      const res = await transportCostApi.record(trip.id, {
        cost_type: form.cost_type,
        amount: form.amount,
        incurred_on: form.incurred_on || null,
        notes: form.notes || null,
        ...(form.confirmDuplicate ? { confirm_duplicate: true } : {}),
      })
      if (!res.ok) {
        // The look-alike refusal is recoverable: the server is asking whether
        // this really is a second genuine cost, so the form offers that answer
        // rather than making somebody retype everything.
        setError(res.message || 'That cost was refused.')
        if ((res.message || '').includes('confirm_duplicate')) {
          setForm({ ...form, offerConfirm: true })
        }
        return
      }
      toast.success('Cost recorded')
      setForm(null)
      await load(); onChanged?.()
    } catch {
      toast.error('Could not record the cost')
    } finally { setBusy(null) }
  }

  const retract = async (cost) => {
    const reason = window.prompt('Why is this cost being retracted?')
    if (!reason) return
    setBusy(cost.id)
    try {
      const res = await transportCostApi.retract(trip.id, cost.id, reason)
      if (!res.ok) { setError(res.message || 'That could not be retracted.'); return }
      toast.success('Cost retracted')
      await load(); onChanged?.()
    } catch {
      toast.error('Could not retract the cost')
    } finally { setBusy(null) }
  }

  if (loading) {
    return <p style={muted}><Loader2 size={13} className="spin" /> Loading costs…</p>
  }

  const types = Object.entries(state.breakdown ?? {})

  return (
    <div style={{ marginTop: 12 }}>
      <div style={bar}>
        <div>
          <div style={capLbl}>Total cost</div>
          <div style={{ fontSize: 17, fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>
            {fmtMoney(state.total, currency)}
          </div>
        </div>
        {types.length > 0 && (
          <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            {types.map(([type, amount]) => (
              <div key={type}>
                <div style={capLbl}>{type.replace(/_/g, ' ')}</div>
                <div style={{ fontSize: 13, fontVariantNumeric: 'tabular-nums' }}>
                  {fmtMoney(amount, currency)}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {error && <p style={{ ...muted, color: 'var(--danger)' }}><AlertTriangle size={13} /> {error}</p>}

      {state.costs.length === 0 ? (
        <p style={muted}>No cost has been recorded against this trip yet.</p>
      ) : (
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, marginTop: 8 }}>
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11 }}>
              <th style={th}>Type</th><th style={th}>Amount</th><th style={th}>Incurred</th>
              <th style={th}>Source</th><th style={th} />
            </tr>
          </thead>
          <tbody>
            {state.costs.map((c) => (
              <tr key={c.id} style={{ borderTop: '1px solid var(--border)' }}>
                <td style={td}>{c.cost_type_label ?? c.cost_type}</td>
                <td style={{ ...td, fontVariantNumeric: 'tabular-nums' }}>
                  {fmtMoney(c.amount, c.currency ?? currency)}
                </td>
                <td style={td}>{c.incurred_on ? fmtDate(c.incurred_on) : '—'}</td>
                <td style={td}>
                  <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                    {c.source}{c.source_ref ? ` · ${c.source_ref}` : ''}
                  </span>
                </td>
                <td style={{ ...td, textAlign: 'right' }}>
                  {canRetract && (
                    <button type="button" style={{ ...btnGhost, color: 'var(--danger)' }}
                      disabled={busy === c.id} onClick={() => retract(c)} title="Retract this cost">
                      <Trash2 size={13} /> Retract
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {canRecord && !form && (
        <button type="button" style={btn} onClick={() => setForm({})}>
          <Plus size={14} /> Record a cost
        </button>
      )}

      {canRecord && form && (
        <form onSubmit={submit} style={formBox}>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            <label style={field}>
              <span style={lbl}>Type</span>
              {/* A datalist, not a select — the vocabulary is open (D-58). */}
              <input required list="trip-cost-types" style={input} maxLength={40}
                value={form.cost_type ?? ''} placeholder="fuel"
                onChange={(e) => setForm({ ...form, cost_type: e.target.value })} />
              <datalist id="trip-cost-types">
                {(state.known_types ?? []).map((t) => <option key={t} value={t} />)}
              </datalist>
            </label>
            <label style={field}>
              <span style={lbl}>Amount</span>
              <input required type="number" step="0.01" min="0.01" style={input}
                value={form.amount ?? ''}
                onChange={(e) => setForm({ ...form, amount: e.target.value })} />
            </label>
            <label style={field}>
              <span style={lbl}>Incurred on</span>
              <input type="date" style={input} max={new Date().toISOString().slice(0, 10)}
                value={form.incurred_on ?? ''}
                onChange={(e) => setForm({ ...form, incurred_on: e.target.value })} />
            </label>
          </div>
          <label style={{ ...field, width: '100%' }}>
            <span style={lbl}>Notes</span>
            <input style={input} maxLength={500} value={form.notes ?? ''}
              onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          </label>

          {form.offerConfirm && (
            <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 12 }}>
              <input type="checkbox" checked={!!form.confirmDuplicate}
                onChange={(e) => setForm({ ...form, confirmDuplicate: e.target.checked })} />
              Yes, this is a second genuine cost — record it anyway.
            </label>
          )}

          <div style={{ display: 'flex', gap: 8 }}>
            <button type="submit" style={btn} disabled={busy === 'record'}>
              {busy === 'record' ? <Loader2 size={14} className="spin" /> : <IndianRupee size={14} />}
              Record
            </button>
            <button type="button" style={btnGhost} onClick={() => { setForm(null); setError('') }}>
              Cancel
            </button>
          </div>
        </form>
      )}
    </div>
  )
}

const muted = { fontSize: 12, color: 'var(--text-muted)', margin: '12px 0 0', display: 'flex', alignItems: 'center', gap: 6 }
const bar = { display: 'flex', gap: 24, flexWrap: 'wrap', justifyContent: 'space-between', padding: '10px 12px', background: 'var(--surface-2)', borderRadius: 8 }
const capLbl = { fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: .4 }
const th = { padding: '6px 8px', fontWeight: 500 }
const td = { padding: '8px', verticalAlign: 'top' }
const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', marginTop: 10, fontSize: 13, borderRadius: 7, border: '1px solid var(--border)', background: 'var(--surface)', cursor: 'pointer' }
const btnGhost = { ...btn, marginTop: 0, marginLeft: 6, background: 'transparent' }
const formBox = { marginTop: 10, padding: 12, border: '1px solid var(--border)', borderRadius: 8, display: 'flex', flexDirection: 'column', gap: 10 }
const field = { display: 'flex', flexDirection: 'column', gap: 4, minWidth: 140 }
const lbl = { fontSize: 11, color: 'var(--text-muted)' }
const input = { padding: '6px 8px', fontSize: 13, borderRadius: 6, border: '1px solid var(--border)', background: 'var(--surface)', color: 'inherit' }
