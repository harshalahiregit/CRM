/**
 * Attendance corrections, approver side.
 *
 * The current times sit beside the requested ones. Approving a correction
 * without seeing what the day already says is approving blind — and it is the
 * whole judgement: whether the change is plausible, and whether it changes
 * anything at all.
 *
 * Approving WRITES the day and recomputes the hours. The row records that the
 * write happened, so an approval that never reached the timesheet is visible
 * rather than something to discover at payroll.
 */

import { useState, useEffect, useCallback } from 'react'
import { PenLine, Check, X, PauseCircle, Lock, RefreshCw, ArrowRight } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'
import RequestThread from '../components/RequestThread'

const STATUS = {
  pending:  { fg: '#fbbf24', bg: 'rgba(251,191,36,0.12)',  label: 'Pending' },
  on_hold:  { fg: '#60a5fa', bg: 'rgba(96,165,250,0.12)',  label: 'On hold' },
  approved: { fg: '#34d399', bg: 'rgba(52,211,153,0.12)',  label: 'Approved' },
  rejected: { fg: '#f87171', bg: 'rgba(248,113,113,0.12)', label: 'Rejected' },
}

const TABS = [
  { key: 'open',     label: 'Needs a decision' },
  { key: 'approved', label: 'Approved' },
  { key: 'rejected', label: 'Rejected' },
]

const hhmm = t => (t ? (String(t).length > 8 ? String(t).slice(11, 16) : String(t).slice(0, 5)) : '—')
const day  = d => (d ? String(d).slice(0, 10) : '—')

function Pill({ status }) {
  const s = STATUS[status] || { fg: 'var(--text-muted)', bg: 'var(--bg-input)', label: status }
  return (
    <span className="rounded-md text-[10px] font-bold uppercase tracking-wider"
      style={{ padding: '3px 8px', color: s.fg, background: s.bg }}>{s.label}</span>
  )
}

/** Now → asked for, side by side. The comparison IS the decision. */
function Change({ label, from, to }) {
  if (!to) return null
  const same = hhmm(from) === hhmm(to)
  return (
    <div className="flex items-center gap-1.5 text-[11px]">
      <span className="uppercase tracking-wider font-bold" style={{ color: 'var(--text-muted)', minWidth: 74 }}>{label}</span>
      <span style={{ color: 'var(--text-muted)' }}>{hhmm(from)}</span>
      <ArrowRight size={10} style={{ color: 'var(--text-muted)' }} />
      <span className="font-bold" style={{ color: same ? 'var(--text-muted)' : '#fbbf24' }}>{hhmm(to)}</span>
      {same && <span style={{ color: 'var(--text-muted)' }}>(no change)</span>}
    </div>
  )
}

