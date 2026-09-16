import { useState, useEffect, useCallback } from 'react'
import { Wallet, Loader2, CheckCircle2, XCircle, AlertTriangle, Plus } from 'lucide-react'
import { useToast } from '@/components/ui/Toast'
import { transportAdvanceApi } from '@/services/transportApi'
import { Chip } from './MasterFormFields'
import { fmtMoney, fmtDateTime, advanceStatusCfg } from '../constants'

/**
 * Advances panel on the trip detail page — SNG-TRN-011, BR-P0-005.
 *
 * Same design language as AllocationPanel, PretripPanel and DispatchPanel,
 * deliberately: the panels sit on one page and nobody should have to learn a
 * fourth vocabulary to read this one.
 *
 * ── THE EXPOSURE BAR IS THE POINT, NOT THE LIST ─────────────────────────
 * BR-P0-005 is a rule about the SUM — "advance request + outstanding balance
 * cannot exceed configured trip exposure". A list of advances alone leaves
 * somebody adding them up in their head to work out whether the next one will
 * be refused. The bar answers that before they type.
 *
 * Every figure comes from the server and NOTHING is summed here. Those are
 * bcmath sums of a DECIMAL column; adding JavaScript numbers would drift from
 * the refusal the server actually gives, and disagreeing with the server about
 * money is worse than not showing the number at all.
 *
 * ── REQUESTING AND APPROVING ARE SEPARATE GRANTS ────────────────────────
 * PERM-006 lets Operations and Dispatcher ASK; PERM-007 does not let them
 * ALLOW. The buttons follow the grants, so somebody who may only request never
 * sees an Approve button that would 403 — and the segregation is visible rather
 * than discovered by being refused.
 *
 * The server adds a narrower rule on top: nobody decides the request they
 * raised, at any seniority. That one cannot be predicted from grants alone, so
 * its refusal arrives as a message and is shown in place.
 */
