/**
 * Asking for a punch to be fixed.
 *
 * The day as it currently stands is fetched as soon as a date is picked and
 * shown beside the boxes. Asking for a correction without seeing the existing
 * times invites requests that change nothing, and makes "what was actually
 * wrong" impossible for an approver to judge.
 *
 * A blank time means "leave this one alone", never "clear it" — the server
 * treats it that way and the form says so, because the opposite reading would
 * quietly wipe a good punch.
 */

import { useState, useEffect, useCallback } from 'react'
import { PenLine, Plus, Send, X, AlertTriangle, Clock } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'
import RequestThread from '../components/RequestThread'

const STATUS = {
  pending:  { fg: '#fbbf24', bg: 'rgba(251,191,36,0.12)',  label: 'Waiting on a decision' },
  on_hold:  { fg: '#60a5fa', bg: 'rgba(96,165,250,0.12)',  label: 'Needs your reply' },
  approved: { fg: '#34d399', bg: 'rgba(52,211,153,0.12)',  label: 'Approved' },
  rejected: { fg: '#f87171', bg: 'rgba(248,113,113,0.12)', label: 'Rejected' },
}

const hhmm = t => (t ? String(t).slice(11, 16) || String(t).slice(0, 5) : '—')
const day  = d => (d ? String(d).slice(0, 10) : '—')

function Pill({ status }) {
  const s = STATUS[status] || { fg: 'var(--text-muted)', bg: 'var(--bg-input)', label: status }
  return (
    <span className="rounded-md text-[10px] font-bold uppercase tracking-wider"
      style={{ padding: '3px 8px', color: s.fg, background: s.bg }}>{s.label}</span>
  )
}

const EMPTY = {
  attendance_date: '', requested_check_in: '', requested_check_out: '',
  requested_break_start: '', requested_break_end: '', reason: '',
}

const inputStyle = {
  padding: '8px 11px', background: 'var(--bg-input)',
  border: '1px solid var(--border)', color: 'var(--text-p)',
}

