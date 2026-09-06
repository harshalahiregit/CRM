/**
 * Advances, admin side — the ladder, the money, and the bills coming back.
 *
 * Whether THIS user can act is not worked out here. The server sends
 * `can.act` and, when it is false, `can.reason` — "this is waiting on the
 * employee's own reporting manager" rather than a flat "not authorised", which
 * is the difference between somebody understanding the queue and asking about
 * it. Re-deriving that rule in the client would eventually derive it differently.
 *
 * The outstanding figure is shown before anything is approved. The old
 * SangoeTrack screen said outright it had "no per-employee ledger, so an
 * employee's existing outstanding balance is not shown before a new advance is
 * granted" — granting a second advance against an unsettled first should be a
 * decision, not an accident.
 */

import { useState, useEffect, useCallback } from 'react'
import { Wallet, Check, X, PauseCircle, Lock, RefreshCw, Banknote, AlertTriangle } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'
import RequestThread from '../components/RequestThread'
import AdvanceLadder from '../components/AdvanceLadder'
import useAttachmentOpener from '../components/useAttachmentOpener'

const inr = n =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 })
    .format(Number(n) || 0)

const MODES = [['upi', 'UPI'], ['bank_transfer', 'Bank transfer'], ['cheque', 'Cheque'], ['cash', 'Cash']]

const TABS = [
  { key: 'open',        label: 'Needs a decision' },
  { key: 'settlements', label: 'Settlements to review' },
  { key: 'approved',    label: 'Ready to disburse' },
  { key: 'disbursed',   label: 'Out, awaiting settlement' },
  { key: 'settled',     label: 'Settled' },
]

/* ── disbursement ────────────────────────────────────────────────────── */