export default function AdvancesPanel({ trip, canRequest, canApprove, onChanged }) {
  const toast = useToast()
  const [state, setState] = useState({ advances: [], exposure: null })
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(null)
  const [form, setForm] = useState(null)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setState(await transportAdvanceApi.list(trip.id))
    } catch {
      toast.error('Could not load advances')
    } finally {
      setLoading(false)
    }
  }, [trip.id, toast])

  useEffect(() => { load() }, [load])

  const exposure = state.exposure
  const currency = exposure?.currency ?? 'INR'

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    setBusy('request')
    try {
      const res = await transportAdvanceApi.request(trip.id, {
        amount_requested: form.amount,
        purpose: form.purpose || null,
        ...(form.counterparty === 'driver'
          ? { driver_id: Number(form.counterpartyId) || null }
          : { supplier_id: Number(form.counterpartyId) || null }),
      })
      if (!res.ok) { setError(res.message || 'That advance was refused.'); return }
      toast.success('Advance requested')
      setForm(null)
      await load(); onChanged?.()
    } catch {
      toast.error('Could not request the advance')
    } finally { setBusy(null) }
  }

  const decide = async (advance, approve) => {
    let body = {}

    if (approve) {
      // TRP-P0-007 wants the system to show "the permitted amount" — an
      // approver may grant LESS than was asked for, and that difference is the
      // whole point of a policy limit. Pre-filled with the request, so the
      // common case is one Enter.
      const granted = window.prompt(
        'Approve how much? Leave as-is to approve the full request.',
        advance.amount_requested,
      )
      if (granted === null) return
      if (String(granted).trim() !== String(advance.amount_requested)) {
        body = { amount_approved: granted }
      }
    }

    let reason = ''
    if (!approve) {
      reason = window.prompt('Why is this being rejected?') || ''
      if (!reason.trim()) return
    }

    setError('')
    setBusy(advance.id)
    try {
      const res = approve
        ? await transportAdvanceApi.approve(trip.id, advance.id, body)
        : await transportAdvanceApi.reject(trip.id, advance.id, reason)
      if (!res.ok) { setError(res.message || 'That decision was refused.'); return }
      toast.success(approve ? 'Advance approved' : 'Advance rejected')
      await load(); onChanged?.()
    } catch {
      toast.error('Could not record that decision')
    } finally { setBusy(null) }
  }

  if (loading) {
    return <p style={muted}><Loader2 size={13} className="spin" /> Loading advances…</p>
  }

  const remaining = exposure?.remaining ?? '0.00'
  const atLimit = Number(remaining) <= 0

  return (
    <div style={{ marginTop: 12 }}>
      {/* What the policy allows, what is already committed, what is left. */}
      {exposure && (
        <div style={bar}>
          <Figure label="Policy limit" value={fmtMoney(exposure.limit, currency)} />
          <Figure label="Already committed" value={fmtMoney(exposure.committed, currency)} />
          <Figure label="Still available" value={fmtMoney(remaining, currency)}
            tone={atLimit ? 'danger' : 'ok'} />
        </div>
      )}

      {atLimit && (
        <p style={{ ...muted, color: 'var(--danger)' }}>
          <AlertTriangle size={13} /> This trip has reached its advance limit. A further
          request needs an authorised override.
        </p>
      )}

      {error && <p style={{ ...muted, color: 'var(--danger)' }}><AlertTriangle size={13} /> {error}</p>}

      {state.advances.length === 0 ? (
        <p style={muted}>No advance has been requested against this trip.</p>
      ) : (
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, marginTop: 8 }}>
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11 }}>
              <th style={th}>Requested</th><th style={th}>Approved</th>
              <th style={th}>Purpose</th><th style={th}>Status</th><th style={th} />
            </tr>
          </thead>
          <tbody>
            {state.advances.map((a) => (
              <tr key={a.id} style={{ borderTop: '1px solid var(--border)' }}>
                <td style={td}>{fmtMoney(a.amount_requested, a.currency ?? currency)}</td>
                <td style={td}>
                  {a.amount_approved
                    ? fmtMoney(a.amount_approved, a.currency ?? currency)
                    : <span style={{ color: 'var(--text-muted)' }}>—</span>}
                </td>
                <td style={td}>{a.purpose || <span style={{ color: 'var(--text-muted)' }}>—</span>}</td>
                <td style={td}>
                  <Chip cfg={advanceStatusCfg(a.status)} />
                  {a.decided_at && (
                    <div style={{ fontSize: 10, color: 'var(--text-muted)', marginTop: 2 }}>
                      {fmtDateTime(a.decided_at)}
                    </div>
                  )}
                </td>
                <td style={{ ...td, textAlign: 'right' }}>
                  {canApprove && a.status === 'requested' && (
                    <>
                      <button type="button" style={btnGhost} disabled={busy === a.id}
                        onClick={() => decide(a, true)}>
                        <CheckCircle2 size={13} /> Approve
                      </button>
                      <button type="button" style={{ ...btnGhost, color: 'var(--danger)' }}
                        disabled={busy === a.id} onClick={() => decide(a, false)}>
                        <XCircle size={13} /> Reject
                      </button>
                    </>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {canRequest && !form && (
        <button type="button" style={btn} onClick={() => setForm({ counterparty: 'driver' })}>
          <Plus size={14} /> Request an advance
        </button>
      )}

      {canRequest && form && (
        <form onSubmit={submit} style={formBox}>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            <label style={field}>
              <span style={lbl}>Amount</span>
              <input required type="number" step="0.01" min="0.01" style={input}
                value={form.amount ?? ''}
                onChange={(e) => setForm({ ...form, amount: e.target.value })} />
            </label>
            {/* Driver XOR supplier — the server enforces exactly one, so the
                form offers a choice rather than two fields that can both be
                filled and then refused. */}
            <label style={field}>
              <span style={lbl}>Paid to</span>
              <select style={input} value={form.counterparty}
                onChange={(e) => setForm({ ...form, counterparty: e.target.value })}>
                <option value="driver">Driver</option>
                <option value="supplier">Supplier</option>
              </select>
            </label>
            <label style={field}>
              <span style={lbl}>{form.counterparty === 'driver' ? 'Driver' : 'Supplier'} ID</span>
              <input type="number" min="1" style={input} value={form.counterpartyId ?? ''}
                onChange={(e) => setForm({ ...form, counterpartyId: e.target.value })} />
            </label>
          </div>
          <label style={{ ...field, width: '100%' }}>
            <span style={lbl}>Purpose</span>
            <input style={input} maxLength={500} value={form.purpose ?? ''}
              onChange={(e) => setForm({ ...form, purpose: e.target.value })} />
          </label>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="submit" style={btn} disabled={busy === 'request'}>
              {busy === 'request' ? <Loader2 size={14} className="spin" /> : <Wallet size={14} />}
              Request
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

function Figure({ label, value, tone }) {
  return (
    <div>
      <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: .4 }}>
        {label}
      </div>
      <div style={{
        fontSize: 15, fontWeight: 600, fontVariantNumeric: 'tabular-nums',
        color: tone === 'danger' ? 'var(--danger)' : tone === 'ok' ? 'var(--success)' : 'inherit',
      }}>{value}</div>
    </div>
  )
}

const muted = { fontSize: 12, color: 'var(--text-muted)', margin: '12px 0 0', display: 'flex', alignItems: 'center', gap: 6 }
const bar = { display: 'flex', gap: 24, flexWrap: 'wrap', padding: '10px 12px', background: 'var(--surface-2)', borderRadius: 8, marginBottom: 4 }
const th = { padding: '6px 8px', fontWeight: 500 }
const td = { padding: '8px', verticalAlign: 'top' }
const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', marginTop: 10, fontSize: 13, borderRadius: 7, border: '1px solid var(--border)', background: 'var(--surface)', cursor: 'pointer' }
const btnGhost = { ...btn, marginTop: 0, marginLeft: 6, background: 'transparent' }
const formBox = { marginTop: 10, padding: 12, border: '1px solid var(--border)', borderRadius: 8, display: 'flex', flexDirection: 'column', gap: 10 }
const field = { display: 'flex', flexDirection: 'column', gap: 4, minWidth: 140 }
const lbl = { fontSize: 11, color: 'var(--text-muted)' }
const input = { padding: '6px 8px', fontSize: 13, borderRadius: 6, border: '1px solid var(--border)', background: 'var(--surface)', color: 'inherit' }
