/**
 * Expense claims, admin side — the native ones.
 *
 * The three actions are the same at every point a claim is open: approve,
 * decline, hold. A claim held twice and answered twice offers exactly what a
 * fresh one does. The only difference is the thread above it, which is why the
 * detail pane leads with the history rather than only the latest hold: whoever
 * picks a claim up needs to see what was already asked, and it may not have
 * been them who asked it.
 *
 * Approving for a different amount requires a reason, and holding requires a
 * reason. Both rules live in the service; they are mirrored in the form so the
 * requirement is visible before the request rather than after it.
 */

import { useState, useEffect, useCallback } from 'react'
import { Receipt, Check, X, PauseCircle, Lock, RefreshCw } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'
import RequestThread from '../components/RequestThread'
import useAttachmentOpener from '../components/useAttachmentOpener'

const inr = n =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 })
    .format(Number(n) || 0)

const STATUS = {
  pending:   { fg: '#fbbf24', bg: 'rgba(251,191,36,0.12)', label: 'Pending' },
  on_hold:   { fg: '#60a5fa', bg: 'rgba(96,165,250,0.12)', label: 'On hold' },
  approved:  { fg: '#34d399', bg: 'rgba(52,211,153,0.12)', label: 'Approved' },
  declined:  { fg: '#f87171', bg: 'rgba(248,113,113,0.12)', label: 'Declined' },
}

const TABS = [
  { key: 'open',     label: 'Needs a decision' },
  { key: 'on_hold',  label: 'On hold' },
  { key: 'approved', label: 'Approved' },
  { key: 'declined', label: 'Declined' },
]

function Pill({ status }) {
  const s = STATUS[status] || { fg: 'var(--text-muted)', bg: 'var(--bg-input)', label: status }
  return (
    <span className="rounded-md text-[10px] font-bold uppercase tracking-wider"
      style={{ padding: '3px 8px', color: s.fg, background: s.bg }}>{s.label}</span>
  )
}