function DisburseForm({ advance, onDone }) {
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [mode, setMode] = useState('bank_transfer')
  const [ref,  setRef]  = useState('')
  const [busy, setBusy] = useState(false)

  // Cash aside, a payment with no reference cannot be matched to a bank
  // statement later. The server enforces this; the button reflects it.
  const needsRef = mode !== 'cash'

  const submit = async () => {
    setBusy(true)
    try {
      await hrApi.advances.disburse(advance.id, mode, ref.trim() || null)
      toast.success(`${inr(advance.amount_approved ?? advance.amount_requested)} recorded as disbursed.`)
      setOpen(false); setRef('')
      onDone?.()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not record the disbursement.')
    } finally {
      setBusy(false)
    }
  }

  if (!open) {
    return (
      <button onClick={() => setOpen(true)}
        className="rounded-lg text-xs font-bold flex items-center gap-1.5"
        style={{ padding: '8px 14px', background: 'rgba(5,150,105,0.14)', color: '#34d399', border: '1px solid rgba(52,211,153,0.35)' }}>
        <Banknote size={13} /> Record disbursement
      </button>
    )
  }

  return (
    <div className="flex flex-col gap-2.5 rounded-xl p-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <p className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>
        Paying {inr(advance.amount_approved ?? advance.amount_requested)} to {advance.employee?.name}
      </p>

      <div className="flex flex-wrap gap-1.5">
        {MODES.map(([value, label]) => (
          <button key={value} onClick={() => setMode(value)} aria-pressed={mode === value}
            className="rounded-lg text-xs font-semibold"
            style={{
              padding: '6px 12px',
              background: mode === value ? 'rgba(124,58,237,0.16)' : 'var(--bg-card)',
              border: `1px solid ${mode === value ? '#7C3AED' : 'var(--border)'}`,
              color: mode === value ? '#a78bfa' : 'var(--text-muted)',
            }}>{label}</button>
        ))}
      </div>

      {needsRef && (
        <input value={ref} onChange={e => setRef(e.target.value)} autoFocus maxLength={100}
          placeholder={mode === 'cheque' ? 'Cheque number' : mode === 'upi' ? 'UPI reference' : 'UTR / transaction reference'}
          className="rounded-lg text-sm"
          style={{ padding: '8px 11px', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-p)' }} />
      )}

      <div className="flex gap-2">
        <button onClick={submit} disabled={busy || (needsRef && !ref.trim())}
          className="rounded-lg text-xs font-bold disabled:opacity-50"
          style={{ padding: '8px 14px', background: '#059669', color: '#fff' }}>
          {busy ? 'Recording…' : 'Confirm disbursement'}
        </button>
        <button onClick={() => setOpen(false)} disabled={busy}
          className="rounded-lg text-xs font-semibold"
          style={{ padding: '8px 14px', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
          Cancel
        </button>
      </div>
    </div>
  )
}

/* ── settlement review ───────────────────────────────────────────────── */

function SettlementQueue({ rows, loading, onDone }) {
  const toast = useToast()
  const [busy, setBusy]     = useState(false)
  const [remarks, setRemarks] = useState({})

  const act = async (s, accept) => {
    const note = (remarks[s.id] || '').trim()
    if (!accept && !note) return toast.error('Sending a settlement back needs a reason.')

    setBusy(true)
    try {
      if (accept) await hrApi.advances.acceptSettlement(s.id, note || null)
      else        await hrApi.advances.rejectSettlement(s.id, note)
      toast.success(accept ? 'Settlement accepted.' : 'Sent back. The employee can submit another.')
      onDone?.()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That did not go through.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <HrLoading />
  if (!rows.length) return <HrEmpty icon={Wallet} title="Nothing to review" hint="No settlements are waiting." />

  return (
    <div className="flex flex-col gap-2">
      {rows.map(s => (
        <div key={s.id} className="rounded-xl flex flex-col gap-2.5"
          style={{ padding: 14, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>
              {s.advance?.employee?.name || 'Unknown'}
            </span>
            <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{s.advance?.reference}</span>
            {/* The server words the case, so the arithmetic is not re-derived —
                or re-derived differently — on this side. */}
            <span className="rounded-md text-[10px] font-bold px-2 py-1"
              style={{
                color: Number(s.extra_due) > 0 ? '#fbbf24' : Number(s.balance_return) > 0 ? '#60a5fa' : '#34d399',
                background: 'var(--bg-input)',
              }}>{s.case_label}</span>
          </div>

          <div className="flex flex-wrap gap-x-5 gap-y-1 text-[11px]" style={{ color: 'var(--text-muted)' }}>
            <span>Advanced <b style={{ color: 'var(--text-h)' }}>{inr(s.advance?.disbursed_amount)}</b></span>
            <span>Spent <b style={{ color: 'var(--text-h)' }}>{inr(s.actual_expense)}</b></span>
            {Number(s.balance_return) > 0 && <span>To return <b style={{ color: '#60a5fa' }}>{inr(s.balance_return)}</b></span>}
            {Number(s.extra_due) > 0 && <span>Company owes <b style={{ color: '#fbbf24' }}>{inr(s.extra_due)}</b></span>}
          </div>

          {s.notes && <p className="text-xs" style={{ color: 'var(--text-p)' }}>{s.notes}</p>}

          {!s.attachments?.length && (
            // Worth saying. Accepting a settlement with no bills is a decision,
            // not something to discover afterwards.
            <p className="text-[11px] font-semibold flex items-center gap-1" style={{ color: '#fbbf24' }}>
              <AlertTriangle size={11} /> No bills attached
            </p>
          )}

          <textarea rows={2} value={remarks[s.id] || ''}
            onChange={e => setRemarks(p => ({ ...p, [s.id]: e.target.value }))}
            placeholder="Remarks — required to send it back"
            className="rounded-lg text-sm w-full"
            style={{ padding: '8px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }} />

          <div className="flex gap-2">
            <button onClick={() => act(s, true)} disabled={busy}
              className="rounded-lg text-xs font-bold"
              style={{ padding: '8px 14px', background: 'rgba(52,211,153,0.15)', color: '#34d399', border: '1px solid rgba(52,211,153,0.35)' }}>
              Accept
            </button>
            <button onClick={() => act(s, false)} disabled={busy}
              className="rounded-lg text-xs font-bold"
              style={{ padding: '8px 14px', background: 'rgba(248,113,113,0.14)', color: '#f87171', border: '1px solid rgba(248,113,113,0.3)' }}>
              Send back
            </button>
          </div>
        </div>
      ))}
    </div>
  )
}

/* ── page ────────────────────────────────────────────────────────────── */

export default function Advances() {
  const toast = useToast()

  const [tab,     setTab]     = useState('open')
  const [rows,    setRows]    = useState([])
  const [setts,   setSetts]   = useState([])
  const [loading, setLoading] = useState(true)
  const [error,   setError]   = useState(null)

  const [openId, setOpenId] = useState(null)
  const [detail, setDetail] = useState(null)
  const [busy,   setBusy]   = useState(false)

  const [action, setAction] = useState(null)
  const [reason, setReason] = useState('')
  const [amount, setAmount] = useState('')

  const fetchFile = useCallback(a => hrApi.advances.file(openId, a), [openId])
  const files = useAttachmentOpener(fetchFile)

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      if (tab === 'settlements') {
        setSetts(await hrApi.advances.settlements())
      } else if (tab === 'open') {
        // 'open' is not a server status: it is everything still climbing.
        const all = await hrApi.advances.list()
        setRows(all.filter(a => ['pending', 'manager_approved', 'accounts_approved', 'on_hold'].includes(a.status)))
      } else {
        setRows(await hrApi.advances.list({ status: tab }))
      }
    } catch (e) {
      setError(e?.response?.data?.message || 'Could not load advances.')
    } finally {
      setLoading(false)
    }
  }, [tab])

  useEffect(() => { load() }, [load])

  const openAdvance = async id => {
    setOpenId(id); setDetail(null); setAction(null); setReason(''); setAmount('')
    try { setDetail(await hrApi.advances.get(id)) }
    catch { toast.error('Could not open that advance.'); setOpenId(null) }
  }

  const refreshDetail = async id => { setDetail(await hrApi.advances.get(id)); load() }

  const submit = async () => {
    const a = detail?.advance
    if (!a) return

    if (action === 'hold'    && !reason.trim()) return toast.error('A hold needs a reason — the employee has to know what to do about it.')
    if (action === 'decline' && !reason.trim()) return toast.error('Declining an advance needs a reason.')
    if (action === 'note'    && !reason.trim()) return toast.error('An empty note helps nobody.')

    const changed = action === 'approve' && amount !== '' &&
      Math.abs(Number(amount) - Number(a.amount_approved ?? a.amount_requested)) > 0.005
    if (changed && !reason.trim()) return toast.error('Changing the amount needs a reason.')

    setBusy(true)
    try {
      if (action === 'approve') await hrApi.advances.approve(a.id, amount === '' ? null : Number(amount), reason.trim() || null)
      if (action === 'decline') await hrApi.advances.decline(a.id, reason.trim())
      if (action === 'hold')    await hrApi.advances.hold(a.id, reason.trim(), amount === '' ? null : Number(amount))
      if (action === 'note')    await hrApi.advances.note(a.id, reason.trim())

      toast.success({
        approve: 'Approved. It moves to the next tier.',
        decline: 'Advance declined.',
        hold:    'Put on hold. The employee has been asked to respond.',
        note:    'Note added — the employee cannot see it.',
      }[action])

      setAction(null); setReason(''); setAmount('')
      await refreshDetail(a.id)
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That did not go through.')
    } finally {
      setBusy(false)
    }
  }

  const a   = detail?.advance
  const can = detail?.can || {}

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-lg font-bold flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <Wallet size={18} /> Advances
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Manager, then accounts, then director — each in turn. Money leaves the company here.
          </p>
        </div>
        <button onClick={load} disabled={loading}
          className="rounded-lg text-xs font-semibold flex items-center gap-1.5"
          style={{ padding: '7px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
          <RefreshCw size={13} className={loading ? 'animate-spin' : ''} /> Refresh
        </button>
      </div>

      <div className="flex gap-1.5 flex-wrap">
        {TABS.map(t => (
          <button key={t.key} onClick={() => setTab(t.key)}
            className="rounded-lg text-xs font-semibold"
            style={{
              padding: '6px 12px',
              background: tab === t.key ? 'var(--accent)' : 'var(--bg-input)',
              color: tab === t.key ? '#fff' : 'var(--text-p)',
              border: '1px solid var(--border)',
            }}>{t.label}</button>
        ))}
      </div>

      {tab === 'settlements' ? (
        <SettlementQueue rows={setts} loading={loading} onDone={load} />
      ) : loading ? <HrLoading /> : error ? (
        <div className="rounded-xl text-xs" style={{ padding: 14, background: 'rgba(248,113,113,0.1)', color: '#f87171' }}>
          {error} <button onClick={load} className="underline font-semibold ml-1">Try again</button>
        </div>
      ) : !rows.length ? (
        <HrEmpty icon={Wallet} title="Nothing here" hint="No advances match this tab." />
      ) : (
        <div className="flex flex-col gap-2">
          {rows.map(r => (
            <button key={r.id} onClick={() => openAdvance(r.id)}
              className="rounded-xl text-left flex flex-col gap-2"
              style={{ padding: '12px 14px', background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <div className="flex items-center gap-3 flex-wrap">
                <div className="flex-1 min-w-[180px]">
                  <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>
                    {r.employee?.name || 'Unknown'}
                    <span className="font-normal ml-1.5 text-[11px]" style={{ color: 'var(--text-muted)' }}>{r.reference}</span>
                  </p>
                  <p className="text-[11px] truncate" style={{ color: 'var(--text-muted)' }}>{r.purpose}</p>
                </div>
                <span className="text-sm font-bold" style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                  {inr(r.amount_approved ?? r.amount_requested)}
                </span>
              </div>
              <div className="flex items-center gap-2 flex-wrap">
                <span className="text-[10px] font-bold uppercase tracking-wider"
                  style={{ color: r.status === 'on_hold' ? '#60a5fa' : 'var(--text-muted)' }}>
                  {r.stage_label}
                </span>
                {/* Only meaningful while it is still climbing. */}
                {r.next_tier && <AdvanceLadder ladder={ladderFor(r)} compact />}
              </div>
            </button>
          ))}
        </div>
      )}

      {openId && (
        <div role="dialog" aria-modal="true" aria-label="Advance"
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          style={{ background: 'rgba(0,0,0,0.6)' }} onClick={() => setOpenId(null)}>
          <div onClick={e => e.stopPropagation()} className="rounded-2xl w-full max-w-2xl flex flex-col"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '88vh' }}>

            {!a ? <div className="p-8"><HrLoading /></div> : (
              <>
                <div className="flex items-start gap-3 p-5" style={{ borderBottom: '1px solid var(--border)' }}>
                  <div className="flex-1">
                    <h2 className="text-base font-bold" style={{ color: 'var(--text-h)' }}>
                      {a.employee?.name} <span className="font-normal text-xs" style={{ color: 'var(--text-muted)' }}>{a.reference}</span>
                    </h2>
                    <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{a.stage_label}</p>
                    <div className="mt-2"><AdvanceLadder ladder={detail.ladder} /></div>
                  </div>
                  <div className="text-right">
                    <p className="text-lg font-bold" style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                      {inr(a.amount_approved ?? a.amount_requested)}
                    </p>
                    {a.amount_approved != null && Number(a.amount_approved) !== Number(a.amount_requested) && (
                      <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>asked {inr(a.amount_requested)}</p>
                    )}
                  </div>
                  <button onClick={() => setOpenId(null)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
                </div>

                <div className="p-5 overflow-y-auto flex flex-col gap-4">
                  {/* The ledger the old screen could not show. */}
                  {detail.outstanding?.open_count > 0 && (
                    <div className="rounded-xl text-[11px] flex items-start gap-1.5"
                      style={{ padding: 10, background: 'rgba(251,191,36,0.09)', border: '1px solid rgba(251,191,36,0.25)', color: '#fbbf24' }}>
                      <AlertTriangle size={12} style={{ marginTop: 1, flexShrink: 0 }} />
                      <span>
                        Already carrying <b>{inr(detail.outstanding.outstanding_amount)}</b> across{' '}
                        {detail.outstanding.open_count} unsettled advance{detail.outstanding.open_count === 1 ? '' : 's'}
                        {detail.outstanding.oldest_disbursed_at && `, oldest from ${detail.outstanding.oldest_disbursed_at}`}.
                      </span>
                    </div>
                  )}

                  <p className="text-xs" style={{ color: 'var(--text-p)' }}>{a.purpose}</p>

                  <div className="grid gap-2 text-[11px]" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(140px,1fr))', color: 'var(--text-muted)' }}>
                    {[['Type', a.advance_type], ['Category', a.category], ['Project / site', a.project_site],
                      ['Needed by', a.required_date], ['Settle by', a.expected_settlement_date],
                      ['Department', a.employee?.department]].map(([k, v]) => v ? (
                        <div key={k}><span className="uppercase tracking-wider font-bold">{k}</span><br />
                          <span style={{ color: 'var(--text-p)' }}>{String(v).slice(0, 40)}</span></div>
                      ) : null)}
                  </div>

                  {/* What happened to the money. The pane described the REQUEST in full
                      and then said nothing about the payment or the bills that came back,
                      which is the half an approver is accountable for. */}
                  {a.disbursed_at && (
                    <div className="rounded-xl text-[11px]" style={{ padding: 10, background: 'rgba(52,211,153,0.08)', border: '1px solid rgba(52,211,153,0.25)', color: '#34d399' }}>
                      {inr(a.disbursed_amount)} paid by {String(a.disbursement_mode || '').replace('_', ' ')}
                      {a.disbursement_reference ? ` · ref ${a.disbursement_reference}` : ''}
                      {` · ${String(a.disbursed_at).slice(0, 10)}`}
                    </div>
                  )}

                  {!!a.settlements?.length && (
                    <div className="flex flex-col gap-1.5">
                      <p className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                        Settlements · every attempt is kept
                      </p>
                      {a.settlements.map(st => (
                        <div key={st.id} className="rounded-lg text-[11px]" style={{ padding: '8px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
                          <span className="font-bold">{inr(st.actual_expense)} spent</span> · {st.case_label}
                          <span className="ml-1" style={{ color: st.status === 'accepted' ? '#34d399' : st.status === 'rejected' ? '#f87171' : '#fbbf24' }}>({st.status})</span>
                          {st.review_remarks ? <span className="block mt-0.5" style={{ color: 'var(--text-muted)' }}>{st.review_remarks}</span> : null}
                        </div>
                      ))}
                    </div>
                  )}

                  {!!a.attachments?.length && (
                    <div className="flex flex-wrap gap-1.5">
                      {a.attachments.map(f => (
                        <button key={f.id} onClick={() => files.open(f)} disabled={files.opening === f.id}
                          className="rounded-lg text-[11px] font-semibold"
                          style={{ padding: '5px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
                          {files.opening === f.id ? 'Opening…' : f.name}
                        </button>
                      ))}
                    </div>
                  )}
                  {files.error && <p className="text-[11px]" style={{ color: '#f87171' }}>{files.error}</p>}

                  <RequestThread entries={detail.thread} onOpenFile={files.open}
                    emptyLabel="Nothing has happened on this advance yet." />
                </div>

                <div className="p-5 flex flex-col gap-3" style={{ borderTop: '1px solid var(--border)' }}>
                  {/* The server says whether this user may act, and why not. */}
                  {!can.act && can.reason && (
                    <p className="text-[11px] flex items-start gap-1.5" style={{ color: 'var(--text-muted)' }}>
                      <AlertTriangle size={12} style={{ marginTop: 1, flexShrink: 0 }} /> {can.reason}
                    </p>
                  )}

                  {can.disburse && <DisburseForm advance={a} onDone={() => refreshDetail(a.id)} />}

                  {!action ? (
                    <div className="flex gap-2 flex-wrap">
                      {can.act && <Act icon={Check}       label="Approve" tone="#34d399" onClick={() => setAction('approve')} />}
                      {can.act && <Act icon={PauseCircle} label="Hold"    tone="#60a5fa" onClick={() => setAction('hold')} />}
                      {can.act && <Act icon={X}           label="Decline" tone="#f87171" onClick={() => setAction('decline')} />}
                      <Act icon={Lock} label="Internal note" tone="#fbbf24" onClick={() => setAction('note')} />
                    </div>
                  ) : (
                    <>
                      {(action === 'approve' || action === 'hold') && (
                        <label className="flex flex-col gap-1">
                          <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                            {action === 'approve' ? 'Approve for' : 'Propose an amount (optional)'}
                          </span>
                          <input type="number" step="0.01" min="0.01" value={amount} onChange={e => setAmount(e.target.value)}
                            placeholder={action === 'approve' ? String(a.amount_approved ?? a.amount_requested) : 'Leave blank to just ask a question'}
                            className="rounded-lg text-sm w-full"
                            style={{ padding: '8px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }} />
                        </label>
                      )}

                      <label className="flex flex-col gap-1">
                        <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                          {action === 'note' ? 'Note for other approvers' : 'Reason'}
                          {action === 'approve' && <span style={{ textTransform: 'none', fontWeight: 400 }}> — required if you change the amount</span>}
                        </span>
                        <textarea rows={3} value={reason} onChange={e => setReason(e.target.value)}
                          className="rounded-lg text-sm w-full"
                          style={{ padding: '8px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }} />
                      </label>

                      <div className="flex gap-2">
                        <button onClick={submit} disabled={busy}
                          className="rounded-lg text-xs font-bold flex-1"
                          style={{ padding: '9px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
                          {busy ? 'Working…' : { approve: 'Approve', decline: 'Decline', hold: 'Put on hold', note: 'Add note' }[action]}
                        </button>
                        <button onClick={() => { setAction(null); setReason(''); setAmount('') }}
                          className="rounded-lg text-xs font-semibold"
                          style={{ padding: '9px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
                          Cancel
                        </button>
                      </div>
                    </>
                  )}
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * The list endpoint sends next_tier but not the whole ladder, which only the
 * detail response carries. Rebuilding it from next_tier keeps the row badge
 * honest without a second request per row.
 */
const LADDER = ['manager', 'accounts', 'director']
function ladderFor(row) {
  const at = LADDER.indexOf(row.next_tier)
  if (at < 0) return []
  return LADDER.map((tier, i) => ({ tier, done: i < at, current: i === at }))
}

function Act({ icon: Icon, label, tone, onClick }) {
  return (
    <button onClick={onClick} className="rounded-lg text-xs font-bold flex items-center gap-1.5"
      style={{ padding: '8px 14px', background: `${tone}1f`, color: tone, border: `1px solid ${tone}40` }}>
      <Icon size={13} /> {label}
    </button>
  )
}
