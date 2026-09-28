import { useState, useEffect, useCallback } from 'react'
import { Receipt, Loader2, ShieldCheck, AlertTriangle, ArrowRight } from 'lucide-react'
import { useToast } from '@/components/ui/Toast'
import { transportBillingApi } from '@/services/transportApi'
import { fmtMoney, fmtDateTime } from '../constants'

/**
 * Billing trigger panel — SNG-TRN-015, API-010.
 *
 * ── THE BUTTON DOES NOT RAISE AN INVOICE, AND SAYS SO ───────────────────
 * Step 11 puts the invoice on the other side of a wall: EVT-010 InvoicePosted
 * is produced by **Accounts**, and FORBID-002 / LOCK-004 forbid Transport
 * writing any accounting entry. What this panel does is mark a trip ready and
 * hand it over.
 *
 * So the button reads "Mark ready to invoice", not "Bill this trip", and the
 * panel says plainly what happens next. A control that over-promises is how
 * somebody ends up waiting for an invoice nobody raised.
 *
 * ── THE REASON IS THE INTERFACE ─────────────────────────────────────────
 * "No billing without defined preconditions" is the whole ticket, so the
 * blocker matters more than the button. The server returns the same sentence
 * the refusal would carry, and it is shown whether or not the user may act on
 * it — a blocker only the biller can read is a blocker nobody fixes.
 */
export default function BillingPanel({ trip, canPrepare, canInvoice, onChanged }) {
  const toast = useToast()
  const [state, setState] = useState({ readiness: null, bill: null })
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [invoiceId, setInvoiceId] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setState(await transportBillingApi.get(trip.id))
    } catch {
      toast.error('Could not load billing readiness')
    } finally {
      setLoading(false)
    }
  }, [trip.id, toast])

  useEffect(() => { load() }, [load])

  const prepare = async () => {
    setError('')
    setBusy(true)
    try {
      const res = await transportBillingApi.prepare(trip.id)
      if (!res.ok) { setError(res.message || 'That could not be prepared.'); return }
      toast.success('Marked ready to invoice')
      await load(); onChanged?.()
    } catch {
      toast.error('Could not prepare billing')
    } finally { setBusy(false) }
  }

  /**
   * STT-010 — Accounts raised the invoice; record its number.
   *
   * The refusal is shown in place rather than thrown: the service refuses a
   * SECOND, different invoice id against an already-linked bill, and "this trip
   * is already linked to invoice 4242" is exactly the sentence the person needs.
   */
  const recordInvoice = async (e) => {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      const res = await transportBillingApi.markInvoiced(trip.id, Number(invoiceId))
      if (!res.ok) { setError(res.message || 'That could not be recorded.'); return }
      toast.success('Invoice recorded')
      setInvoiceId('')
      await load(); onChanged?.()
    } catch {
      toast.error('Could not record the invoice')
    } finally { setBusy(false) }
  }

  if (loading) {
    return <p style={muted}><Loader2 size={13} className="spin" /> Loading billing status…</p>
  }

  const { readiness, bill } = state
  const ready = readiness?.preparable

  return (
    <div style={{ marginTop: 12 }}>
      {bill ? (
        <div style={{ ...bar, borderLeft: '3px solid var(--success)' }}>
          <ShieldCheck size={16} style={{ color: 'var(--success)', flexShrink: 0 }} />
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 13, fontWeight: 600 }}>
              {bill.invoice_id ? 'Invoiced' : 'Ready to invoice'}
            </div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
              {fmtMoney(bill.billable_amount, bill.currency)}
              {bill.prepared_at && <> · marked {fmtDateTime(bill.prepared_at)}</>}
              {bill.basis === 'exception_waiver' && <> · POD waived by exception</>}
            </div>
            {/* Where it goes next. Without this the trip sits at "ready" and
                nobody knows whose move it is. */}
            {!bill.invoice_id && (
              <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4,
                display: 'flex', alignItems: 'center', gap: 4 }}>
                <ArrowRight size={11} /> Accounts raises the invoice from here.
                Transport does not issue invoices.
              </div>
            )}
          </div>
        </div>
      ) : (
        <div style={{
          ...bar,
          borderLeft: `3px solid ${ready ? 'var(--success)' : '#fbbf24'}`,
        }}>
          {ready
            ? <ShieldCheck size={16} style={{ color: 'var(--success)', flexShrink: 0 }} />
            : <AlertTriangle size={16} style={{ color: '#fbbf24', flexShrink: 0 }} />}
          <div>
            <div style={{ fontSize: 13, fontWeight: 600 }}>
              {ready ? 'Ready to invoice' : 'Not ready to invoice'}
            </div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
              {readiness?.reason}
            </div>
            {ready && readiness?.amount && (
              <div style={{ fontSize: 12, marginTop: 3, fontVariantNumeric: 'tabular-nums' }}>
                {fmtMoney(readiness.amount, trip.currency)} — the freight agreed on this trip.
              </div>
            )}
          </div>
        </div>
      )}

      {error && <p style={{ ...muted, color: 'var(--danger)' }}><AlertTriangle size={13} /> {error}</p>}

      {canPrepare && ready && !bill && (
        <button type="button" style={btn} disabled={busy} onClick={prepare}>
          {busy ? <Loader2 size={14} className="spin" /> : <Receipt size={14} />}
          Mark ready to invoice
        </button>
      )}

      {/* STT-010. The second button, and it took two days longer than it should
          have: the route shipped on 19 Sep with nothing here to press it, so a
          trip stopped at Billable and closure was unreachable from the screen.
          Same gap as D-106, one layer up. */}
      {canInvoice && bill && !bill.invoice_id && (
        <form style={invoiceRow} onSubmit={recordInvoice}>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>Invoice number from Accounts</span>
            <input
              required type="number" min="1" style={input} placeholder="e.g. 4242"
              value={invoiceId} onChange={(e) => setInvoiceId(e.target.value)}
            />
          </label>
          <button type="submit" style={{ ...btn, marginTop: 0 }} disabled={busy}>
            {busy ? <Loader2 size={14} className="spin" /> : <Receipt size={14} />}
            Record invoice
          </button>
        </form>
      )}

      {/* Said plainly rather than left as an empty space. Somebody looking at a
          trip stuck on Billable needs to know it is waiting on a person, not on
          the system. */}
      {!canInvoice && bill && !bill.invoice_id && (
        <p style={muted}>
          Waiting for Accounts to raise the invoice and record its number here.
        </p>
      )}
    </div>
  )
}

const muted = { fontSize: 12, color: 'var(--text-muted)', margin: '12px 0 0', display: 'flex', alignItems: 'center', gap: 6 }
const bar = { display: 'flex', gap: 10, alignItems: 'flex-start', padding: '10px 12px', background: 'var(--surface-2)', borderRadius: 8 }
const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', marginTop: 10, fontSize: 13, borderRadius: 7, border: '1px solid var(--border)', background: 'var(--surface)', cursor: 'pointer' }
const invoiceRow = { display: 'flex', gap: 10, alignItems: 'flex-end', marginTop: 12, flexWrap: 'wrap' }
const input = { padding: '6px 8px', fontSize: 13, borderRadius: 6, border: '1px solid var(--border)', background: 'var(--surface)', color: 'inherit', width: 150 }