export default function Reimbursements() {
  const toast = useToast()

  const [tab,      setTab]      = useState('open')
  const [rows,     setRows]     = useState([])
  const [loading,  setLoading]  = useState(true)
  const [error,    setError]    = useState(null)

  const [openId,   setOpenId]   = useState(null)
  const [detail,   setDetail]   = useState(null)
  const [busy,     setBusy]     = useState(false)

  // The action form. One object rather than three, because only one action is
  // ever in flight and separate state lets a stale reason survive into the wrong one.
  const [action,   setAction]   = useState(null)   // 'approve' | 'decline' | 'hold' | 'note'
  const [reason,   setReason]   = useState('')
  const [amount,   setAmount]   = useState('')

  const fetchFile = useCallback(
    attachmentId => hrApi.reimbursements.file(openId, attachmentId), [openId])
  const files = useAttachmentOpener(fetchFile)

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      // 'open' is not a server status — it is the two that still need somebody,
      // fetched unfiltered and split here so the tab counts stay consistent.
      const params = ['approved', 'declined', 'on_hold'].includes(tab) ? { status: tab } : {}
      const data = await hrApi.reimbursements.list(params)
      setRows(tab === 'open' ? data.filter(r => r.status === 'pending' || r.status === 'on_hold') : data)
    } catch (e) {
      setError(e?.response?.data?.message || 'Could not load claims.')
    } finally {
      setLoading(false)
    }
  }, [tab])

  useEffect(() => { load() }, [load])

  const openClaim = async id => {
    setOpenId(id); setDetail(null); setAction(null); setReason(''); setAmount('')
    try {
      setDetail(await hrApi.reimbursements.get(id))
    } catch {
      toast.error('Could not open that claim.')
      setOpenId(null)
    }
  }

  const close = () => { setOpenId(null); setDetail(null); setAction(null) }

  const submit = async () => {
    const claim = detail?.claim
    if (!claim) return

    // Mirrors the service. Saying so here means the rule is met before the
    // request rather than explained after it fails.
    if (action === 'hold'    && !reason.trim()) return toast.error('A hold needs a reason — the employee has to know what to do about it.')
    if (action === 'decline' && !reason.trim()) return toast.error('Declining a claim needs a reason.')
    if (action === 'note'    && !reason.trim()) return toast.error('An empty note helps nobody.')

    const changed = action === 'approve' && amount !== '' &&
      Math.abs(Number(amount) - Number(claim.amount_approved ?? claim.amount_claimed)) > 0.005
    if (changed && !reason.trim()) return toast.error('Changing the amount needs a reason.')

    setBusy(true)
    try {
      if (action === 'approve') await hrApi.reimbursements.approve(claim.id, amount === '' ? null : Number(amount), reason.trim() || null)
      if (action === 'decline') await hrApi.reimbursements.decline(claim.id, reason.trim())
      if (action === 'hold')    await hrApi.reimbursements.hold(claim.id, reason.trim(), amount === '' ? null : Number(amount))
      if (action === 'note')    await hrApi.reimbursements.note(claim.id, reason.trim())

      toast.success({
        approve: 'Claim approved.',
        decline: 'Claim declined.',
        hold:    'Put on hold. The employee has been asked to respond.',
        note:    'Note added — the employee cannot see it.',
      }[action])

      setAction(null); setReason(''); setAmount('')
      setDetail(await hrApi.reimbursements.get(claim.id))
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That did not go through.')
    } finally {
      setBusy(false)
    }
  }

  const claim = detail?.claim
  const can   = detail?.can || {}

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-lg font-bold flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <Receipt size={18} /> Reimbursements
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Expense claims from employees, with the conversation on each one.
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

      {loading ? <HrLoading /> : error ? (
        <div className="rounded-xl text-xs" style={{ padding: 14, background: 'rgba(248,113,113,0.1)', color: '#f87171' }}>
          {error} <button onClick={load} className="underline font-semibold ml-1">Try again</button>
        </div>
      ) : !rows.length ? <HrEmpty icon={Receipt} title="No claims here" hint="Nothing matches this tab right now." /> : (
        <div className="flex flex-col gap-2">
          {rows.map(r => (
            <button key={r.id} onClick={() => openClaim(r.id)}
              className="rounded-xl text-left flex items-center gap-3 flex-wrap"
              style={{ padding: '12px 14px', background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <div className="flex-1 min-w-[180px]">
                <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{r.title}</p>
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  {r.employee?.name || 'Unknown'}
                  {r.employee?.employee_code && ` · ${r.employee.employee_code}`}
                  {r.expense_date && ` · spent ${r.expense_date}`}
                </p>
              </div>
              {!!r.attachments_count && (
                <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  {r.attachments_count} file{r.attachments_count === 1 ? '' : 's'}
                </span>
              )}
              <span className="text-sm font-bold" style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                {inr(r.amount_approved ?? r.amount_claimed)}
              </span>
              <Pill status={r.status} />
            </button>
          ))}
        </div>
      )}

      {openId && (
        <div role="dialog" aria-modal="true" aria-label="Claim"
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          style={{ background: 'rgba(0,0,0,0.6)' }} onClick={close}>
          <div onClick={e => e.stopPropagation()}
            className="rounded-2xl w-full max-w-2xl flex flex-col"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '88vh' }}>

            {!claim ? <div className="p-8"><HrLoading /></div> : (
              <>
                <div className="flex items-start gap-3 p-5" style={{ borderBottom: '1px solid var(--border)' }}>
                  <div className="flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <h2 className="text-base font-bold" style={{ color: 'var(--text-h)' }}>{claim.title}</h2>
                      <Pill status={claim.status} />
                    </div>
                    <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                      {claim.employee?.name} · {claim.employee?.department || '—'} · spent {claim.expense_date}
                    </p>
                  </div>
                  <div className="text-right">
                    <p className="text-lg font-bold" style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                      {inr(claim.amount_approved ?? claim.amount_claimed)}
                    </p>
                    {claim.amount_approved != null && Number(claim.amount_approved) !== Number(claim.amount_claimed) && (
                      <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                        claimed {inr(claim.amount_claimed)}
                      </p>
                    )}
                  </div>
                  <button onClick={close} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
                </div>

                <div className="p-5 overflow-y-auto flex flex-col gap-4">
                  {claim.description && (
                    <p className="text-xs" style={{ color: 'var(--text-p)' }}>{claim.description}</p>
                  )}

                  {!!claim.attachments?.length && (
                    <div>
                      <p className="text-[10px] font-bold uppercase tracking-wider mb-1.5" style={{ color: 'var(--text-muted)' }}>
                        Receipts
                      </p>
                      <div className="flex flex-wrap gap-1.5">
                        {claim.attachments.map(a => (
                          <button key={a.id} onClick={() => files.open(a)} disabled={files.opening === a.id}
                            className="rounded-lg text-[11px] font-semibold"
                            style={{ padding: '5px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
                            {files.opening === a.id ? 'Opening…' : a.name}
                          </button>
                        ))}
                      </div>
                      {files.error && <p className="text-[11px] mt-1" style={{ color: '#f87171' }}>{files.error}</p>}
                    </div>
                  )}

                  <div>
                    <p className="text-[10px] font-bold uppercase tracking-wider mb-2" style={{ color: 'var(--text-muted)' }}>
                      History
                    </p>
                    <RequestThread entries={detail.thread} onOpenFile={files.open}
                      emptyLabel="Nothing has happened on this claim yet." />
                  </div>
                </div>

                <div className="p-5 flex flex-col gap-3" style={{ borderTop: '1px solid var(--border)' }}>
                  {!action ? (
                    <div className="flex gap-2 flex-wrap">
                      {/* The same three, always, however the claim got here. */}
                      {can.approve && <Act icon={Check}       label="Approve" tone="#34d399" onClick={() => setAction('approve')} />}
                      {can.hold    && <Act icon={PauseCircle} label="Hold"    tone="#60a5fa" onClick={() => setAction('hold')} />}
                      {can.decline && <Act icon={X}           label="Decline" tone="#f87171" onClick={() => setAction('decline')} />}
                      <Act icon={Lock} label="Internal note" tone="#fbbf24" onClick={() => setAction('note')} />
                      {!can.approve && (
                        <p className="text-[11px] self-center" style={{ color: 'var(--text-muted)' }}>
                          This claim has been decided.
                        </p>
                      )}
                    </div>
                  ) : (
                    <>
                      {(action === 'approve' || action === 'hold') && (
                        <label className="flex flex-col gap-1">
                          <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                            {action === 'approve' ? 'Approve for' : 'Propose an amount (optional)'}
                          </span>
                          <input type="number" step="0.01" min="0.01" value={amount}
                            onChange={e => setAmount(e.target.value)}
                            placeholder={action === 'approve' ? String(claim.amount_claimed) : 'Leave blank to just ask a question'}
                            className="rounded-lg text-sm w-full"
                            style={{ padding: '8px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }} />
                          {action === 'hold' && (
                            <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                              An amount turns this into an offer the employee can accept in one tap.
                            </span>
                          )}
                        </label>
                      )}

                      <label className="flex flex-col gap-1">
                        <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                          {action === 'note' ? 'Note for other approvers' : 'Reason'}
                          {action === 'approve' && <span style={{ textTransform: 'none', fontWeight: 400 }}> — required if you change the amount</span>}
                        </span>
                        <textarea rows={3} value={reason} onChange={e => setReason(e.target.value)}
                          placeholder={action === 'hold' ? 'e.g. The receipt attached is for 2,500, not 5,000.' : ''}
                          className="rounded-lg text-sm w-full"
                          style={{ padding: '8px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }} />
                      </label>

                      <div className="flex gap-2">
                        <button onClick={submit} disabled={busy}
                          className="rounded-lg text-xs font-bold flex-1"
                          style={{ padding: '9px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
                          {busy ? 'Working…' : { approve: 'Approve claim', decline: 'Decline claim', hold: 'Put on hold', note: 'Add note' }[action]}
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

function Act({ icon: Icon, label, tone, onClick }) {
  return (
    <button onClick={onClick} className="rounded-lg text-xs font-bold flex items-center gap-1.5"
      style={{ padding: '8px 14px', background: `${tone}1f`, color: tone, border: `1px solid ${tone}40` }}>
      <Icon size={13} /> {label}
    </button>
  )
}