export default function MyCorrections() {
  const toast = useToast()

  const [rows,    setRows]    = useState([])
  const [loading, setLoading] = useState(true)
  const [error,   setError]   = useState(null)
  const [busy,    setBusy]    = useState(false)

  const [creating, setCreating] = useState(false)
  const [form,     setForm]     = useState(EMPTY)
  const [existing, setExisting] = useState(null)

  const [openId, setOpenId] = useState(null)
  const [detail, setDetail] = useState(null)
  const [reply,  setReply]  = useState('')

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      setRows(await hrApi.corrections.me.list())
    } catch (e) {
      setError(e?.response?.status === 403
        ? (e?.response?.data?.message || 'Your login is not linked to an employee record. Contact HR.')
        : 'Could not load your corrections.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  // Show the day as it stands the moment a date is chosen.
  useEffect(() => {
    if (!form.attendance_date) { setExisting(null); return }

    let cancelled = false
    hrApi.corrections.me.day(form.attendance_date)
      .then(d => { if (!cancelled) setExisting(d?.attendance ?? null) })
      .catch(() => { if (!cancelled) setExisting(null) })

    return () => { cancelled = true }
  }, [form.attendance_date])

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  const submit = async () => {
    if (!form.attendance_date) return toast.error('Which day?')
    if (!form.reason.trim())   return toast.error('Say what went wrong.')

    const anyTime = ['requested_check_in', 'requested_check_out', 'requested_break_start', 'requested_break_end']
      .some(k => form[k])
    if (!anyTime) return toast.error('Give at least one time — a correction with no times asks for nothing.')

    setBusy(true)
    try {
      // Blanks are stripped rather than sent as '': the server reads a missing
      // time as "leave it alone", and an empty string is not that.
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null))
      await hrApi.corrections.me.create(payload)
      toast.success('Correction requested.')
      setCreating(false); setForm(EMPTY); setExisting(null)
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That could not be submitted.')
    } finally {
      setBusy(false)
    }
  }

  const open = async id => {
    setOpenId(id); setDetail(null); setReply('')
    try { setDetail(await hrApi.corrections.me.get(id)) }
    catch { toast.error('Could not open that request.'); setOpenId(null) }
  }

  const send = async () => {
    if (!reply.trim()) return toast.error('Say something before sending.')
    setBusy(true)
    try {
      await hrApi.corrections.me.reply(openId, reply.trim())
      toast.success('Sent.')
      setReply('')
      setDetail(await hrApi.corrections.me.get(openId))
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That could not be sent.')
    } finally {
      setBusy(false)
    }
  }

  const withdraw = async id => {
    setBusy(true)
    try {
      await hrApi.corrections.me.withdraw(id)
      toast.success('Withdrawn.')
      setOpenId(null)
      load()
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That could not be withdrawn.')
    } finally {
      setBusy(false)
    }
  }

  const c   = detail?.correction
  const can = detail?.can || {}

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-lg font-bold flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <PenLine size={18} /> Attendance corrections
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            A punch that was wrong, or one you never made.
          </p>
        </div>
        <button onClick={() => setCreating(v => !v)}
          className="rounded-lg text-xs font-bold flex items-center gap-1.5"
          style={{ padding: '8px 13px', background: 'var(--accent)', color: '#fff' }}>
          <Plus size={14} /> Request a correction
        </button>
      </div>

      {creating && (
        <div className="rounded-2xl flex flex-col gap-3" style={{ padding: 18, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <label className="flex flex-col gap-1" style={{ maxWidth: 220 }}>
            <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Which day</span>
            <input type="date" max={new Date().toISOString().slice(0, 10)} value={form.attendance_date}
              onChange={e => set('attendance_date', e.target.value)}
              className="rounded-lg text-sm w-full" style={inputStyle} />
          </label>

          {/* What the day says right now. */}
          {form.attendance_date && (
            <div className="rounded-xl text-[11px]" style={{ padding: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <span className="font-bold" style={{ color: 'var(--text-muted)' }}>Currently recorded: </span>
              {existing ? (
                <span style={{ color: 'var(--text-p)' }}>
                  in {hhmm(existing.check_in)} · out {hhmm(existing.check_out)}
                  {existing.working_hours != null ? ` · ${existing.working_hours}h` : ''}
                  {existing.status ? ` · ${existing.status}` : ''}
                </span>
              ) : (
                <span style={{ color: '#fbbf24' }}>nothing recorded for this day</span>
              )}
            </div>
          )}

          <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(140px,1fr))' }}>
            {[['requested_check_in', 'Clock in'], ['requested_check_out', 'Clock out'],
              ['requested_break_start', 'Break start'], ['requested_break_end', 'Break end']].map(([k, label]) => (
              <label key={k} className="flex flex-col gap-1">
                <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>{label}</span>
                <input type="time" value={form[k]} onChange={e => set(k, e.target.value)}
                  className="rounded-lg text-sm w-full" style={inputStyle} />
              </label>
            ))}
          </div>

          <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
            Leave a box empty to keep that time exactly as it is. Empty never means "clear it".
          </p>

          <label className="flex flex-col gap-1">
            <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>What went wrong</span>
            <textarea rows={2} value={form.reason} onChange={e => set('reason', e.target.value)}
              placeholder="e.g. Forgot to clock out — left the site at 18:00."
              className="rounded-lg text-sm w-full" style={inputStyle} />
          </label>

          <div className="flex gap-2">
            <button onClick={submit} disabled={busy}
              className="rounded-lg text-xs font-bold flex items-center gap-1.5"
              style={{ padding: '9px 16px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
              <Send size={13} /> {busy ? 'Submitting…' : 'Request correction'}
            </button>
            <button onClick={() => { setCreating(false); setForm(EMPTY); setExisting(null) }}
              className="rounded-lg text-xs font-semibold"
              style={{ padding: '9px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
              Cancel
            </button>
          </div>
        </div>
      )}

      {loading ? <HrLoading /> : error ? (
        <div className="rounded-xl text-xs flex items-start gap-1.5" style={{ padding: 14, background: 'rgba(248,113,113,0.1)', color: '#f87171' }}>
          <AlertTriangle size={13} style={{ marginTop: 1, flexShrink: 0 }} /> {error}
        </div>
      ) : !rows.length ? (
        <HrEmpty icon={PenLine} title="No corrections asked for" hint="If a punch is wrong, ask for it to be fixed above." />
      ) : (
        <div className="flex flex-col gap-2">
          {rows.map(r => (
            <button key={r.id} onClick={() => open(r.id)}
              className="rounded-xl text-left flex items-center gap-3 flex-wrap"
              style={{
                padding: '12px 14px', background: 'var(--bg-card)',
                border: r.status === 'on_hold' ? '1px solid rgba(96,165,250,0.5)' : '1px solid var(--border)',
              }}>
              <div className="flex-1 min-w-[180px]">
                <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{day(r.attendance_date)}</p>
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  {[r.requested_check_in && `in ${String(r.requested_check_in).slice(0, 5)}`,
                    r.requested_check_out && `out ${String(r.requested_check_out).slice(0, 5)}`]
                    .filter(Boolean).join(' · ') || 'break times'}
                  {r.reason ? ` · ${String(r.reason).slice(0, 50)}` : ''}
                </p>
                {r.admin_remarks && (
                  <p className="text-[11px] mt-0.5" style={{ color: r.status === 'rejected' ? '#f87171' : 'var(--text-muted)' }}>
                    {r.admin_remarks}
                  </p>
                )}
              </div>
              {r.applied && (
                <span className="text-[10px] font-bold flex items-center gap-1" style={{ color: '#34d399' }}>
                  <Clock size={10} /> Applied
                </span>
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
                    <h2 className="text-base font-bold" style={{ color: 'var(--text-h)' }}>{day(c.attendance_date)}</h2>
                    <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{c.reason}</p>
                  </div>
                  <Pill status={c.status} />
                  <button onClick={() => setOpenId(null)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
                </div>

                <div className="p-5 overflow-y-auto flex flex-col gap-4">
                  <div className="grid gap-2 text-[11px]" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', color: 'var(--text-muted)' }}>
                    {[['Clock in', c.requested_check_in], ['Clock out', c.requested_check_out],
                      ['Break start', c.requested_break_start], ['Break end', c.requested_break_end]]
                      .map(([k, v]) => v ? (
                        <div key={k}><span className="uppercase tracking-wider font-bold">{k}</span><br />
                          <span style={{ color: 'var(--text-p)' }}>{String(v).slice(0, 5)}</span></div>
                      ) : null)}
                  </div>

                  {c.admin_remarks && (
                    <p className="text-xs rounded-lg" style={{ padding: '8px 10px', background: 'var(--bg-input)', color: 'var(--text-p)' }}>
                      {c.admin_remarks}
                    </p>
                  )}

                  <RequestThread entries={detail.thread} emptyLabel="Nothing has happened on this request yet." />
                </div>

                <div className="p-5 flex flex-col gap-3" style={{ borderTop: '1px solid var(--border)' }}>
                  {can.reply ? (
                    <>
                      <textarea rows={2} value={reply} onChange={e => setReply(e.target.value)}
                        placeholder="Reply to the approver…" className="w-full rounded-lg text-sm" style={inputStyle} />
                      <div className="flex gap-2">
                        <button onClick={send} disabled={busy}
                          className="rounded-lg text-xs font-bold flex items-center gap-1.5"
                          style={{ padding: '9px 16px', background: 'var(--accent)', color: '#fff', opacity: busy ? 0.6 : 1 }}>
                          <Send size={13} /> Send
                        </button>
                        {can.withdraw && (
                          <button onClick={() => withdraw(c.id)} disabled={busy}
                            className="rounded-lg text-xs font-semibold"
                            style={{ padding: '9px 14px', background: 'rgba(248,113,113,0.1)', color: '#f87171', border: '1px solid rgba(248,113,113,0.3)' }}>
                            Withdraw
                          </button>
                        )}
                      </div>
                    </>
                  ) : (
                    <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                      This request has been decided, so it can no longer be replied to.
                    </p>
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