export default function Corrections() {
  const toast = useToast()

  const [tab,     setTab]     = useState('open')
  const [rows,    setRows]    = useState([])
  const [loading, setLoading] = useState(true)
  const [error,   setError]   = useState(null)

  const [openId, setOpenId] = useState(null)
  const [detail, setDetail] = useState(null)
  const [busy,   setBusy]   = useState(false)
  const [action, setAction] = useState(null)
  const [text,   setText]   = useState('')

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      if (tab === 'open') {
        const all = await hrApi.corrections.list()
        setRows(all.filter(r => r.status === 'pending' || r.status === 'on_hold'))
      } else {
        setRows(await hrApi.corrections.list({ status: tab }))
      }
    } catch (e) {
      setError(e?.response?.data?.message || 'Could not load corrections.')
    } finally {
      setLoading(false)
    }
  }, [tab])

  useEffect(() => { load() }, [load])

  const open = async id => {
    setOpenId(id); setDetail(null); setAction(null); setText('')
    try { setDetail(await hrApi.corrections.get(id)) }
    catch { toast.error('Could not open that request.'); setOpenId(null) }
  }

  const submit = async () => {
    const c = detail?.correction
    if (!c) return

    if (action === 'reject' && !text.trim()) return toast.error('Rejecting a correction needs a reason.')
    if (action === 'hold'   && !text.trim()) return toast.error('A hold needs a reason — the employee has to know what to do about it.')
    if (action === 'note'   && !text.trim()) return toast.error('An empty note helps nobody.')

    setBusy(true)
    try {
      if (action === 'approve') await hrApi.corrections.approve(c.id, text.trim() || null)
      if (action === 'reject')  await hrApi.corrections.reject(c.id, text.trim())
      if (action === 'hold')    await hrApi.corrections.hold(c.id, text.trim())
      if (action === 'note')    await hrApi.corrections.note(c.id, text.trim())

      toast.success({
        approve: 'Approved — the day has been updated.',
        reject:  'Correction rejected.',
        hold:    'Put on hold. The employee has been asked to respond.',
        note:    'Note added — the employee cannot see it.',
      }[action])

      setAction(null); setText('')
      setDetail(await hrApi.corrections.get(c.id))
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That did not go through.')
    } finally {
      setBusy(false)
    }
  }

  const c   = detail?.correction
  const can = detail?.can || {}
  const now = c?.attendance

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-lg font-bold flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <PenLine size={18} /> Attendance corrections
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Approving writes the day and recomputes the hours.
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
      ) : !rows.length ? (
        <HrEmpty icon={PenLine} title="Nothing here" hint="No corrections match this tab." />
      ) : (
        <div className="flex flex-col gap-2">
          {rows.map(r => (
            <button key={r.id} onClick={() => open(r.id)}
              className="rounded-xl text-left flex items-center gap-3 flex-wrap"
              style={{ padding: '12px 14px', background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <div className="flex-1 min-w-[180px]">
                <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>
                  {r.employee?.name || 'Unknown'}
                  <span className="font-normal text-[11px] ml-1.5" style={{ color: 'var(--text-muted)' }}>
                    {day(r.attendance_date)}
                  </span>
                </p>
                <p className="text-[11px] truncate" style={{ color: 'var(--text-muted)' }}>{r.reason}</p>
              </div>
              {!r.attendance && (
                // Worth flagging: approving will create the day, not edit one.
                <span className="text-[10px] font-bold" style={{ color: '#fbbf24' }}>No record for that day</span>
              )}
              <Pill status={r.status} />
            </button>
          ))}
        </div>
      )}

      {openId && (
        <div role="dialog" aria-modal="true" aria-label="Correction"
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          style={{ background: 'rgba(0,0,0,0.6)' }} onClick={() => setOpenId(null)}>
          <div onClick={e => e.stopPropagation()} className="rounded-2xl w-full max-w-xl flex flex-col"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '88vh' }}>

            {!c ? <div className="p-8"><HrLoading /></div> : (
              <>
                <div className="flex items-start gap-3 p-5" style={{ borderBottom: '1px solid var(--border)' }}>
                  <div className="flex-1">
                    <h2 className="text-base font-bold" style={{ color: 'var(--text-h)' }}>
                      {c.employee?.name}
                      <span className="font-normal text-xs ml-1.5" style={{ color: 'var(--text-muted)' }}>
                        {day(c.attendance_date)}
                      </span>
                    </h2>
                    <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                      {c.employee?.employee_code} · {c.employee?.department || '—'}
                    </p>
                  </div>
                  <Pill status={c.status} />
                  <button onClick={() => setOpenId(null)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
                </div>

                <div className="p-5 overflow-y-auto flex flex-col gap-4">
                  <p className="text-xs" style={{ color: 'var(--text-p)' }}>{c.reason}</p>

                  {/* Now → asked for. This comparison is the decision. */}
                  <div className="rounded-xl flex flex-col gap-1.5" style={{ padding: 11, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    {!now && (
                      <p className="text-[11px] mb-1" style={{ color: '#fbbf24' }}>
                        No attendance recorded for this day — approving will create it.
                      </p>
                    )}
                    <Change label="Clock in"    from={now?.check_in}    to={c.requested_check_in} />
                    <Change label="Clock out"   from={now?.check_out}   to={c.requested_check_out} />
                    <Change label="Break start" from={now?.break_start} to={c.requested_break_start} />
                    <Change label="Break end"   from={now?.break_end}   to={c.requested_break_end} />
                    {now?.working_hours != null && (
                      <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
                        Currently {now.working_hours}h · {now.status}
                      </p>
                    )}
                  </div>

                  {c.applied && (
                    <p className="text-[11px] rounded-lg" style={{ padding: '7px 9px', background: 'rgba(52,211,153,0.09)', color: '#34d399' }}>
                      Applied to the timesheet.
                    </p>
                  )}

                  <RequestThread entries={detail.thread} emptyLabel="Nothing has happened on this request yet." />
                </div>

                <div className="p-5 flex flex-col gap-3" style={{ borderTop: '1px solid var(--border)' }}>
                  {!action ? (
                    <div className="flex gap-2 flex-wrap">
                      {can.approve && <Act icon={Check}       label="Approve" tone="#34d399" onClick={() => setAction('approve')} />}
                      {can.hold    && <Act icon={PauseCircle} label="Hold"    tone="#60a5fa" onClick={() => setAction('hold')} />}
                      {can.reject  && <Act icon={X}           label="Reject"  tone="#f87171" onClick={() => setAction('reject')} />}
                      <Act icon={Lock} label="Internal note" tone="#fbbf24" onClick={() => setAction('note')} />
                      {!can.approve && (
                        <p className="text-[11px] self-center" style={{ color: 'var(--text-muted)' }}>
                          This request has been decided.
                        </p>
                      )}
                    </div>
                  ) : (
                    <>
                      <label className="flex flex-col gap-1">
                        <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                          {action === 'note' ? 'Note for other approvers'
                            : action === 'approve' ? 'Remarks (optional)' : 'Reason'}
                        </span>
                        <textarea rows={3} value={text} onChange={e => setText(e.target.value)}
                          className="rounded-lg text-sm w-full"
                          style={{ padding: '8px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }} />
                      </label>
                      <div className="flex gap-2">
                        <button onClick={submit} disabled={busy}
                          className="rounded-lg text-xs font-bold flex-1"
                          style={{ padding: '9px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
                          {busy ? 'Working…' : { approve: 'Approve and update the day', reject: 'Reject', hold: 'Put on hold', note: 'Add note' }[action]}
                        </button>
                        <button onClick={() => { setAction(null); setText('') }}
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
